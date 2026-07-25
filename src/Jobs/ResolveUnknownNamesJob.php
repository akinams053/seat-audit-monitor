<?php

// src/Jobs/ResolveUnknownNamesJob.php
// 批量解析审计记录中的 Unknown 实体名称、角色当前 affiliation 及军团/联盟名称。
// 触发方式：旧违规记录或军团审计页面的「解析未知来源」按钮（异步入 Horizon），
// 或 Artisan seat:audit:resolve-unknown-names（同步执行）。

namespace Seat\SeatAuditMonitor\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Seat\SeatAuditMonitor\Enums\AuditType;

class ResolveUnknownNamesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** ESI 接口单批 ID 上限。 */
    private const ESI_BATCH_SIZE = 1000;

    private const ESI_NAMES_ENDPOINT       = 'https://esi.evetech.net/latest/universe/names/';
    private const ESI_AFFILIATION_ENDPOINT = 'https://esi.evetech.net/latest/characters/affiliation/';

    private const LOG_PREFIX = '[seat-audit:resolve-unknown]';

    public function handle(): void
    {
        // 第一步仅处理真的仍为 Unknown 的实体名称。/universe/names/ 会返回角色、军团或联盟类别，
        // 所以不能沿用旧逻辑假定 counterparty_id 一定是角色。
        $unknownEntityIds = $this->unknownTopLevelEntityIds();
        [$resolvedEntityNames, $resolvedEntityCategories, $failedNameBatches] = $this->resolveUnknownEntityNames($unknownEntityIds);

        // 除名称解析外，军团列表还需要补全参与者军团名称。这里从旧合同和 2.0 的两种事件
        // 收集“已由来源语义或快照类型确认的角色”，并收集扫描时写入的军团 ID 快照。未知 ID
        // 只有被 names endpoint 识别为 character 后才允许进入 affiliation endpoint。
        $participants = $this->collectAuditParticipants();
        foreach ($resolvedEntityCategories as $entityId => $category) {
            if ($category === 'character') {
                $participants['character_ids'][$entityId] = true;
            }
        }

        $characterIds = array_keys($participants['character_ids']);
        $existingAffiliations = $this->existingAffiliations($characterIds);
        $charactersNeedingAffiliation = array_values(array_diff($characterIds, array_keys($existingAffiliations)));
        [$resolvedAffiliations, $failedAffiliationBatches] = $this->resolveAffiliations($charactersNeedingAffiliation);

        // 现有 affiliation 和本次 ESI 返回的 affiliation 都可为页面补全军团名；同时纳入 2.0
        // 违规记录和合同快照中的历史军团 ID。只缓存名字，不回写 violation 的历史军团快照字段。
        $corporationAndAllianceIds = $participants['corporation_and_alliance_ids'];
        foreach ($existingAffiliations as $affiliation) {
            $corporationAndAllianceIds[$affiliation['corporation_id']] = true;
            if ($affiliation['alliance_id'] !== null) {
                $corporationAndAllianceIds[$affiliation['alliance_id']] = true;
            }
        }
        foreach ($resolvedAffiliations as $affiliation) {
            $corporationAndAllianceIds[$affiliation['corporation_id']] = true;
            if ($affiliation['alliance_id'] !== null) {
                $corporationAndAllianceIds[$affiliation['alliance_id']] = true;
            }
        }

        [$resolvedCorporationNames, $failedCorporationNameBatches] = $this->resolveCorporationAndAllianceNames(
            array_keys($corporationAndAllianceIds),
        );

        Log::info(self::LOG_PREFIX . ' 解析完成。', [
            'unknown_entity_candidates' => count($unknownEntityIds),
            'resolved_top_level_names' => count($resolvedEntityNames),
            'confirmed_character_participants' => count($characterIds),
            'new_affiliations' => count($resolvedAffiliations),
            'resolved_corporation_or_alliance_names' => $resolvedCorporationNames,
            'failed_name_batches' => $failedNameBatches,
            'failed_affiliation_batches' => $failedAffiliationBatches,
            'failed_corporation_name_batches' => $failedCorporationNameBatches,
        ]);
    }

    /**
     * 收集顶层名称仍是 Unknown 的实体 ID。
     *
     * character_* 对旧 1.0 和 2.0 通常是角色，counterparty_* 在军团审计中却可能是角色、军团
     * 或联盟。统一交给 ESI names endpoint 分类后再决定是否查询 affiliation。
     *
     * @return array<int, int>
     */
    private function unknownTopLevelEntityIds(): array
    {
        $entityIds = [];

        foreach (DB::table('seat_audit_violations')
            ->where('character_name', 'LIKE', 'Unknown (ID:%')
            ->whereNotNull('character_id')
            ->pluck('character_id') as $entityId) {
            $normalized = $this->positiveInteger($entityId);
            if ($normalized !== null) {
                $entityIds[$normalized] = $normalized;
            }
        }

        foreach (DB::table('seat_audit_violations')
            ->where('counterparty_name', 'LIKE', 'Unknown (ID:%')
            ->whereNotNull('counterparty_id')
            ->pluck('counterparty_id') as $entityId) {
            $normalized = $this->positiveInteger($entityId);
            if ($normalized !== null) {
                $entityIds[$normalized] = $normalized;
            }
        }

        // member_contracts 的 assignee 不一定写在顶层；若其实体快照仍是 Unknown，也一并交给
        // names endpoint。这里不会把 details 当作指令执行，只安全提取固定键和正整数 ID。
        foreach (DB::table('seat_audit_violations')
            ->where('audit_type', AuditType::MemberContracts->value)
            ->select('details')
            ->cursor() as $violation) {
            foreach ($this->partySnapshots($violation->details ?? null) as $party) {
                $entityId = $this->positiveInteger($party['id'] ?? null);
                $name = trim((string) ($party['name'] ?? ''));
                if ($entityId !== null && str_starts_with($name, 'Unknown (ID:')) {
                    $entityIds[$entityId] = $entityId;
                }
            }
        }

        return array_values($entityIds);
    }

    /**
     * 调用 names endpoint，更新顶层 Unknown 名称并缓存 entity category。
     *
     * @param array<int, int> $entityIds
     * @return array{0: array<int, string>, 1: array<int, string>, 2: int}
     */
    private function resolveUnknownEntityNames(array $entityIds): array
    {
        $resolvedNames = [];
        $resolvedCategories = [];
        $failedBatches = 0;

        foreach (array_chunk($entityIds, self::ESI_BATCH_SIZE) as $batch) {
            $data = $this->postEsi(self::ESI_NAMES_ENDPOINT, $batch, 'entity-names');
            if ($data === null) {
                $failedBatches++;
                continue;
            }

            foreach ($data as $item) {
                $entityId = $this->positiveInteger($item['id'] ?? null);
                $name = $item['name'] ?? null;
                $category = $item['category'] ?? null;
                if ($entityId === null
                    || ! is_string($name)
                    || trim($name) === ''
                    || ! in_array($category, ['character', 'corporation', 'alliance'], true)) {
                    continue;
                }

                // 仅替换仍为 Unknown 的顶层快照，保留已经记录的正常名字，避免 ESI 延迟或实体
                // 重名导致历史审计表被意外覆盖。无论类别为何都允许更新：2.0 外部方可以是军团/联盟。
                DB::table('seat_audit_violations')
                    ->where('character_id', $entityId)
                    ->where('character_name', 'LIKE', 'Unknown (ID:%')
                    ->update(['character_name' => $name]);
                DB::table('seat_audit_violations')
                    ->where('counterparty_id', $entityId)
                    ->where('counterparty_name', 'LIKE', 'Unknown (ID:%')
                    ->update(['counterparty_name' => $name]);

                $this->upsertUniverseName($entityId, $name, $category);
                $resolvedNames[$entityId] = $name;
                $resolvedCategories[$entityId] = $category;
            }
        }

        return [$resolvedNames, $resolvedCategories, $failedBatches];
    }

    /**
     * 从所有可显示审计类型收集确认的角色参与者和军团/联盟快照 ID。
     *
     * @return array{
     *     character_ids: array<int, true>,
     *     corporation_and_alliance_ids: array<int, true>
     * }
     */
    private function collectAuditParticipants(): array
    {
        $characterIds = [];
        $corporationAndAllianceIds = [];

        DB::table('seat_audit_violations')
            ->whereIn('audit_type', [
                AuditType::Contracts->value,
                AuditType::IskDonations->value,
                AuditType::MemberContracts->value,
            ])
            ->select([
                'audit_type',
                'character_id',
                'counterparty_id',
                'external_party_type',
                'character_corporation_id',
                'counterparty_corporation_id',
                'details',
            ])
            ->orderBy('id')
            ->cursor()
            ->each(function (object $violation) use (&$characterIds, &$corporationAndAllianceIds): void {
                $memberOrIssuerId = $this->positiveInteger($violation->character_id ?? null);
                if ($memberOrIssuerId !== null) {
                    // 旧 Contracts 的 issuer/acceptor 都是 character；2.0 character_id 固定为成员角色。
                    $characterIds[$memberOrIssuerId] = true;
                }

                $counterpartyId = $this->positiveInteger($violation->counterparty_id ?? null);
                if ($counterpartyId !== null) {
                    if ($violation->audit_type === AuditType::Contracts->value
                        || $violation->external_party_type === 'character') {
                        $characterIds[$counterpartyId] = true;
                    }
                }

                foreach ([$violation->character_corporation_id ?? null, $violation->counterparty_corporation_id ?? null] as $corporationId) {
                    $normalized = $this->positiveInteger($corporationId);
                    if ($normalized !== null) {
                        $corporationAndAllianceIds[$normalized] = true;
                    }
                }

                // member_contracts 保存 issuer / assignee / acceptor 对象；Donation 的 donor / recipient
                // 也使用同一快照对象格式。只信任 entity_type=character 的对象进入 affiliation 接口。
                foreach ($this->partySnapshots($violation->details ?? null) as $party) {
                    $entityId = $this->positiveInteger($party['id'] ?? null);
                    if ($entityId !== null && ($party['entity_type'] ?? null) === 'character') {
                        $characterIds[$entityId] = true;
                    }
                    $corporationId = $this->positiveInteger($party['corporation_id'] ?? null);
                    if ($corporationId !== null) {
                        $corporationAndAllianceIds[$corporationId] = true;
                    }
                }
            });

        return [
            'character_ids' => $characterIds,
            'corporation_and_alliance_ids' => $corporationAndAllianceIds,
        ];
    }

    /**
     * @param array<int, int> $characterIds
     * @return array<int, array{corporation_id: int, alliance_id: ?int}>
     */
    private function existingAffiliations(array $characterIds): array
    {
        if ($characterIds === []) {
            return [];
        }

        return DB::table('character_affiliations')
            ->whereIn('character_id', $characterIds)
            ->select('character_id', 'corporation_id', 'alliance_id')
            ->get()
            ->mapWithKeys(function (object $affiliation): array {
                return [(int) $affiliation->character_id => [
                    'corporation_id' => (int) $affiliation->corporation_id,
                    'alliance_id' => $this->positiveInteger($affiliation->alliance_id ?? null),
                ]];
            })
            ->all();
    }

    /**
     * 只将已确认的角色提交给 affiliation endpoint，避免把军团/联盟 ID 误当角色 ID 请求 ESI。
     *
     * @param array<int, int> $characterIds
     * @return array<int, array{corporation_id: int, alliance_id: ?int}>
     */
    private function resolveAffiliations(array $characterIds): array
    {
        $affiliations = [];
        $failedBatches = 0;

        foreach (array_chunk($characterIds, self::ESI_BATCH_SIZE) as $batch) {
            $data = $this->postEsi(self::ESI_AFFILIATION_ENDPOINT, $batch, 'affiliation');
            if ($data === null) {
                $failedBatches++;
                continue;
            }

            foreach ($data as $item) {
                $characterId = $this->positiveInteger($item['character_id'] ?? null);
                $corporationId = $this->positiveInteger($item['corporation_id'] ?? null);
                $allianceId = $this->positiveInteger($item['alliance_id'] ?? null);
                $factionId = $this->positiveInteger($item['faction_id'] ?? null);
                if ($characterId === null || $corporationId === null) {
                    continue;
                }

                // 不更新既有 affiliation，避免覆盖 SeAT 自己的同步时间线；insertOrIgnore 同时吸收
                // 两个管理员近乎同时提交解析任务时的竞争，仍让本批取得军团 ID 以补全名称缓存。
                DB::table('character_affiliations')->insertOrIgnore([
                    'character_id' => $characterId,
                    'corporation_id' => $corporationId,
                    'alliance_id' => $allianceId,
                    'faction_id' => $factionId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $affiliations[$characterId] = [
                    'corporation_id' => $corporationId,
                    'alliance_id' => $allianceId,
                ];
            }
        }

        return [$affiliations, $failedBatches];
    }

    /**
     * 补齐军团或联盟名称。优先信任 SeAT 已有 corporation_infos；未知名称才写入 universe_names。
     *
     * @param array<int, int> $entityIds
     * @return array{0: int, 1: int}
     */
    private function resolveCorporationAndAllianceNames(array $entityIds): array
    {
        if ($entityIds === []) {
            return [0, 0];
        }

        $knownUniverseIds = DB::table('universe_names')
            ->whereIn('entity_id', $entityIds)
            ->whereIn('category', ['corporation', 'alliance'])
            ->pluck('entity_id')
            ->map(static fn ($entityId): int => (int) $entityId)
            ->all();
        $knownCorporationIds = DB::table('corporation_infos')
            ->whereIn('corporation_id', $entityIds)
            ->pluck('corporation_id')
            ->map(static fn ($entityId): int => (int) $entityId)
            ->all();
        $idsToResolve = array_values(array_diff($entityIds, $knownUniverseIds, $knownCorporationIds));

        $resolvedCount = 0;
        $failedBatches = 0;
        foreach (array_chunk($idsToResolve, self::ESI_BATCH_SIZE) as $batch) {
            $data = $this->postEsi(self::ESI_NAMES_ENDPOINT, $batch, 'corporation-alliance-names');
            if ($data === null) {
                $failedBatches++;
                continue;
            }

            foreach ($data as $item) {
                $entityId = $this->positiveInteger($item['id'] ?? null);
                $name = $item['name'] ?? null;
                $category = $item['category'] ?? null;
                if ($entityId === null
                    || ! is_string($name)
                    || trim($name) === ''
                    || ! in_array($category, ['corporation', 'alliance'], true)) {
                    continue;
                }

                $this->upsertUniverseName($entityId, $name, $category);
                $resolvedCount++;
            }
        }

        return [$resolvedCount, $failedBatches];
    }

    /**
     * 把 Donation 的 donor/recipient 或成员合同的 issuer/assignee/acceptor 快照统一为安全数组。
     *
     * @return array<int, array<string, mixed>>
     */
    private function partySnapshots(mixed $details): array
    {
        $decoded = $this->detailsArray($details);
        $parties = $decoded['parties'] ?? [];
        if (! is_array($parties)) {
            return [];
        }

        $snapshots = [];
        foreach (['issuer', 'assignee', 'acceptor', 'donor', 'recipient'] as $key) {
            if (isset($parties[$key]) && is_array($parties[$key])) {
                $snapshots[] = $parties[$key];
            }
        }

        return $snapshots;
    }

    /**
     * 统一调用 ESI POST 接口；失败返回 null，调用方按整批跳过并记录统计。
     *
     * @param array<int, int> $payload
     * @return array<int, array<string, mixed>>|null
     */
    private function postEsi(string $endpoint, array $payload, string $tag): ?array
    {
        if ($payload === []) {
            return [];
        }

        try {
            $response = Http::timeout(30)
                ->acceptJson()
                ->asJson()
                ->post($endpoint, array_values($payload));
        } catch (\Throwable $exception) {
            Log::warning(self::LOG_PREFIX . ' [' . $tag . '] HTTP 异常：' . $exception->getMessage());

            return null;
        }

        if (! $response->successful()) {
            Log::warning(
                self::LOG_PREFIX . ' [' . $tag . '] 状态 ' . $response->status()
                . '，body：' . mb_substr((string) $response->body(), 0, 500)
            );

            return null;
        }

        $data = $response->json();
        if (! is_array($data)) {
            Log::warning(self::LOG_PREFIX . ' [' . $tag . '] 响应不是数组');

            return null;
        }

        return $data;
    }

    /**
     * 插入 SeAT universe_names 缓存；已有数据不覆盖，避免干扰 SeAT 原生同步轨迹。
     */
    private function upsertUniverseName(int $entityId, string $name, string $category): void
    {
        $exists = DB::table('universe_names')
            ->where('entity_id', $entityId)
            ->exists();
        if ($exists) {
            return;
        }

        DB::table('universe_names')->insert([
            'entity_id' => $entityId,
            'name' => $name,
            'category' => $category,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function detailsArray(mixed $details): array
    {
        if (is_array($details)) {
            return $details;
        }
        if (! is_string($details) || $details === '') {
            return [];
        }

        $decoded = json_decode($details, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function positiveInteger(mixed $value): ?int
    {
        $normalized = trim((string) ($value ?? ''));
        if (preg_match('/^[1-9][0-9]*$/', $normalized) !== 1) {
            return null;
        }

        return (int) $normalized;
    }
}
