<?php

// src/database/migrations/2026_05_20_000002_backfill_contract_availability_for_existing_violations.php
// 回填历史合同违规行的 availability 字段
// JOIN contract_details 一次性 UPDATE：seat_audit_violations 通过 contract_id 关联取 availability
// 钱包行（contract_id IS NULL）不参与

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class BackfillContractAvailabilityForExistingViolations extends Migration
{
    public function up()
    {
        // 一次性 UPDATE：MariaDB 支持 multi-table UPDATE，按 contract_id JOIN 拿 availability
        // 只更新还没回填过的行（contract_availability IS NULL）+ 合同类型行
        // 用原生 SQL 是因为 Eloquent/Builder 不直接支持 JOIN UPDATE
        DB::statement(<<<SQL
            UPDATE seat_audit_violations AS v
            INNER JOIN contract_details AS cd ON cd.contract_id = v.contract_id
            SET v.contract_availability = cd.availability
            WHERE v.audit_type = 'contracts'
              AND v.contract_id IS NOT NULL
              AND v.contract_availability IS NULL
        SQL);
    }

    public function down()
    {
        // 回填属于数据修复，down 不主动清空。完整撤销请配合回滚 2026_05_20_000001（dropColumn 带走数据）
    }
}
