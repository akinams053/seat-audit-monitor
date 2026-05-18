<?php

// src/Jobs/ResolveUnknownNamesJob.php
// 批量从 ESI 公开接口 POST /universe/names/ 解析「外部 character_id」的角色名
// 适用场景：违规记录里发起方/接收方显示 'Unknown (ID: X)'（即 character_infos 表里没有这个角色，
// 一般是未在 SeAT 授权过 ESI 的外部玩家），点 UI 按钮触发本 Job 后批量解析后写回。

namespace Seat\SeatAuditMonitor\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ResolveUnknownNamesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * ESI /universe/names/ 单次接受的 ID 上限是 1000
     */
    const ESI_BATCH_SIZE = 1000;

    /**
     * ESI 公开接口（无需 token）
     */
    const ESI_ENDPOINT = 'https://esi.evetech.net/latest/universe/names/';

    /**
     * 写到日志时统一前缀，便于排查
     */
    const LOG_PREFIX = '[seat-audit:resolve-unknown]';

    public function handle()
    {
        // 步骤1：收集所有 character_name 仍是 'Unknown (ID:...)' 行的 character_id
        $charIds = DB::table('seat_audit_violations')
            ->where('character_name', 'LIKE', 'Unknown (ID:%')
            ->whereNotNull('character_id')
            ->pluck('character_id')
            ->toArray();

        // 步骤2：同样收集 counterparty_name 仍是 'Unknown (ID:...)' 行的 counterparty_id
        // 钱包行 counterparty_id 为 NULL 不会出现在这里
        $counterpartyIds = DB::table('seat_audit_violations')
            ->where('counterparty_name', 'LIKE', 'Unknown (ID:%')
            ->whereNotNull('counterparty_id')
            ->pluck('counterparty_id')
            ->toArray();

        // 合并、去重、过滤掉 0
        $allIds = array_values(array_unique(array_filter(
            array_merge($charIds, $counterpartyIds),
            fn ($id) => $id > 0
        )));

        if (empty($allIds)) {
            Log::info(self::LOG_PREFIX . ' 无需解析的 ID，提前退出。');
            return;
        }

        Log::info(self::LOG_PREFIX . ' 待解析 ID 总数：' . count($allIds));

        // 步骤3：分批请求 ESI。整批失败时跳过该批（下次重跑会再试），符合「跳过不更新」决策
        $resolvedCount = 0;
        $failedBatchCount = 0;

        foreach (array_chunk($allIds, self::ESI_BATCH_SIZE) as $batchIndex => $batch) {
            try {
                $response = Http::timeout(30)
                    ->acceptJson()
                    ->asJson()
                    ->post(self::ESI_ENDPOINT, array_values($batch));
            } catch (\Throwable $e) {
                Log::warning(self::LOG_PREFIX . ' 批次 ' . $batchIndex . ' HTTP 异常：' . $e->getMessage());
                $failedBatchCount++;
                continue;
            }

            // ESI 对包含「不存在的 ID」的整批可能返回 404，按决策整批跳过下次再试
            if (!$response->successful()) {
                Log::warning(
                    self::LOG_PREFIX . ' 批次 ' . $batchIndex . ' 非成功状态 ' . $response->status()
                    . '，body：' . mb_substr((string) $response->body(), 0, 500)
                );
                $failedBatchCount++;
                continue;
            }

            $data = $response->json();

            if (!is_array($data)) {
                Log::warning(self::LOG_PREFIX . ' 批次 ' . $batchIndex . ' 响应不是数组');
                $failedBatchCount++;
                continue;
            }

            // 步骤4：仅取 category=character 的解析结果写回。
            // 这层过滤防止 corp/alliance 名字误填进角色名列（虽然历史上 violation 的 character_id 都应是 character）。
            foreach ($data as $item) {
                if (($item['category'] ?? null) !== 'character') {
                    continue;
                }

                $id = $item['id'] ?? null;
                $name = $item['name'] ?? null;

                if (!$id || !is_string($name) || $name === '') {
                    continue;
                }

                // WHERE 仍包含 LIKE 'Unknown%' 是双保险：避免覆盖已经被解析过或新扫描已知的正常名字
                $affectedChr = DB::table('seat_audit_violations')
                    ->where('character_id', $id)
                    ->where('character_name', 'LIKE', 'Unknown (ID:%')
                    ->update(['character_name' => $name]);

                $affectedCtp = DB::table('seat_audit_violations')
                    ->where('counterparty_id', $id)
                    ->where('counterparty_name', 'LIKE', 'Unknown (ID:%')
                    ->update(['counterparty_name' => $name]);

                if ($affectedChr > 0 || $affectedCtp > 0) {
                    $resolvedCount++;
                }
            }
        }

        Log::info(
            self::LOG_PREFIX . ' 解析完成。成功更新 ' . $resolvedCount . ' 个角色名'
            . '，失败批次 ' . $failedBatchCount . ' 个。'
        );
    }
}
