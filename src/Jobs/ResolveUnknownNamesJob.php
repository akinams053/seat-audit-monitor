<?php

// src/Jobs/ResolveUnknownNamesJob.php
// 解析外部 character_id 的姓名 + 当前所属军团 + 军团名字，并回填到本地 SeAT 数据：
//  - violations.character_name / counterparty_name（角色名快照）
//  - universe_names（角色和军团名字缓存，SeAT 自身使用的表）
//  - character_affiliations（角色 → 军团映射，SeAT 自身使用的表）
// 触发方式：UI「解析未知来源」按钮（异步入 Horizon）或 Artisan seat:audit:resolve-unknown-names

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

    /**
     * ESI 接口单批 ID 上限
     */
    const ESI_BATCH_SIZE = 1000;

    const ESI_NAMES_ENDPOINT       = 'https://esi.evetech.net/latest/universe/names/';
    const ESI_AFFILIATION_ENDPOINT = 'https://esi.evetech.net/latest/characters/affiliation/';

    const LOG_PREFIX = '[seat-audit:resolve-unknown]';

    public function handle()
    {
        // ============ 步骤 1：收集所有未解析的 character_id ============
        // 包括 character_name 和 counterparty_name 仍是 'Unknown (ID:%' 的行
        $charIds = DB::table('seat_audit_violations')
            ->where('character_name', 'LIKE', 'Unknown (ID:%')
            ->whereNotNull('character_id')
            ->pluck('character_id')
            ->toArray();

        $counterpartyIds = DB::table('seat_audit_violations')
            ->where('counterparty_name', 'LIKE', 'Unknown (ID:%')
            ->whereNotNull('counterparty_id')
            ->pluck('counterparty_id')
            ->toArray();

        $charactersNeedingNameResolve = array_values(array_unique(array_filter(
            array_merge($charIds, $counterpartyIds),
            fn ($id) => $id > 0
        )));

        // ============ 步骤 2：另外收集所有合同行涉及的角色（用于解析他们的 affiliation） ============
        // 即使角色名已经解析（character_name 已是真实姓名），他们可能还没在 character_affiliations 里——
        // 没 affiliation 则 UI 显示不出军团。这里把所有合同行的 issuer + acceptor 都纳入 affiliation 解析。
        $contractParticipantIds = DB::table('seat_audit_violations')
            ->where('audit_type', AuditType::Contracts->value)
            ->select('character_id', 'counterparty_id')
            ->get()
            ->flatMap(fn ($r) => [$r->character_id, $r->counterparty_id])
            ->filter(fn ($id) => $id !== null && $id > 0)
            ->unique()
            ->values()
            ->toArray();

        // 过滤掉已有 affiliation 行的角色，避免重复 ESI 调用
        $existingAffiliations = empty($contractParticipantIds)
            ? []
            : DB::table('character_affiliations')
                ->whereIn('character_id', $contractParticipantIds)
                ->pluck('character_id')
                ->toArray();

        $charactersNeedingAffiliationResolve = array_values(array_diff(
            $contractParticipantIds,
            $existingAffiliations
        ));

        // 合并：需要解析名字 或 需要解析 affiliation 的所有角色 ID
        $allCharactersToResolve = array_values(array_unique(array_merge(
            $charactersNeedingNameResolve,
            $charactersNeedingAffiliationResolve
        )));

        // 不在这里 early return：即使前两类都为空，步骤 4.5 仍可能补全已存在 affiliation 行的 corp 名字。
        // 各步骤内部对空数组都是 no-op，安全。

        Log::info(
            self::LOG_PREFIX . ' 名字待解析：' . count($charactersNeedingNameResolve)
            . '；affiliation 待解析：' . count($charactersNeedingAffiliationResolve)
            . '；合同参与者总数：' . count($contractParticipantIds)
        );

        // ============ 步骤 3：批量解析角色名（POST /universe/names/） ============
        // 同时把 character 名字 UPSERT 到 universe_names 表（SeAT 共用缓存）
        $resolvedNameCount = 0;
        $failedNameBatch = 0;

        foreach (array_chunk($charactersNeedingNameResolve, self::ESI_BATCH_SIZE) as $batch) {
            $data = $this->postEsi(self::ESI_NAMES_ENDPOINT, array_values($batch), 'names');

            if ($data === null) {
                $failedNameBatch++;
                continue;
            }

            foreach ($data as $item) {
                if (($item['category'] ?? null) !== 'character') {
                    continue;
                }
                $id = $item['id'] ?? null;
                $name = $item['name'] ?? null;
                if (!$id || !is_string($name) || $name === '') {
                    continue;
                }

                // UPDATE violations 角色名快照（保留 LIKE 'Unknown%' 限制避免覆盖已正常名字）
                $a = DB::table('seat_audit_violations')
                    ->where('character_id', $id)
                    ->where('character_name', 'LIKE', 'Unknown (ID:%')
                    ->update(['character_name' => $name]);
                $b = DB::table('seat_audit_violations')
                    ->where('counterparty_id', $id)
                    ->where('counterparty_name', 'LIKE', 'Unknown (ID:%')
                    ->update(['counterparty_name' => $name]);

                if ($a > 0 || $b > 0) {
                    $resolvedNameCount++;
                }

                // 把名字 UPSERT 到 universe_names（SeAT 共用名字缓存）
                $this->upsertUniverseName($id, $name, 'character');
            }
        }

        // ============ 步骤 4：批量解析 affiliation（POST /characters/affiliation/） ============
        // 拿到 char → corp_id 映射，UPSERT 到 character_affiliations；同时收集所有 corp_id 供下一步解析名字
        $resolvedAffCount = 0;
        $failedAffBatch = 0;
        $allCorpIds = [];

        foreach (array_chunk($allCharactersToResolve, self::ESI_BATCH_SIZE) as $batch) {
            $data = $this->postEsi(self::ESI_AFFILIATION_ENDPOINT, array_values($batch), 'affiliation');

            if ($data === null) {
                $failedAffBatch++;
                continue;
            }

            foreach ($data as $item) {
                $characterId = $item['character_id'] ?? null;
                $corpId      = $item['corporation_id'] ?? null;
                $allianceId  = $item['alliance_id'] ?? null;
                $factionId   = $item['faction_id'] ?? null;

                if (!$characterId || !$corpId) {
                    continue;
                }

                // UPSERT character_affiliations
                // 不动现有行的 updated_at（避免覆盖 SeAT 自己更新的时间戳）：仅在不存在时插入
                $exists = DB::table('character_affiliations')
                    ->where('character_id', $characterId)
                    ->exists();

                if (!$exists) {
                    DB::table('character_affiliations')->insert([
                        'character_id'   => $characterId,
                        'corporation_id' => $corpId,
                        'alliance_id'    => $allianceId,
                        'faction_id'     => $factionId,
                        'created_at'     => now(),
                        'updated_at'     => now(),
                    ]);
                    $resolvedAffCount++;
                }

                $allCorpIds[] = (int) $corpId;
                if ($allianceId) {
                    $allCorpIds[] = (int) $allianceId; // 联盟也一起解析名字，未来 UI 可能用到
                }
            }
        }

        $allCorpIds = array_values(array_unique($allCorpIds));

        // ============ 步骤 4.5：补全 — 收集所有合同涉及角色的 corp_id（即使 affiliation 早已存在） ============
        // 修复 bug：SeAT 自身可能早就同步过外部角色的 affiliation（如 cgwang SkyCity 在 2026-02 同步过），
        // 此时步骤 4 跳过这些角色 → 他们所属的外部 corp_id 不进 $allCorpIds → 步骤 5 不会去解析这些 corp 的名字。
        // 这里直接从 character_affiliations 拉本批所有合同参与者的当前 corp_id 补回来。
        if (!empty($contractParticipantIds)) {
            $allExistingCorpIds = DB::table('character_affiliations')
                ->whereIn('character_id', $contractParticipantIds)
                ->pluck('corporation_id')
                ->toArray();
            $allCorpIds = array_values(array_unique(array_merge($allCorpIds, $allExistingCorpIds)));
        }

        // ============ 步骤 5：批量解析军团/联盟名字（POST /universe/names/） ============
        // 排除 universe_names 已有 + corporation_infos 已有（SeAT 内部 corp），减少不必要的 ESI 调用
        $existingCorpInUniverseNames = empty($allCorpIds)
            ? []
            : DB::table('universe_names')
                ->whereIn('entity_id', $allCorpIds)
                ->whereIn('category', ['corporation', 'alliance'])
                ->pluck('entity_id')
                ->toArray();

        $existingCorpInInfos = empty($allCorpIds)
            ? []
            : DB::table('corporation_infos')
                ->whereIn('corporation_id', $allCorpIds)
                ->pluck('corporation_id')
                ->toArray();

        $corpsNeedingName = array_values(array_diff(
            $allCorpIds,
            $existingCorpInUniverseNames,
            $existingCorpInInfos
        ));
        $resolvedCorpCount = 0;
        $failedCorpBatch = 0;

        foreach (array_chunk($corpsNeedingName, self::ESI_BATCH_SIZE) as $batch) {
            $data = $this->postEsi(self::ESI_NAMES_ENDPOINT, array_values($batch), 'corp-names');

            if ($data === null) {
                $failedCorpBatch++;
                continue;
            }

            foreach ($data as $item) {
                $category = $item['category'] ?? null;
                if (!in_array($category, ['corporation', 'alliance'], true)) {
                    continue;
                }
                $id = $item['id'] ?? null;
                $name = $item['name'] ?? null;
                if (!$id || !is_string($name) || $name === '') {
                    continue;
                }

                $this->upsertUniverseName($id, $name, $category);
                $resolvedCorpCount++;
            }
        }

        Log::info(
            self::LOG_PREFIX . ' 解析完成。角色名 ' . $resolvedNameCount
            . '；新增 affiliation ' . $resolvedAffCount
            . '；军团/联盟名字 ' . $resolvedCorpCount
            . '；失败批次 names=' . $failedNameBatch
            . ' affiliation=' . $failedAffBatch
            . ' corp-names=' . $failedCorpBatch
        );
    }

    /**
     * 统一调用 ESI POST 接口；失败返回 null，调用方按整批跳过
     */
    private function postEsi(string $endpoint, array $payload, string $tag): ?array
    {
        if (empty($payload)) {
            return [];
        }

        try {
            $response = Http::timeout(30)
                ->acceptJson()
                ->asJson()
                ->post($endpoint, array_values($payload));
        } catch (\Throwable $e) {
            Log::warning(self::LOG_PREFIX . ' [' . $tag . '] HTTP 异常：' . $e->getMessage());
            return null;
        }

        if (!$response->successful()) {
            Log::warning(
                self::LOG_PREFIX . ' [' . $tag . '] 状态 ' . $response->status()
                . '，body：' . mb_substr((string) $response->body(), 0, 500)
            );
            return null;
        }

        $data = $response->json();
        if (!is_array($data)) {
            Log::warning(self::LOG_PREFIX . ' [' . $tag . '] 响应不是数组');
            return null;
        }

        return $data;
    }

    /**
     * UPSERT 一条 universe_names 行。
     * 已存在的不动（避免影响 SeAT 自身更新轨迹），仅 INSERT 新行。
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
            'entity_id'  => $entityId,
            'name'       => $name,
            'category'   => $category,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
