<?php

// /Users/akina/project/seat-audit-monitor/src/database/migrations/2026_05_18_000003_seed_contracts_audit_baseline.php
// 预置合同审计的初始水位线，避免首次扫描时回扫到 1970 全量历史合同

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class SeedContractsAuditBaseline extends Migration
{
    /**
     * 合同审计基线时间：2026 年以前的合同体量大但业务价值低（多为联盟内部物资划转的历史快照），
     * 作为历史数据完全跳过，让首次扫描只覆盖业务上仍在跟踪的范围。
     */
    private const BASELINE = '2026-01-01 00:00:00';

    public function up()
    {
        // 用 exists 检查保证幂等：如果 contracts 水位线行已存在（例如手动 SQL 已建过、
        // 或本 migration 在某次 rollback/migrate 循环中已跑过），则不覆盖现有进度，
        // 避免水位线倒退导致重复扫描已处理过的合同。
        $exists = DB::table('seat_audit_status')
            ->where('audit_type', 'contracts')
            ->exists();

        if ($exists) {
            return;
        }

        DB::table('seat_audit_status')->insert([
            'audit_type'        => 'contracts',
            'last_id'           => 0,
            'last_completed_at' => self::BASELINE,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);
    }

    public function down()
    {
        // 故意 noop：基线属于业务数据预置而非纯 schema 变更。
        // 若 rollback 时删除此行，再次 migrate 时会重置水位线，已扫过的合同会被重复处理
        // （seat_audit_violations 表对 contract_id 无 unique 约束）。
        //
        // 若确需回扫历史合同，请手动执行：
        //   UPDATE seat_audit_status SET last_completed_at = '1970-01-01 00:00:00' WHERE audit_type = 'contracts';
    }
}
