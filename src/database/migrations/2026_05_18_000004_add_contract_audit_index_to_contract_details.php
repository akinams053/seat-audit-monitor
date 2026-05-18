<?php

// /Users/akina/project/seat-audit-monitor/src/database/migrations/2026_05_18_000004_add_contract_audit_index_to_contract_details.php
// 为 SeAT 核心表 contract_details 加复合索引，服务 AuditContractsJob 的增量扫描查询

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddContractAuditIndexToContractDetails extends Migration
{
    /**
     * 索引名加 idx_audit_ 前缀，明确标记来源是本插件，与 eveseat/eveapi 上游索引区分。
     */
    private const INDEX_NAME = 'idx_audit_contract_scan';

    public function up()
    {
        // 注意：contract_details 是 SeAT 核心包 (eveseat/eveapi) 维护的表，本插件在此追加索引
        // 是性能优化手段。复合顺序按 selectivity：
        //   status (10 值 ENUM，filter 一半) > type (5 值 ENUM) > date_completed (range)
        // AuditContractsJob 的 WHERE: status='finished' AND type IN ('item_exchange','auction')
        //   AND date_completed > ? → 此索引能将全表 scan 转为 range scan
        Schema::table('contract_details', function (Blueprint $table) {
            $table->index(['status', 'type', 'date_completed'], self::INDEX_NAME);
        });
    }

    public function down()
    {
        // rollback 时按命名删除，避免误删 SeAT 核心包自带索引
        Schema::table('contract_details', function (Blueprint $table) {
            $table->dropIndex(self::INDEX_NAME);
        });
    }
}
