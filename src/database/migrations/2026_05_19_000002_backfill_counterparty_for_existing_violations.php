<?php

// src/database/migrations/2026_05_19_000002_backfill_counterparty_for_existing_violations.php
// 回填历史违规记录的「接收方」快照字段
// - 钱包历史行：counterparty_name 一次性写入 '市场'（counterparty_id 保持 NULL）
// - 合同历史行：按 id chunk 处理，从 details JSON 提取 contract.acceptor_id 和 parties.acceptor_name
// 仅处理本次扩展后两列均为 NULL 的行，避免重复执行覆盖新数据

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class BackfillCounterpartyForExistingViolations extends Migration
{
    /**
     * 合同 backfill 的批处理大小，避免一次性把全部历史合同 violation 加载到内存
     */
    const CHUNK_SIZE = 500;

    public function up()
    {
        // 步骤1：钱包交易历史行整批回填，对方固定为「市场」
        // 双 whereNull 保证只处理本次扩展前的旧行；新版本 Job 写入的行会显式带 counterparty_name='市场'
        DB::table('seat_audit_violations')
            ->where('audit_type', 'wallet_transactions')
            ->whereNull('counterparty_id')
            ->whereNull('counterparty_name')
            ->update([
                'counterparty_name' => '市场',
            ]);

        // 步骤2：合同历史按主键 chunk 处理。chunkById 内部默认按 id 推进游标，UPDATE 不影响遍历完整性
        // 仅遍历尚未回填的行；同时跳过 details 反序列化失败的异常数据
        DB::table('seat_audit_violations')
            ->where('audit_type', 'contracts')
            ->whereNull('counterparty_id')
            ->whereNull('counterparty_name')
            ->chunkById(self::CHUNK_SIZE, function ($rows) {
                foreach ($rows as $row) {
                    // details 列在表层声明为 JSON：从 DB::table 拿出来通常是字符串，统一 decode 成数组
                    $details = is_string($row->details)
                        ? json_decode($row->details, true)
                        : (array) $row->details;

                    if (!is_array($details)) {
                        continue;
                    }

                    // 接收方 ID 从 details.contract.acceptor_id 取，名字从 details.parties.acceptor_name 取
                    // 任一字段缺失时保持 NULL，UI 渲染层会兜底为占位符
                    $acceptorId = $details['contract']['acceptor_id'] ?? null;
                    $acceptorName = $details['parties']['acceptor_name'] ?? null;

                    DB::table('seat_audit_violations')
                        ->where('id', $row->id)
                        ->update([
                            'counterparty_id'   => $acceptorId,
                            'counterparty_name' => $acceptorName,
                        ]);
                }
            });
    }

    public function down()
    {
        // 回填属于数据修复性质，单独回滚此 migration 不主动清空已回填的列
        // 完整撤销请配合回滚 2026_05_19_000001（dropColumn 会带走两列数据）
    }
}
