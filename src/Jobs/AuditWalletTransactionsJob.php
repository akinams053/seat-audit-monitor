<?php

// src/Jobs/AuditWalletTransactionsJob.php
// 核心增量审计 Job，扫描角色钱包交易记录，检测违规卖出行为

namespace Seat\SeatAuditMonitor\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Seat\SeatAuditMonitor\Models\AuditStatus;

class AuditWalletTransactionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * 审计类型标识，用于水位线的读写
     */
    const AUDIT_TYPE = 'wallet_transactions';

    /**
     * 每批处理的记录数量
     */
    const CHUNK_SIZE = 500;

    public function handle()
    {
        // 步骤1：从 seat_audit_status 读取上次扫描水位线
        // 若首次运行，getLastId 会自动初始化为 0
        $lastId = AuditStatus::getLastId(self::AUDIT_TYPE);

        // 步骤2：将白名单角色 ID 预加载到内存中（在 chunk 循环外执行，避免 N+1 查询）
        // 使用 array_flip 将数组值变为键，实现 O(1) 的 isset 查找
        $whitelistIds = array_flip(
            DB::table('seat_audit_whitelist')
                ->pluck('character_id')
                ->toArray()
        );

        // 步骤3：将监控物品名单预加载到内存中（type_id => item_name 映射）
        // 同样在 chunk 循环外执行，避免 N+1 查询
        $monitoredItems = DB::table('seat_audit_monitor_items')
            ->pluck('item_name', 'type_id')
            ->toArray();

        // 若监控名单为空，则无需扫描，直接退出
        if (empty($monitoredItems)) {
            return;
        }

        // 步骤4：预加载角色名映射（character_id => name）
        // character_wallet_transactions 表不含角色名，需从 character_infos 表获取
        $characterNames = DB::table('character_infos')
            ->pluck('name', 'character_id')
            ->toArray();

        // 记录本次扫描处理到的最大 ID，用于结束后更新水位线
        $maxProcessedId = $lastId;

        // 步骤4：增量扫描 character_wallet_transactions 表
        // 仅查询 id > last_id 的记录，按 id 升序保证水位线单调递增
        DB::table('character_wallet_transactions')
            ->where('id', '>', $lastId)
            ->orderBy('id', 'asc')
            ->chunk(self::CHUNK_SIZE, function ($records) use (
                $whitelistIds,
                $monitoredItems,
                $characterNames,
                &$maxProcessedId
            ) {
                // 本批次待插入的违规记录集合（批量插入以减少数据库往返次数）
                $violations = [];

                foreach ($records as $record) {
                    // 追踪本批次最大 ID，用于最终更新水位线
                    if ($record->id > $maxProcessedId) {
                        $maxProcessedId = $record->id;
                    }

                    // 过滤步骤①：白名单拦截（最高优先级）
                    // 若角色在白名单中，跳过该角色的所有审计逻辑
                    if (isset($whitelistIds[$record->character_id])) {
                        continue;
                    }

                    // 过滤步骤②：行为判定，仅审计卖出行为（is_buy === 0）
                    // is_buy = 1 表示买入，不在审计范围内
                    if ((int) $record->is_buy !== 0) {
                        continue;
                    }

                    // 过滤步骤③：物品匹配，检查 type_id 是否在监控名单内
                    // 只要命中即判定为违规，无需其他条件
                    if (!isset($monitoredItems[$record->type_id])) {
                        continue;
                    }

                    // 判定为违规，构建快照记录
                    // character_name 和 item_name 均采用快照存储，防止历史记录因名称变更而失真
                    $violations[] = [
                        'character_id'   => $record->character_id,
                        // 角色名快照：从预加载的 character_infos 映射中读取
                        'character_name' => isset($characterNames[$record->character_id])
                            ? $characterNames[$record->character_id]
                            : 'Unknown (ID: ' . $record->character_id . ')',
                        'type_id'        => $record->type_id,
                        // 物品名快照：从预加载的监控物品映射中读取
                        'item_name'      => $monitoredItems[$record->type_id],
                        // 总金额 = 单价 × 数量，EVE 经济数值可能极大
                        'amount'         => (float) $record->unit_price * (int) $record->quantity,
                        // 交易实际发生时间，来自原始记录的 date 字段
                        'violation_time' => $record->date,
                        // 将整条原始交易记录序列化为 JSON 存储，供审计追溯
                        'details'        => json_encode($record),
                        'created_at'     => now()->toDateTimeString(),
                    ];
                }

                // 本批次有违规记录则批量插入，减少数据库往返次数
                if (!empty($violations)) {
                    DB::table('seat_audit_violations')->insert($violations);
                }
            });

        // 步骤5：扫描完成后更新水位线，下次扫描将从此 ID 之后开始
        if ($maxProcessedId > $lastId) {
            AuditStatus::setLastId(self::AUDIT_TYPE, $maxProcessedId);
        }
    }
}
