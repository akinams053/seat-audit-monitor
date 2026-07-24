<?php

// src/Services/Audit/DonationJournalScanner.php
// 为固定受审军团读取 player_donation journal、归并镜像并构造待写入的 ISK 捐赠违规候选

namespace Seat\SeatAuditMonitor\Services\Audit;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Seat\SeatAuditMonitor\Enums\AuditType;
use Seat\SeatAuditMonitor\Models\AuditCorporation;

final class DonationJournalScanner
{
    /**
     * 每批以 canonical donation event 计数，而非原始 journal 行计数。
     * 一个 event 最多包含 donor/recipient 两条镜像行，按 event 分批可保证实体快照一次批量读取。
     */
    public const CHUNK_SIZE = 500;

    public function __construct(
        private readonly DonationEventNormalizer $eventNormalizer,
        private readonly EntitySnapshotResolver $entitySnapshotResolver,
        private readonly SourceEventKeyFactory $sourceEventKeyFactory,
    ) {
    }

    /**
     * 读取紧接现有 cursor 之后的一批捐赠来源，并生成固定军团的违规候选。
     *
     * 本服务只完成来源读取与规则判定，绝不写 violation 或推进 cursor。调用方必须在同一
     * DB transaction 内使用返回的 lastSourcePosition 写入并推进进度，确保异常不会留下
     * “已写未推进”或“已推进未写”的半完成状态。
     *
     * @param array<int, true> $memberIds 扫描时 corporation_members 的当前成员集合
     * @param array<int, true> $characterWhitelistIds
     * @param array<int, true> $corporationWhitelistIds
     */
    public function nextBatch(
        array $memberIds,
        CarbonInterface $auditFrom,
        array $characterWhitelistIds,
        array $corporationWhitelistIds,
        ?DonationCursorPosition $afterPosition,
        int $chunkSize = self::CHUNK_SIZE,
    ): ?DonationJournalBatch {
        if ($chunkSize <= 0) {
            throw new InvalidArgumentException('Donation scanner 的 chunkSize 必须大于 0。');
        }

        $canonicalGroupsQuery = $this->canonicalGroupsQuery($auditFrom);
        if ($afterPosition !== null) {
            $this->applyAfterPosition($canonicalGroupsQuery, $afterPosition);
        }

        $canonicalGroups = $canonicalGroupsQuery->limit($chunkSize)->get();
        if ($canonicalGroups->isEmpty()) {
            return null;
        }

        $canonicalDonationIds = $canonicalGroups
            ->pluck('canonical_donation_id')
            ->map(static fn ($donationId): string => (string) $donationId)
            ->all();

        // Hydrate 时不能再次按 date 过滤：若镜像两侧 date 异常不一致，必须把完整分组交给
        // normalizer 判为 invalid，而不能仅读取较新的一侧后错误地视为合法单边捐赠。
        $journalRows = $this->loadJournalRows($canonicalDonationIds);
        $lastGroup = $canonicalGroups->last();
        $lastSourcePosition = new DonationCursorPosition(
            occurredAt: (string) $lastGroup->occurred_at,
            characterId: (int) $lastGroup->cursor_character_id,
            canonicalDonationId: (string) $lastGroup->canonical_donation_id,
        );

        return $this->buildBatch(
            journalRows: $journalRows,
            memberIds: $memberIds,
            auditFrom: $auditFrom,
            characterWhitelistIds: $characterWhitelistIds,
            corporationWhitelistIds: $corporationWhitelistIds,
            lastSourcePosition: $lastSourcePosition,
        );
    }

    /**
     * 返回 canonical donation 的稳定排序查询。
     *
     * 测试服务器已确认同一笔 player_donation 的正负镜像共享 journal id，因此只按 id 分组。
     * (MIN(date), MIN(character_id), id) 仅用于稳定读取与 cursor 排序；normalizer 会复核
     * 分组内 date/party/金额/所属角色是否一致，任何矛盾数据均不会写入违规。
     */
    private function canonicalGroupsQuery(CarbonInterface $auditFrom): Builder
    {
        return DB::table('character_wallet_journals')
            ->where('ref_type', 'player_donation')
            ->selectRaw('id AS canonical_donation_id, MIN(date) AS occurred_at, MIN(character_id) AS cursor_character_id')
            ->groupBy('id')
            ->having('occurred_at', '>=', $auditFrom->toDateTimeString())
            ->orderBy('occurred_at')
            ->orderBy('cursor_character_id')
            ->orderBy('canonical_donation_id');
    }

