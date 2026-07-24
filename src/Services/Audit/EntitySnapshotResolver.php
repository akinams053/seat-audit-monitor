<?php

// src/Services/Audit/EntitySnapshotResolver.php
// 按扫描 chunk 批量解析参与方名称、实体类型及当前军团快照，未知实体不阻断审计

namespace Seat\SeatAuditMonitor\Services\Audit;

use Illuminate\Support\Facades\DB;

final class EntitySnapshotResolver
{
    /**
     * 批量解析实体快照。
     *
     * 名称优先级为 character_infos / corporation_infos，再回退到 universe_names。
     * 只有已识别为角色的实体才查询 character_affiliations；因此 affiliation 只表达当前军团快照，
     * 绝不作为成员资格的依据。无法识别的实体仍返回 Unknown 快照，供扫描器继续处理。
     *
     * @param array<int, int|string> $entityIds
     * @return array<int, EntitySnapshot>
     */
    public function resolve(array $entityIds): array
    {
        $normalizedEntityIds = $this->normalizeEntityIds($entityIds);

        if ($normalizedEntityIds === []) {
            return [];
        }

        $characterNames = DB::table('character_infos')
            ->whereIn('character_id', $normalizedEntityIds)
            ->pluck('name', 'character_id')
            ->mapWithKeys(static fn ($name, $characterId): array => [(int) $characterId => (string) $name])
            ->all();

        $universeNames = $this->universeNamesByEntityId($normalizedEntityIds);

        // character_infos 是最可靠的角色来源；外部角色仅存在 universe_names 时，以 category=character 识别。
        $characterIds = [];
        foreach ($normalizedEntityIds as $entityId) {
            if (isset($characterNames[$entityId]) || ($universeNames[$entityId]['category'] ?? null) === 'character') {
                $characterIds[] = $entityId;
            }
        }

        $affiliations = $characterIds === []
            ? []
            : DB::table('character_affiliations')
                ->whereIn('character_id', $characterIds)
                ->select('character_id', 'corporation_id')
                ->get()
                ->mapWithKeys(static fn ($affiliation): array => [
                    (int) $affiliation->character_id => (int) $affiliation->corporation_id,
                ])
                ->all();

        // 角色 affiliation 指向的军团，加上直接作为 corporation 实体出现的参与方，统一补齐军团名称。
        $corporationIds = [];
        foreach ($affiliations as $corporationId) {
            if ($corporationId > 0) {
                $corporationIds[] = $corporationId;
            }
        }
        foreach ($normalizedEntityIds as $entityId) {
            // 未识别为角色的参与方可能是已被 SeAT 同步、但尚未写入 universe_names 的军团。
            // 一并查询 corporation_infos，避免把这类外部军团错误降级为 Unknown。
            if (! in_array($entityId, $characterIds, true)) {
                $corporationIds[] = $entityId;
            }
        }
        $corporationIds = array_values(array_unique($corporationIds));

        $corporationInfos = $corporationIds === []
            ? []
            : DB::table('corporation_infos')
                ->whereIn('corporation_id', $corporationIds)
                ->select('corporation_id', 'name', 'ticker')
                ->get()
                ->mapWithKeys(static fn ($corporation): array => [
                    (int) $corporation->corporation_id => [
                        'name'   => (string) $corporation->name,
                        'ticker' => $corporation->ticker === null ? null : (string) $corporation->ticker,
                    ],
                ])
                ->all();

        // affiliation 指向的外部军团不一定在输入实体中，单独从 universe_names 补齐兜底名称。
        $missingCorporationUniverseIds = array_values(array_diff($corporationIds, array_keys($universeNames)));
        if ($missingCorporationUniverseIds !== []) {
            $universeNames += $this->universeNamesByEntityId($missingCorporationUniverseIds);
        }

        $snapshots = [];
        foreach ($normalizedEntityIds as $entityId) {
            $universeEntity = $universeNames[$entityId] ?? null;
            $entityType = $this->resolveEntityType($entityId, $characterNames, $corporationInfos, $universeEntity);

            if ($entityType === 'unknown') {
                $snapshots[$entityId] = EntitySnapshot::unknown($entityId);
                continue;
            }

            $corporationId = $entityType === 'character'
                ? ($affiliations[$entityId] ?? null)
                : ($entityType === 'corporation' ? $entityId : null);
            $corporation = $corporationId === null ? null : ($corporationInfos[$corporationId] ?? null);
            $corporationUniverse = $corporationId === null ? null : ($universeNames[$corporationId] ?? null);

            $name = match ($entityType) {
                'character' => $characterNames[$entityId] ?? $universeEntity['name'],
                'corporation' => $corporation['name'] ?? $universeEntity['name'],
                'alliance' => $universeEntity['name'],
            };

            $snapshots[$entityId] = new EntitySnapshot(
                entityId: $entityId,
                name: $name,
                entityType: $entityType,
                corporationId: $corporationId,
                corporationName: $corporation['name'] ?? $corporationUniverse['name'] ?? null,
                corporationTicker: $corporation['ticker'] ?? null,
            );
        }

        return $snapshots;
    }

    /**
     * 将输入中的正整数 ID 去重并规范为 int；空值和非正值由上游规则层作为无效事件分别统计。
     *
     * @param array<int, int|string> $entityIds
     * @return array<int, int>
     */
    private function normalizeEntityIds(array $entityIds): array
    {
        $normalizedEntityIds = [];

        foreach ($entityIds as $entityId) {
            $normalized = trim((string) $entityId);
            if ($normalized === '' || preg_match('/^[1-9][0-9]*$/', $normalized) !== 1) {
                continue;
            }

            $normalizedEntityIds[(int) $normalized] = (int) $normalized;
        }

        return array_values($normalizedEntityIds);
    }

    /**
     * @param array<int, int> $entityIds
     * @return array<int, array{name: string, category: string}>
     */
    private function universeNamesByEntityId(array $entityIds): array
    {
        if ($entityIds === []) {
            return [];
        }

        return DB::table('universe_names')
            ->whereIn('entity_id', $entityIds)
            ->select('entity_id', 'name', 'category')
            ->get()
            ->mapWithKeys(static fn ($entity): array => [
                (int) $entity->entity_id => [
                    'name'     => (string) $entity->name,
                    'category' => (string) $entity->category,
                ],
            ])
            ->all();
    }

    /**
     * @param array<int, string> $characterNames
     * @param array<int, array{name: string, ticker: ?string}> $corporationInfos
     * @param array{name: string, category: string}|null $universeEntity
     * @return 'character'|'corporation'|'alliance'|'unknown'
     */
    private function resolveEntityType(
        int $entityId,
        array $characterNames,
        array $corporationInfos,
        ?array $universeEntity
    ): string {
        if (isset($characterNames[$entityId]) || ($universeEntity['category'] ?? null) === 'character') {
            return 'character';
        }

        if (isset($corporationInfos[$entityId]) || ($universeEntity['category'] ?? null) === 'corporation') {
            return 'corporation';
        }

        if (($universeEntity['category'] ?? null) === 'alliance') {
            return 'alliance';
        }

        return 'unknown';
    }
}
