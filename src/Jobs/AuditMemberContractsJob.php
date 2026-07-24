<?php

// src/Jobs/AuditMemberContractsJob.php
// 扫描固定军团 98588384 成员与外部方之间的低价已完成合同；不改变旧监控物品合同审计

namespace Seat\SeatAuditMonitor\Jobs;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Seat\SeatAuditMonitor\Enums\AuditType;
use Seat\SeatAuditMonitor\Models\AuditCorporation;
use Seat\SeatAuditMonitor\Models\AuditCursor;
use Seat\SeatAuditMonitor\Repositories\CursorRepository;
use Seat\SeatAuditMonitor\Services\Audit\EntitySnapshot;
use Seat\SeatAuditMonitor\Services\Audit\EntitySnapshotResolver;
use Seat\SeatAuditMonitor\Services\Audit\SourceEventKeyFactory;
use Seat\SeatAuditMonitor\Services\Audit\ViolationWriter;

class AuditMemberContractsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const AUDIT_CORPORATION_ID = AuditCorporation::TARGET_CORPORATION_ID;
    private const CHUNK_SIZE = 500;
    private const PRICE_THRESHOLD_CENTS = '500000000';

    public function handle(
        CursorRepository $cursorRepository,
        EntitySnapshotResolver $entitySnapshotResolver,
        SourceEventKeyFactory $sourceEventKeyFactory,
        ViolationWriter $violationWriter,
    ): void {
        $auditCorporation = AuditCorporation::query()
            ->where('corporation_id', self::AUDIT_CORPORATION_ID)
            ->first();

        // audit_from 由部署时明确设置；禁用或未配置时不得创建 cursor，更不能扫入历史合同。
        if ($auditCorporation === null
            || ! $auditCorporation->enabled
            || ! $auditCorporation->audit_contracts
            || $auditCorporation->audit_from === null) {
            Log::warning('[seat-audit:member-contracts] 跳过扫描：98588384 未配置、未启用、未开启合同审计或缺少 audit_from。');

            return;
        }

        $auditFrom = CarbonImmutable::parse($auditCorporation->audit_from);
        $memberIds = $this->idSet(
            DB::table('corporation_members')
                ->where('corporation_id', self::AUDIT_CORPORATION_ID)
                ->pluck('character_id')
                ->all()
        );
        if ($memberIds === []) {
            // 空名册时绝不推进 cursor，避免成员同步故障造成不可逆漏审。
            Log::warning('[seat-audit:member-contracts] 跳过扫描：98588384 当前成员名册为空，未推进 cursor。');

            return;
        }

        $characterWhitelistIds = $this->idSet(
            DB::table('seat_audit_whitelist')->pluck('character_id')->all()
        );
        $corporationWhitelistIds = $this->idSet(
            DB::table('seat_audit_corporation_whitelist')->pluck('corporation_id')->all()
        );

        // 读 cursor 时回退十分钟，用于吸收 character_contracts 映射同步延迟或发现时间边界问题；
        // 该临时位置仅控制本次读取顺序，正式 cursor 始终只会由 advanceIfAhead() 单调向前推进。
        $readAfterPosition = DB::transaction(function () use ($cursorRepository): ?array {
            $cursor = $cursorRepository->lockOrCreateForUpdate(
                AuditType::MemberContracts->value,
                self::AUDIT_CORPORATION_ID,
            );
            $cursorRepository->markStarted($cursor);

            if ($cursor->cursor_at === null) {
                return null;
            }

            if ((int) $cursor->cursor_id <= 0) {
                throw new RuntimeException('成员合同 cursor 数据不完整，已拒绝扫描以避免重复或漏审。');
            }

            return [
                'discovered_at' => CarbonImmutable::parse($cursor->cursor_at)->subMinutes(10)->toDateTimeString(),
                'contract_id'   => 0,
            ];
        });

        while (true) {
            /** @var array{discovered_at: string, contract_id: int}|null $nextPosition */
            $nextPosition = DB::transaction(function () use (
                $cursorRepository,
                $entitySnapshotResolver,
                $sourceEventKeyFactory,
                $violationWriter,
                $memberIds,
                $auditFrom,
                $characterWhitelistIds,
                $corporationWhitelistIds,
                $readAfterPosition,
            ): ?array {
                $cursor = $cursorRepository->lockOrCreateForUpdate(
                    AuditType::MemberContracts->value,
                    self::AUDIT_CORPORATION_ID,
                );
                $contracts = $this->readContractChunk($auditFrom, $readAfterPosition);

                if ($contracts === []) {
                    $cursorRepository->markSucceeded($cursor);

                    return null;
                }

                $violations = $this->buildViolations(
                    contracts: $contracts,
                    memberIds: $memberIds,
                    auditFrom: $auditFrom,
                    characterWhitelistIds: $characterWhitelistIds,
                    corporationWhitelistIds: $corporationWhitelistIds,
                    entitySnapshotResolver: $entitySnapshotResolver,
                    sourceEventKeyFactory: $sourceEventKeyFactory,
                );

                $violationWriter->insertOrIgnore($violations);
                $lastContract = $contracts[array_key_last($contracts)];
                $position = [
                    'discovered_at' => CarbonImmutable::parse($lastContract->discovered_at)->toDateTimeString(),
                    'contract_id'   => (int) $lastContract->contract_id,
                ];

                // overlap 会再次读取旧来源；旧位置仍要安全完成幂等写入，但不得倒退正式 cursor。
                $cursorRepository->advanceIfAhead(
                    $cursor,
                    CarbonImmutable::parse($position['discovered_at']),
                    $position['contract_id'],
                );

                return $position;
            });

            if ($nextPosition === null) {
                return;
            }

            // 无论本批合同是否命中低价规则，都继续使用其末尾来源位置读取下一批；否则 overlap
            // 内的大量无关合同可能使扫描永久停在同一段来源数据。
            $readAfterPosition = $nextPosition;
        }
    }

    /**
     * 读取一批按 (discovered_at, contract_id) 稳定排序的已完成物品交换/拍卖合同。
     *
     * contract_details 没有同步时间字段，不能将 date_completed 当作发现 cursor：延迟同步的旧
     * 完成合同可能被永久跳过。因此仅从 character_contracts 聚合每份合同最新的映射更新时间
     * 为 discovered_at，用它发现来源和推进 cursor；合同状态、金额、双方和业务时间仍只从
     * contract_details 读取，最终仍在 buildViolations() 中按 date_completed 判断 audit_from。
     *
     * @param array{discovered_at: string, contract_id: int}|null $afterPosition
     * @return array<int, object>
     */
    private function readContractChunk(CarbonInterface $auditFrom, ?array $afterPosition): array
    {
        // 同一合同可能关联多个 SeAT 角色；先聚合为一个最新发现位置，避免 cursor 分页时重复来源。
        $discoveryQuery = DB::table('character_contracts as cc')
            ->select('cc.contract_id', DB::raw('MAX(cc.updated_at) AS discovered_at'))
            ->whereNotNull('cc.updated_at')
            ->where('cc.updated_at', '>=', $auditFrom->toDateTimeString())
            ->groupBy('cc.contract_id');

        $query = DB::table('contract_details as cd')
            ->joinSub($discoveryQuery, 'discovery', function ($join) {
                $join->on('discovery.contract_id', '=', 'cd.contract_id');
            })
            // discovered_at 仅用于当前扫描排序和 cursor，不是合同业务快照的一部分。
            ->select('cd.*', 'discovery.discovered_at')
            ->where('cd.status', 'finished')
            ->whereIn('cd.type', ['item_exchange', 'auction'])
            ->whereNotNull('cd.date_completed');

        if ($afterPosition !== null) {
            $query->where(function ($positionQuery) use ($afterPosition) {
                $positionQuery->where('discovery.discovered_at', '>', $afterPosition['discovered_at'])
                    ->orWhere(function ($sameTimeQuery) use ($afterPosition) {
                        $sameTimeQuery->where('discovery.discovered_at', '=', $afterPosition['discovered_at'])
                            ->where('cd.contract_id', '>', $afterPosition['contract_id']);
                    });
            });
        }

        return $query
            ->orderBy('discovery.discovered_at')
            ->orderBy('cd.contract_id')
            ->limit(self::CHUNK_SIZE)
            ->get()
            ->all();
    }

    /**
     * 对一批合同应用成员 XOR、低价和外部方白名单规则，并保留完整物品/角色快照。
     *
     * @param array<int, object> $contracts
     * @param array<int, true> $memberIds
     * @param array<int, true> $characterWhitelistIds
     * @param array<int, true> $corporationWhitelistIds
     * @return array<int, array<string, mixed>>
     */
    private function buildViolations(
        array $contracts,
        array $memberIds,
        CarbonInterface $auditFrom,
        array $characterWhitelistIds,
        array $corporationWhitelistIds,
        EntitySnapshotResolver $entitySnapshotResolver,
        SourceEventKeyFactory $sourceEventKeyFactory,
    ): array {
        $contractIds = array_map(static fn (object $contract): int => (int) $contract->contract_id, $contracts);
        $itemsByContract = [];

        // 新成员合同规则不以监控物品为条件，必须保存本批合同的完整 contract_items 快照。
        foreach (DB::table('contract_items')->whereIn('contract_id', $contractIds)->get() as $item) {
            $itemsByContract[(int) $item->contract_id][] = (array) $item;
        }

        $candidateAssessments = [];
        foreach ($contracts as $contract) {
            $issuerId = $this->positiveInteger($contract->issuer_id ?? null);
            $acceptorId = $this->positiveInteger($contract->acceptor_id ?? null);
            if ($issuerId === null || $acceptorId === null) {
                // finished 合同理论上有 acceptor；来源异常时跳过，但外层仍推进已消费来源位置。
                continue;
            }

            $completedAt = $this->parseDate($contract->date_completed ?? null);
            if ($completedAt === null || $completedAt->lt($auditFrom)) {
                continue;
            }

            $price = $this->normalizeDecimal($contract->price ?? null);
            if ($price === null || ! $this->isBelowPriceThreshold($price)) {
                continue;
            }

            $issuerIsMember = isset($memberIds[$issuerId]);
            $acceptorIsMember = isset($memberIds[$acceptorId]);
            if ($issuerIsMember === $acceptorIsMember) {
                // 成员双方互转与外部双方合同均不属于固定军团的对外低价合同。
                continue;
            }

            $candidateAssessments[] = [
                'contract'            => $contract,
                'completed_at'        => $completedAt,
                'price'               => $price,
                'member_character_id' => $issuerIsMember ? $issuerId : $acceptorId,
                'external_party_id'   => $issuerIsMember ? $acceptorId : $issuerId,
                'direction'           => $issuerIsMember ? 'outbound' : 'inbound',
            ];
        }

        $entityIds = [];
        foreach ($candidateAssessments as $assessment) {
            $contract = $assessment['contract'];
            $entityIds[] = (int) $contract->issuer_id;
            $entityIds[] = (int) $contract->acceptor_id;
            if ($this->positiveInteger($contract->assignee_id ?? null) !== null) {
                $entityIds[] = (int) $contract->assignee_id;
            }
        }
        $snapshots = $entitySnapshotResolver->resolve($entityIds);

        $violations = [];
        foreach ($candidateAssessments as $assessment) {
            $contract = $assessment['contract'];
            /** @var CarbonImmutable $completedAt */
            $completedAt = $assessment['completed_at'];
            $memberCharacterId = (int) $assessment['member_character_id'];
            $externalPartyId = (int) $assessment['external_party_id'];
            $direction = (string) $assessment['direction'];
            $issuerId = (int) $contract->issuer_id;
            $acceptorId = (int) $contract->acceptor_id;

            $issuerSnapshot = $snapshots[$issuerId] ?? EntitySnapshot::unknown($issuerId);
            $acceptorSnapshot = $snapshots[$acceptorId] ?? EntitySnapshot::unknown($acceptorId);
            $memberSnapshot = $snapshots[$memberCharacterId] ?? EntitySnapshot::unknown($memberCharacterId);
            $externalSnapshot = $snapshots[$externalPartyId] ?? EntitySnapshot::unknown($externalPartyId);
            $assigneeId = $this->positiveInteger($contract->assignee_id ?? null);
            $assigneeSnapshot = $assigneeId === null
                ? null
                : ($snapshots[$assigneeId] ?? EntitySnapshot::unknown($assigneeId));

            // 只检查外部方。成员白名单不改变固定军团成员的审计范围。
            $externalCharacterWhitelisted = $externalSnapshot->entityType === 'character'
                && isset($characterWhitelistIds[$externalPartyId]);
            $externalCorporationWhitelisted = $externalSnapshot->corporationId !== null
                && isset($corporationWhitelistIds[$externalSnapshot->corporationId]);
            if ($externalCharacterWhitelisted || $externalCorporationWhitelisted) {
                continue;
            }

            $contractItems = $itemsByContract[(int) $contract->contract_id] ?? [];
            // discovered_at 是 character_contracts 的聚合发现时间，只能参与本轮 cursor，不能混入
            // 对外可追溯的合同业务快照；合同内容仍严格来自 contract_details。
            $contractDetails = (array) $contract;
            unset($contractDetails['discovered_at']);

            $violations[] = [
                'source_event_key'            => $sourceEventKeyFactory->memberContract(
                    self::AUDIT_CORPORATION_ID,
                    $contract->contract_id,
                ),
                'source_reference'            => 'contract:' . $contract->contract_id,
                'character_id'                => $memberCharacterId,
                'character_name'              => $memberSnapshot->name,
                'counterparty_id'             => $externalPartyId,
                'counterparty_name'           => $externalSnapshot->name,
                'type_id'                     => null,
                'item_name'                   => null,
                // 新规则金额和低价门槛只使用 price，绝不能套用旧合同审计的 max(price,reward)。
                'amount'                      => $assessment['price'],
                'violation_time'              => $completedAt->toDateTimeString(),
                'details'                     => [
                    'contract' => $contractDetails,
                    'items'    => $contractItems,
                    'parties'  => [
                        'issuer'   => $this->snapshotDetails($issuerSnapshot),
                        'assignee' => $assigneeSnapshot === null ? null : $this->snapshotDetails($assigneeSnapshot),
                        'acceptor' => $this->snapshotDetails($acceptorSnapshot),
                    ],
                    'assessment' => [
                        'audit_corporation_id' => self::AUDIT_CORPORATION_ID,
                        'audit_from'           => $auditFrom->toDateTimeString(),
                        'member_character_id'  => $memberCharacterId,
                        'external_party_id'    => $externalPartyId,
                        'direction'            => $direction,
                        'price_threshold'      => '5000000.00',
                        'price'                => $assessment['price'],
                        'reward'               => $contract->reward,
                    ],
                ],
                'audit_type'                  => AuditType::MemberContracts->value,
                'audit_corporation_id'        => self::AUDIT_CORPORATION_ID,
                'member_character_id'         => $memberCharacterId,
                'external_party_id'           => $externalPartyId,
                'external_party_type'         => $externalSnapshot->entityType,
                'direction'                   => $direction,
                'character_corporation_id'    => $memberSnapshot->corporationId,
                'counterparty_corporation_id' => $externalSnapshot->corporationId,
                'contract_id'                 => $contract->contract_id,
                'contract_availability'       => $contract->availability,
            ];
        }

        return $violations;
    }

    /**
     * 非负 decimal 字符串转为两位小数；任何浮点、指数记法或超过两位的小数都不参与低价判断。
     */
    private function normalizeDecimal(mixed $value): ?string
    {
        if (is_float($value)) {
            return null;
        }

        $normalized = trim((string) ($value ?? ''));
        if (preg_match('/^[0-9]+(?:\.[0-9]{1,2})?$/', $normalized) !== 1) {
            return null;
        }

        [$integer, $fraction] = array_pad(explode('.', $normalized, 2), 2, '');
        $integer = ltrim($integer, '0') ?: '0';
        $fraction = str_pad($fraction, 2, '0');

        return $integer . '.' . $fraction;
    }

    /**
     * 使用“分”字符串比较，避免把 price 转为 PHP float。
     */
    private function isBelowPriceThreshold(string $price): bool
    {
        [$integer, $fraction] = explode('.', $price, 2);
        $cents = ltrim($integer . $fraction, '0') ?: '0';

        if (strlen($cents) !== strlen(self::PRICE_THRESHOLD_CENTS)) {
            return strlen($cents) < strlen(self::PRICE_THRESHOLD_CENTS);
        }

        return strcmp($cents, self::PRICE_THRESHOLD_CENTS) < 0;
    }

    private function parseDate(mixed $value): ?CarbonImmutable
    {
        try {
            return $value === null ? null : CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function positiveInteger(mixed $value): ?int
    {
        $normalized = trim((string) ($value ?? ''));
        if (preg_match('/^[1-9][0-9]*$/', $normalized) !== 1) {
            return null;
        }

        return (int) $normalized;
    }

    /**
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