    /**
     * 对 aggregate/alias 组成的 donation cursor 应用严格“大于”条件。
     * 同一秒、同一角色的多笔真实捐赠由 canonical ID 消歧，不能使用单一时间水位线。
     */
    private function applyAfterPosition(Builder $query, DonationCursorPosition $position): void
    {
        $query->havingRaw(
            '(occurred_at > ?'
            . ' OR (occurred_at = ? AND cursor_character_id > ?)'
            . ' OR (occurred_at = ? AND cursor_character_id = ? AND canonical_donation_id > ?))',
            [
                $position->occurredAt,
                $position->occurredAt,
                $position->characterId,
                $position->occurredAt,
                $position->characterId,
                $position->canonicalDonationId,
            ]
        );
    }

    /**
     * 查询本批 canonical ID 的完整原始 journal 行，并把 amount 在 MariaDB 中转换为 decimal 字符串。
     *
     * @param array<int, string> $canonicalDonationIds
     * @return array<int, object>
     */
    private function loadJournalRows(array $canonicalDonationIds): array
    {
        return DB::table('character_wallet_journals')
            ->where('ref_type', 'player_donation')
            ->whereIn('id', $canonicalDonationIds)
            ->select([
                'character_id',
                'id',
                'first_party_id',
                'second_party_id',
                DB::raw('CAST(amount AS DECIMAL(20, 2)) AS amount_decimal'),
                'date',
                'ref_type',
            ])
            ->orderBy('id')
            ->orderBy('character_id')
            ->get()
            ->all();
    }

    /**
     * 将一批完整来源行转换为固定军团的待写 violation。
     *
     * 成员资格只依据传入的当前成员集合；角色 affiliation 仅在 EntitySnapshotResolver 中用于
     * 当前军团快照和外部方军团白名单判断，绝不反向参与成员资格判定。
     *
     * @param array<int, object> $journalRows
     * @param array<int, true> $memberIds
     * @param array<int, true> $characterWhitelistIds
     * @param array<int, true> $corporationWhitelistIds
     */
    private function buildBatch(
        array $journalRows,
        array $memberIds,
        CarbonInterface $auditFrom,
        array $characterWhitelistIds,
        array $corporationWhitelistIds,
        DonationCursorPosition $lastSourcePosition,
    ): DonationJournalBatch {
        $rowsByCanonicalDonationId = $this->groupJournalRows($journalRows);
        $sourceRows = count($journalRows);
        $invalidReasons = [];
        $validEvents = [];

        foreach ($rowsByCanonicalDonationId as $canonicalRows) {
            $normalizationResult = $this->eventNormalizer->normalize($canonicalRows);
            if (! $normalizationResult->isValid()) {
                $reason = (string) $normalizationResult->invalidReason;
                $invalidReasons[$reason] = ($invalidReasons[$reason] ?? 0) + count($canonicalRows);
                continue;
            }

            $validEvents[] = $normalizationResult->event;
        }

        $candidateAssessments = [];
        foreach ($validEvents as $event) {
            try {
                $occurredAt = CarbonImmutable::parse($event->occurredAt);
            } catch (\Throwable) {
                $invalidReasons['invalid_event_time'] = ($invalidReasons['invalid_event_time'] ?? 0)
                    + count($event->journalRows);
                continue;
            }

            // audit_from 是业务生效边界；cursor 只是读取进度，二者绝不可互相替代。
            if ($occurredAt->lt($auditFrom)) {
                continue;
            }

            $donorIsMember = isset($memberIds[$event->donorId]);
            $recipientIsMember = isset($memberIds[$event->recipientId]);
            if ($donorIsMember === $recipientIsMember) {
                // 双方成员是内部资金流，双方外部与本军团无关，均不记录。
                continue;
            }

            $candidateAssessments[] = [
                'event'                => $event,
                'member_character_id'  => $donorIsMember ? $event->donorId : $event->recipientId,
                'external_party_id'    => $donorIsMember ? $event->recipientId : $event->donorId,
                'direction'            => $donorIsMember ? 'outbound' : 'inbound',
            ];
        }

        $entityIds = [];
        foreach ($candidateAssessments as $assessment) {
            /** @var DonationEvent $event */
            $event = $assessment['event'];
            $entityIds[] = $event->donorId;
            $entityIds[] = $event->recipientId;
        }
        $snapshots = $this->entitySnapshotResolver->resolve($entityIds);

        $violations = [];
        foreach ($candidateAssessments as $assessment) {
            /** @var DonationEvent $event */
            $event = $assessment['event'];
            $memberCharacterId = (int) $assessment['member_character_id'];
            $externalPartyId = (int) $assessment['external_party_id'];
            $direction = (string) $assessment['direction'];

            $donorSnapshot = $snapshots[$event->donorId] ?? EntitySnapshot::unknown($event->donorId);
            $recipientSnapshot = $snapshots[$event->recipientId] ?? EntitySnapshot::unknown($event->recipientId);
            $memberSnapshot = $snapshots[$memberCharacterId] ?? EntitySnapshot::unknown($memberCharacterId);
            $externalSnapshot = $snapshots[$externalPartyId] ?? EntitySnapshot::unknown($externalPartyId);

            // 新规则只豁免外部方。成员本人即使命中角色白名单，仍必须继续审查。
            $externalCharacterWhitelisted = $externalSnapshot->entityType === 'character'
                && isset($characterWhitelistIds[$externalPartyId]);
            $externalCorporationWhitelisted = $externalSnapshot->corporationId !== null
                && isset($corporationWhitelistIds[$externalSnapshot->corporationId]);
            if ($externalCharacterWhitelisted || $externalCorporationWhitelisted) {
                continue;
            }

            $violations[] = [
                'source_event_key'            => $this->sourceEventKeyFactory->iskDonation(
                    AuditCorporation::TARGET_CORPORATION_ID,
                    $event->canonicalDonationId,
                    $event->donorId,
                    $event->recipientId,
                ),
                'source_reference'            => 'wallet_journal_donation:' . $event->canonicalDonationId,
                // character_* 始终表示本军团成员；counterparty_* 始终表示外部参与方，方便最小页面直读。
                'character_id'                => $memberCharacterId,
                'character_name'              => $memberSnapshot->name,
                'counterparty_id'             => $externalPartyId,
                'counterparty_name'           => $externalSnapshot->name,
                'type_id'                     => null,
                'item_name'                   => null,
                'amount'                      => $event->amount,
                'violation_time'              => $event->occurredAt,
                'details'                     => [
                    'source' => [
                        'table'                 => 'character_wallet_journals',
                        'ref_type'              => 'player_donation',
                        'canonical_donation_id' => $event->canonicalDonationId,
                        'is_mirrored'           => $event->isMirrored,
                        'journal_natural_keys'  => $event->journalNaturalKeys,
                        'journal_rows'          => $event->journalRows,
                    ],
                    'parties' => [
                        // donor/recipient 固定由 first_party_id -> second_party_id 表达，不能由余额正负翻转。
                        'donor'     => $this->snapshotDetails($donorSnapshot),
                        'recipient' => $this->snapshotDetails($recipientSnapshot),
                    ],
                    'assessment' => [
                        'audit_corporation_id' => AuditCorporation::TARGET_CORPORATION_ID,
                        'audit_from'           => $auditFrom->toDateTimeString(),
                        'member_character_id'  => $memberCharacterId,
                        'external_party_id'    => $externalPartyId,
                        'direction'            => $direction,
                    ],
                ],
                'audit_type'                  => AuditType::IskDonations->value,
                'audit_corporation_id'        => AuditCorporation::TARGET_CORPORATION_ID,
                'member_character_id'         => $memberCharacterId,
                'external_party_id'           => $externalPartyId,
                'external_party_type'         => $externalSnapshot->entityType,
                'direction'                   => $direction,
                'character_corporation_id'    => $memberSnapshot->corporationId,
                'counterparty_corporation_id' => $externalSnapshot->corporationId,
            ];
        }

        return new DonationJournalBatch(
            violations: $violations,
            sourceRows: $sourceRows,
            invalidReasons: $invalidReasons,
            lastSourcePosition: $lastSourcePosition,
        );
    }

