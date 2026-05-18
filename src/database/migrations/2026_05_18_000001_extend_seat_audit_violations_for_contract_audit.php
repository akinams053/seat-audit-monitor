<?php

// /Users/akina/project/seat-audit-monitor/src/database/migrations/2026_05_18_000001_extend_seat_audit_violations_for_contract_audit.php
// 为合同审计扩展违规记录表，增加审计来源和合同 ID 快照字段

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class ExtendSeatAuditViolationsForContractAudit extends Migration
{
    public function up()
    {
        Schema::table('seat_audit_violations', function (Blueprint $table) {
            // 审计来源类型：保留历史钱包交易记录默认值，并让合同审计写入 contracts
            $table->string('audit_type', 50)
                ->default('wallet_transactions')
                ->index();

            // 合同 ID 快照：钱包交易违规为空，合同违规用于后续 UI 和追溯定位
            $table->unsignedBigInteger('contract_id')
                ->nullable()
                ->index();
        });
    }

    public function down()
    {
        Schema::table('seat_audit_violations', function (Blueprint $table) {
            // MariaDB 环境下使用字段数组删除索引，避免依赖 Laravel 自动生成索引名差异
            $table->dropIndex(['audit_type']);
            $table->dropIndex(['contract_id']);
            $table->dropColumn(['audit_type', 'contract_id']);
        });
    }
}
