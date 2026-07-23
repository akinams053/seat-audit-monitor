<?php

// src/Repositories/CursorRepository.php
// 统一管理 scanner + 审计军团 scope 的游标创建、锁定和推进，供后续各来源扫描器复用

namespace Seat\SeatAuditMonitor\Repositories;

use Carbon\CarbonInterface;
use InvalidArgumentException;
use Seat\SeatAuditMonitor\Models\AuditCursor;

final class CursorRepository
{
    public const GLOBAL_SCOPE = 0;

    /**
     * 获取游标；不存在时以零 ID 和空时间安全初始化。
     */
    public function getOrCreate(string $scanner, int $auditCorporationId = self::GLOBAL_SCOPE): AuditCursor
    {
        $scanner = $this->normalizeScanner($scanner);
        $auditCorporationId = $this->normalizeScope($auditCorporationId);

        return AuditCursor::firstOrCreate(
            [
                'scanner'              => $scanner,
                'audit_corporation_id' => $auditCorporationId,
            ],
            [
                'cursor_at'     => null,
                'cursor_id'     => 0,
                'cursor_sub_id' => 0,
            ]
        );
    }

    /**
     * 在 chunk 事务内读取并锁定游标，防止同一来源和军团 scope 被并发推进。
     */
    public function lockForUpdate(string $scanner, int $auditCorporationId = self::GLOBAL_SCOPE): AuditCursor
    {
        $cursor = $this->getOrCreate($scanner, $auditCorporationId);

        return AuditCursor::query()
            ->whereKey($cursor->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * 记录扫描开始时间；全局 Cache::lock 将在后续 orchestrator 层提供更高层并发保护。
     */
    public function markStarted(string $scanner, int $auditCorporationId = self::GLOBAL_SCOPE): AuditCursor
    {
        $cursor = $this->getOrCreate($scanner, $auditCorporationId);
        $cursor->forceFill(['last_started_at' => now()])->save();

        return $cursor->refresh();
    }

    /**
     * 推进稳定复合游标。调用方必须在当前 chunk 违规写入成功后、同一事务提交前调用。
     */
    public function advance(
        AuditCursor $cursor,
        ?CarbonInterface $cursorAt,
        int $cursorId,
        int $cursorSubId = 0
    ): AuditCursor {
        if ($cursorId < 0 || $cursorSubId < 0) {
            throw new InvalidArgumentException('cursor_id 和 cursor_sub_id 不能小于 0。');
        }

        $cursor->forceFill([
            'cursor_at'     => $cursorAt,
            'cursor_id'     => $cursorId,
            'cursor_sub_id' => $cursorSubId,
        ])->save();

        return $cursor;
    }

    /**
     * 仅在整个 scanner 正常结束后记录成功时间；失败任务保留 last_started_at 供运维识别。
     */
    public function markSucceeded(AuditCursor $cursor): AuditCursor
    {
        $cursor->forceFill(['last_succeeded_at' => now()])->save();

        return $cursor;
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