    /**
     * 只按共享 journal id 分组，禁止以时间、金额或参与方的相似性猜测跨 ID 镜像关系。
     *
     * @param array<int, object> $journalRows
     * @return array<string, array<int, object>>
     */
    private function groupJournalRows(array $journalRows): array
    {
        $rowsByCanonicalDonationId = [];

        foreach ($journalRows as $journalRow) {
            $canonicalDonationId = trim((string) ($journalRow->id ?? ''));
            if ($canonicalDonationId === '') {
                // 来源分组查询不应产生空 ID；保留独立桶以便 normalizer 返回结构化 invalid。
                $canonicalDonationId = '__missing_id_' . count($rowsByCanonicalDonationId);
            }

            $rowsByCanonicalDonationId[$canonicalDonationId][] = $journalRow;
        }

        return $rowsByCanonicalDonationId;
    }

    /**
     * @return array{id: int, name: string, entity_type: string, corporation_id: ?int, corporation_name: ?string, corporation_ticker: ?string}
     */
    private function snapshotDetails(EntitySnapshot $snapshot): array
    {
        return [
            'id'                 => $snapshot->entityId,
            'name'               => $snapshot->name,
            'entity_type'        => $snapshot->entityType,
            'corporation_id'     => $snapshot->corporationId,
            'corporation_name'   => $snapshot->corporationName,
            'corporation_ticker' => $snapshot->corporationTicker,
        ];
    }
}
