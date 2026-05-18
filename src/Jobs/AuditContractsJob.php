<?php

// /Users/akina/project/seat-audit-monitor/src/Jobs/AuditContractsJob.php
// 核心增量审计 Job，扫描已完成合同记录，检测包含监控物品的违规合同

namespace Seat\SeatAuditMonitor\Jobs;

use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Seat\SeatAuditMonitor\Models\AuditStatus;

class AuditContractsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * 审计类型标识，用于区分合同违规记录和钱包交易违规记录。
     */
    const AUDIT_TYPE = 'contracts';

    /**
     * 每批处理的合同数量，避免一次性加载过多合同和物品明细。
     */
    const CHUNK_SIZE = 500;

    public function handle()
    {
        // 步骤1：读取合同审计的完成时间水位线。
        // 首次运行时数据库中没有 last_completed_at，因此使用 Unix 纪元作为全量扫描基线。
        // 用 ?? 而非 ?:：仅在 null 时兜底，避免误把 Carbon 对象当 falsy 处理（语义更精确）
        $lastCompletedAt = AuditStatus::getLastCompletedAt(self::AUDIT_TYPE)
            ?? Carbon::createFromTimestamp(0);

        // 步骤2：预加载白名单角色 ID，并翻转为哈希表。
        // 合同审计要求 issuer / assignee / acceptor 任意一方命中白名单就跳过整份合同。
        $whitelistIds = array_flip(
            DB::table('seat_audit_whitelist')
                ->pluck('character_id')
                ->toArray()
        );

        // 步骤3：预加载监控物品映射（type_id => item_name）。
        // 后续在 contract_items 中只拉取命中这些 type_id 的物品，降低扫描开销。
        $monitoredTypeIds = DB::table('seat_audit_monitor_items')
            ->pluck('item_name', 'type_id')
            ->toArray();

        // 没有配置监控物品时，合同不可能形成违规，直接退出且不推进水位线。
        if (empty($monitoredTypeIds)) {
            return;
        }

        // 步骤4：预加载角色名称快照。
        // details.parties 需要记录三方角色名，缺失时使用 Unknown (ID: X) 兜底。
        $characterNames = DB::table('character_infos')
            ->pluck('name', 'character_id')
            ->toArray();

        // 记录本次扫描处理到的最大完成时间，扫描结束后统一回写水位线。
        $maxCompletedAt = $lastCompletedAt;

        // 步骤5：按 date_completed 增量扫描已完成的物品交换和拍卖合同。
        // 查询条件只包含 status=finished，避免 outstanding 后续取消导致的复杂回退问题。
        DB::table('contract_details')
            ->where('status', 'finished')
            ->whereIn('type', ['item_exchange', 'auction'])
            ->where('date_completed', '>', $lastCompletedAt->toDateTimeString())
            ->whereNotNull('date_completed')
            ->orderBy('date_completed', 'asc')
            ->orderBy('contract_id', 'asc')
            ->chunk(self::CHUNK_SIZE, function ($contracts) use (
                $whitelistIds,
                $monitoredTypeIds,
                $characterNames,
                &$maxCompletedAt
            ) {
                // 步骤6a：收集当前 chunk 的合同 ID，用于一次性查询物品明细。
                $contractIds = $contracts->pluck('contract_id')->toArray();

                if (empty($contractIds)) {
                    return;
                }

                // 步骤6b：只拉取本批合同中命中监控 type_id 的物品行。
                // 这里在 SQL 层完成 contract_id 和 type_id 双重过滤，避免把无关明细搬进 PHP。
                $items = DB::table('contract_items')
                    ->whereIn('contract_id', $contractIds)
                    ->whereIn('type_id', array_keys($monitoredTypeIds))
                    ->get();

                // 将物品行按 contract_id 分组，并在同一合同内按 type_id 聚合数量。
                // 产品口径：一个 (contract_id, type_id) 一条违规。但 EVE 合同中同一 type_id 可能
                // 出现多个 record（典型场景：is_singleton 装配舰船每艘单独成行），需把 quantity
                // 加总后落到 details.item.quantity，避免审计追溯时丢失全量信息。
                // 同时记录 record_ids 列表，便于回查原始明细。
                $itemsByContract = [];

                foreach ($items as $item) {
                    $bucket = $itemsByContract[$item->contract_id][$item->type_id] ?? null;

                    if ($bucket === null) {
                        // 首次出现：拷贝物品行作为快照，把 quantity 转 int 便于后续累加
                        $itemsByContract[$item->contract_id][$item->type_id] = (object) [
                            'record_id'   => $item->record_id,
                            'type_id'     => $item->type_id,
                            'quantity'    => (int) $item->quantity,
                            'is_included' => $item->is_included,
                            'record_ids'  => [$item->record_id],
                        ];
                        continue;
                    }

                    // 后续同 type_id 的 record：累加数量并追加 record_id
                    $bucket->quantity += (int) $item->quantity;
                    $bucket->record_ids[] = $item->record_id;
                }

                // 本批次待插入的违规记录集合；一个 (contract_id, type_id) 对应一条违规。
                $violations = [];

                foreach ($contracts as $contract) {
                    // date_completed 从 DB::table() 读取时通常是字符串，统一 parse 成 Carbon 再比较和回写。
                    $completedAt = Carbon::parse($contract->date_completed);

                    if ($completedAt->gt($maxCompletedAt)) {
                        $maxCompletedAt = $completedAt;
                    }

                    // 过滤步骤①：三方白名单拦截。
                    // 任意一方在白名单中，整份合同跳过，不再检查物品是否命中监控名单。
                    if (
                        isset($whitelistIds[$contract->issuer_id])
                        || isset($whitelistIds[$contract->assignee_id])
                        || isset($whitelistIds[$contract->acceptor_id])
                    ) {
                        continue;
                    }

                    // 过滤步骤②：若该合同没有任何命中监控 type_id 的物品，则不是违规合同。
                    $matchedItems = $itemsByContract[$contract->contract_id] ?? [];

                    if (empty($matchedItems)) {
                        continue;
                    }

                    // 合同金额按产品决策取 price / reward 二者较大值，null 按 0 处理。
                    $amount = max((float) $contract->price, (float) $contract->reward);

                    // parties 快照保存三方名称，避免后续 character_infos 变化影响历史审计记录。
                    $issuerName = $characterNames[$contract->issuer_id]
                        ?? 'Unknown (ID: ' . $contract->issuer_id . ')';
                    $assigneeName = $characterNames[$contract->assignee_id]
                        ?? 'Unknown (ID: ' . $contract->assignee_id . ')';
                    $acceptorName = $characterNames[$contract->acceptor_id]
                        ?? 'Unknown (ID: ' . $contract->acceptor_id . ')';

                    foreach ($matchedItems as $item) {
                        // 违规粒度为一个 (contract_id, type_id) 一条记录。
                        // 若同一合同包含多种监控物品，将分别落库，便于后续按物品筛选和统计。
                        $violations[] = [
                            'character_id'   => $contract->issuer_id,
                            'character_name' => $issuerName,
                            'type_id'        => $item->type_id,
                            'item_name'      => $monitoredTypeIds[$item->type_id],
                            'amount'         => $amount,
                            'violation_time' => $completedAt->toDateTimeString(),
                            'details'        => json_encode([
                                'contract' => [
                                    'contract_id'    => $contract->contract_id,
                                    'type'           => $contract->type,
                                    'status'         => $contract->status,
                                    'issuer_id'      => $contract->issuer_id,
                                    'assignee_id'    => $contract->assignee_id,
                                    'acceptor_id'    => $contract->acceptor_id,
                                    'price'          => $contract->price,
                                    'reward'         => $contract->reward,
                                    'date_issued'    => $contract->date_issued,
                                    'date_completed' => $contract->date_completed,
                                    'title'          => $contract->title,
                                ],
                                'item' => [
                                    // 同 type_id 多 record 已聚合：quantity 是合计，record_ids 列出所有原始明细
                                    'record_id'   => $item->record_id,
                                    'record_ids'  => $item->record_ids,
                                    'type_id'     => $item->type_id,
                                    'quantity'    => $item->quantity,
                                    'is_included' => $item->is_included,
                                ],
                                'parties' => [
                                    'issuer_name'   => $issuerName,
                                    'assignee_name' => $assigneeName,
                                    'acceptor_name' => $acceptorName,
                                ],
                            ]),
                            'audit_type'     => self::AUDIT_TYPE,
                            'contract_id'    => $contract->contract_id,
                            'created_at'     => now()->toDateTimeString(),
                        ];
                    }
                }

                // 本批次有违规记录才批量写入，减少数据库往返次数。
                if (!empty($violations)) {
                    DB::table('seat_audit_violations')->insert($violations);
                }
            });

        // 步骤7：扫描完成后推进 date_completed 水位线。
        // 只有确实处理到更晚的合同时才更新，避免空跑覆盖原有进度。
        if ($maxCompletedAt->gt($lastCompletedAt)) {
            AuditStatus::setLastCompletedAt(self::AUDIT_TYPE, $maxCompletedAt);
        }
    }
}
