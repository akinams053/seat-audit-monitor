<?php

// src/database/migrations/2026_05_19_000001_extend_seat_audit_violations_for_counterparty.php
// 为违规记录表扩展「接收方」快照字段
// - 合同审计：counterparty_id = contract.acceptor_id，counterparty_name = acceptor 角色名
// - 钱包审计：counterparty_id NULL，counterparty_name = '市场'
// 该列同时用于查询层白名单软过滤：和 character_id 一起 LEFT JOIN seat_audit_whitelist 实时排除

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class ExtendSeatAuditViolationsForCounterparty extends Migration
{
    public function up()
    {
        Schema::table('seat_audit_violations', function (Blueprint $table) {
            // 接收方角色 ID 快照
            // - 合同：写入 contract.acceptor_id
            // - 钱包：保持 NULL（市场没有 character_id）
            $table->unsignedBigInteger('counterparty_id')
                ->nullable()
                ->after('character_name');

            // 接收方角色名快照
            // - 合同：character_infos 中 acceptor 的名字
            // - 钱包：固定写入 '市场'
            $table->string('counterparty_name')
                ->nullable()
                ->after('counterparty_id');

            // 索引：用于查询层 LEFT JOIN seat_audit_whitelist 做软过滤
            $table->index('counterparty_id');
        });
    }

    public function down()
    {
        Schema::table('seat_audit_violations', function (Blueprint $table) {
            // MariaDB 兼容：先 drop 索引再 drop 列，并用字段数组避免索引名差异
            $table->dropIndex(['counterparty_id']);
            $table->dropColumn(['counterparty_id', 'counterparty_name']);
        });
    }
}
