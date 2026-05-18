<?php

// src/database/migrations/2026_05_20_000001_extend_seat_audit_violations_for_contract_availability.php
// 为违规记录表扩展合同 availability 快照字段
// 用于在 UI「来源」列细分公开 / 私人 / corp / alliance 合同
// 钱包行该字段保持 NULL；合同行写入 contract_details.availability 的值

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class ExtendSeatAuditViolationsForContractAvailability extends Migration
{
    public function up()
    {
        Schema::table('seat_audit_violations', function (Blueprint $table) {
            // 合同可见性快照：public / personal / corporation / alliance（参考 ESI contract types）
            // 钱包行为 NULL，UI 上 badge 退化为「钱包」单一类型
            // 加索引便于未来按 availability 筛选/统计
            $table->string('contract_availability', 20)
                ->nullable()
                ->after('contract_id')
                ->index();
        });
    }

    public function down()
    {
        Schema::table('seat_audit_violations', function (Blueprint $table) {
            $table->dropIndex(['contract_availability']);
            $table->dropColumn('contract_availability');
        });
    }
}
