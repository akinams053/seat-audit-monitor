<?php

// src/Repositories/CursorRepository.php
// 管理 scanner + 固定审计军团 scope 的 cursor 创建、锁定和单调推进

namespace Seat\SeatAuditMonitor\Repositories;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Seat\SeatAuditMonitor\Models\AuditCursor;

final class CursorRepository
{
    public const GLOBAL_SCOPE = 0;

    /**
     * 纯读 cursor；不存在时返回 null，绝不创建记录。
     *
     * 仅在未来需要展示状态时使用。正式扫描必须调用 lockOrCreateForUpdate()，确保首次
     * 创建、待写 violation 和 cursor 推进处于同一个事务边界内。
     */
    public function readExisting(string $scanner, int $auditCorporationId = self::GLOBAL_SCOPE): ?AuditCursor
    {
        return AuditCursor::query()
            ->where('scanner', $this->normalizeScanner($scanner))
            ->where('audit_corporation_id', $this->normalizeScope($auditCorporationId))
            ->first();
    }

    /**
     * 在调用方已开启的事务内安全创建并锁定 cursor。
     *
     * 不能使用 firstOrCreate() 后再 lock：两个首次扫描可能同时判断不存在，再因唯一索引
     * 冲突中断其中一方。insertOrIgnore() 先让数据库处理竞争，随后 locking read 获取唯一行；
     * 若另一事务刚创建该行，锁定读取会等待其提交并拿到一致的最新 cursor。
     */
    public function lockOrCreateForUpdate(
        string $scanner,
        int $auditCorporationId = self::GLOBAL_SCOPE,
    ): AuditCursor {
        $scanner = $this->normalizeScanner($scanner);
        $auditCorporationId = $this->normalizeScope($auditCorporationId);
        $now = now()->toDateTimeString();

        DB::table('seat_audit_scan_cursors')->insertOrIgnore([
            'scanner'              => $scanner,
            'audit_corporation_id' => $auditCorporationId,
            'cursor_at'            => null,
            'cursor_id'            => 0,
            'cursor_sub_id'        => 0,
            'last_started_at'      => null,
            'last_succeeded_at'    => null,
            'created_at'           => $now,
            'updated_at'           => $now,
        ]);

        return AuditCursor::query()
            ->where('scanner', $scanner)
            ->where('audit_corporation_id', $auditCorporationId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * 推进稳定复合 cursor。
     *
     * 调用方必须先通过 lockOrCreateForUpdate() 获取同一事务内的锁定模型。时间、主 ID、
     * 次级 ID 均不可倒退：donation 使用三段位置，member contracts 的 cursor_sub_id 固定为 0。
     */
    public function advance(
        AuditCursor $cursor,
        CarbonInterface $cursorAt,
        int $cursorId,
        int $cursorSubId = 0,
    ): AuditCursor {
        if ($cursorId < 0 || $cursorSubId < 0) {
            throw new InvalidArgumentException('cursor_id 和 cursor_sub_id 不能小于 0。');
        }
        if ($this->isBeforeCurrent($cursor, $cursorAt, $cursorId, $cursorSubId)) {
            throw new InvalidArgumentException('cursor 不能倒退。');
        }

        $cursor->forceFill([
            'cursor_at'     => $cursorAt,
            'cursor_id'     => $cursorId,
            'cursor_sub_id' => $cursorSubId,
        ])->save();

        return $cursor;
    }

    /**
     * 仅当来源位置严格晚于当前 cursor 时推进。
     *
     * member-contract 的十分钟 overlap 会刻意重读少量旧来源；这些记录仍可依靠
     * source_event_key 幂等写入，但不能把正式 cursor 倒退到 overlap 位置。
     */
    public function advanceIfAhead(
        AuditCursor $cursor,
        CarbonInterface $cursorAt,
        int $cursorId,
        int $cursorSubId = 0,
    ): bool {
        if ($cursor->cursor_at !== null) {
            if ($this->isBeforeCurrent($cursor, $cursorAt, $cursorId, $cursorSubId)
                || $this->isSameAsCurrent($cursor, $cursorAt, $cursorId, $cursorSubId)) {
                return false;
            }
        }

        $this->advance($cursor, $cursorAt, $cursorId, $cursorSubId);

        return true;
    }

    /**
     * 仅在整个 scanner 全部来源批次成功后记录完成时间。
     */
    public function markSucceeded(AuditCursor $cursor): AuditCursor
    {
        $cursor->forceFill(['last_succeeded_at' => now()])->save();

        return $cursor;
    }

    /**
     * 每次 Job 开始时更新运行时间；这不代表 cursor 已推进或扫描已成功。
     */
    public function markStarted(AuditCursor $cursor): AuditCursor
    {
        $cursor->forceFill(['last_started_at' => now()])->save();

        return $cursor;
    }

    /**
     * 比较新旧 cursor 三元组，只有新位置严格早于旧位置时拒绝。
     * 同一位置允许重复保存，便于幂等重试或没有新来源时保持现有状态。
     */
    private function isBeforeCurrent(
        AuditCursor $cursor,
        CarbonInterface $cursorAt,
        int $cursorId,
        int $cursorSubId,
    ): bool {
        if ($cursor->cursor_at === null) {
            return false;
        }

        $currentAt = CarbonImmutable::parse($cursor->cursor_at);
        $newAt = CarbonImmutable::parse($cursorAt);
        if ($newAt->lt($currentAt)) {
            return true;
        }
        if ($newAt->gt($currentAt)) {
            return false;
        }

        $currentId = (int) $cursor->cursor_id;
        if ($cursorId < $currentId) {
            return true;
        }
        if ($cursorId > $currentId) {
            return false;
        }

        return $cursorSubId < (int) $cursor->cursor_sub_id;
    }

    private function isSameAsCurrent(
        AuditCursor $cursor,
        CarbonInterface $cursorAt,
        int $cursorId,
        int $cursorSubId,
    ): bool {
        if ($cursor->cursor_at === null) {
            return false;
        }

        return CarbonImmutable::parse($cursor->cursor_at)->equalTo(CarbonImmutable::parse($cursorAt))
            && $cursorId === (int) $cursor->cursor_id
            && $cursorSubId === (int) $cursor->cursor_sub_id;
    }

    private function normalizeScanner(string $scanner): string
    {
        $scanner = trim($scanner);

        if ($scanner === '' || mb_strlen($scanner) > 64) {
            throw new InvalidArgumentException('scanner 必须是 1 到 64 个字符。');
        }

        return $scanner;
    }

    private function normalizeScope(int $auditCorporationId): int
    {
        if ($auditCorporationId < 0) {
            throw new InvalidArgumentException('audit_corporation_id 不能小于 0。');
        }

        return $auditCorporationId;
    }
}
