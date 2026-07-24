<?php

// src/Jobs/AuditDonationsJob.php
// 扫描固定军团 98588384 成员与外部方之间的 ISK 捐赠，并以原子 cursor 保证增量处理

namespace Seat\SeatAuditMonitor\Jobs;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Seat\SeatAuditMonitor\Enums\AuditType;
use Seat\SeatAuditMonitor\Models\AuditCorporation;
use Seat\SeatAuditMonitor\Models\AuditCursor;
use Seat\SeatAuditMonitor\Repositories\CursorRepository;
use Seat\SeatAuditMonitor\Services\Audit\DonationCursorPosition;
use Seat\SeatAuditMonitor\Services\Audit\DonationJournalBatch;
use Seat\SeatAuditMonitor\Services\Audit\DonationJournalScanner;
use Seat\SeatAuditMonitor\Services\Audit\ViolationWriter;

class AuditDonationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * 当前成员审查仅针对一个明确的 EVE 军团；该值不能替换为 seat_audit_corporations 的自增主键。
     */
    private const AUDIT_CORPORATION_ID = AuditCorporation::TARGET_CORPORATION_ID;

    public function handle(
        DonationJournalScanner $donationJournalScanner,
        CursorRepository $cursorRepository,
        ViolationWriter $violationWriter,
    ): void {
        $auditCorporation = AuditCorporation::query()
            ->where('corporation_id', self::AUDIT_CORPORATION_ID)
            ->first();

        // 启用与 audit_from 都由部署时人工确认；未满足前安全 no-op，绝不创建 cursor 或写入违规。
        if ($auditCorporation === null
            || ! $auditCorporation->enabled
            || ! $auditCorporation->audit_donations
            || $auditCorporation->audit_from === null) {
            Log::warning('[seat-audit:donations] 跳过扫描：98588384 未配置、未启用、未开启捐赠审计或缺少 audit_from。');

            return;
        }

        $auditFrom = CarbonImmutable::parse($auditCorporation->audit_from);
        $memberIds = $this->idSet(
            DB::table('corporation_members')
                ->where('corporation_id', self::AUDIT_CORPORATION_ID)
                ->pluck('character_id')
                ->all()
        );

        // 空名册更可能表示 SeAT 成员同步失败。此时推进 cursor 会永久跳过真实成员事件，必须停止。
        if ($memberIds === []) {
            Log::warning('[seat-audit:donations] 跳过扫描：98588384 当前成员名册为空，未推进 cursor。');

            return;
        }

        $characterWhitelistIds = $this->idSet(
            DB::table('seat_audit_whitelist')->pluck('character_id')->all()
        );
        $corporationWhitelistIds = $this->idSet(
            DB::table('seat_audit_corporation_whitelist')->pluck('corporation_id')->all()
        );
        $started = false;

        while (true) {
            /** @var DonationJournalBatch|null $batch */
            $batch = DB::transaction(function () use (
                $donationJournalScanner,
                $cursorRepository,
                $violationWriter,
                $memberIds,
                $auditFrom,
                $characterWhitelistIds,
                $corporationWhitelistIds,
                &$started,
            ): ?DonationJournalBatch {
                $cursor = $cursorRepository->lockOrCreateForUpdate(
                    AuditType::IskDonations->value,
                    self::AUDIT_CORPORATION_ID,
                );

                if (! $started) {
                    $cursorRepository->markStarted($cursor);
                    $started = true;
                }

                $batch = $donationJournalScanner->nextBatch(
                    memberIds: $memberIds,
                    auditFrom: $auditFrom,
                    characterWhitelistIds: $characterWhitelistIds,
                    corporationWhitelistIds: $corporationWhitelistIds,
                    afterPosition: $this->donationCursorPosition($cursor),
                );

                if ($batch === null) {
                    $cursorRepository->markSucceeded($cursor);

                    return null;
                }

                // insertOrIgnore() 与 advance() 必须处在同一事务：无候选/无效来源也会推进，
                // 任一异常则两项操作同时回滚，下次重试仍从同一安全位置开始。
                $violationWriter->insertOrIgnore($batch->violations);
                $cursorRepository->advance(
                    $cursor,
                    CarbonImmutable::parse($batch->lastSourcePosition->occurredAt),
                    $batch->lastSourcePosition->characterId,
                    $this->positiveInteger($batch->lastSourcePosition->canonicalDonationId, 'canonical donation ID'),
                );

                return $batch;
            });

            if ($batch === null) {
                break;
            }

            if ($batch->invalidReasons !== []) {
                Log::warning('[seat-audit:donations] 已跳过无法安全规范的捐赠来源。', [
                    'source_rows' => $batch->sourceRows,
                    'invalid'     => $batch->invalidReasons,
                ]);
            }
        }
    }

    /**
     * 将已锁定 cursor 还原为 donation scanner 的复合位置；半缺失 cursor 是数据损坏，不能降级为首扫。
     */
    private function donationCursorPosition(AuditCursor $cursor): ?DonationCursorPosition
    {
        if ($cursor->cursor_at === null) {
            return null;
        }

        if ((int) $cursor->cursor_id <= 0 || (int) $cursor->cursor_sub_id <= 0) {
            throw new RuntimeException('ISK donation cursor 数据不完整，已拒绝扫描以避免重复或漏审。');
        }

        return new DonationCursorPosition(
            occurredAt: CarbonImmutable::parse($cursor->cursor_at)->toDateTimeString(),
            characterId: (int) $cursor->cursor_id,
            canonicalDonationId: (string) $cursor->cursor_sub_id,
        );
    }

    /**
     * 将数据库 ID 列表转为严格正整数集合，便于成员 XOR 和白名单 O(1) 查询。
     *
     * @param array<int, int|string|null> $ids
     * @return array<int, true>
     */
    private function idSet(array $ids): array
    {
        $idSet = [];

        foreach ($ids as $id) {
            $normalized = trim((string) ($id ?? ''));
            if (preg_match('/^[1-9][0-9]*$/', $normalized) === 1) {
                $idSet[(int) $normalized] = true;
            }
        }

        return $idSet;
    }

    private function positiveInteger(string $value, string $field): int
    {
        if (preg_match('/^[1-9][0-9]*$/', $value) !== 1) {
            throw new RuntimeException($field . ' 不是正整数。');
        }

        $integer = (int) $value;
        if ($integer <= 0 || (string) $integer !== $value) {
            throw new RuntimeException($field . ' 超出当前 PHP 整数范围。');
        }

        return $integer;
    }
}
