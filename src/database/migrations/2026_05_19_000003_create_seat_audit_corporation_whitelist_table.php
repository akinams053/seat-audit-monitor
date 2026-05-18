<?php

// src/database/migrations/2026_05_19_000003_create_seat_audit_corporation_whitelist_table.php
// 军团白名单表 — 仅用于合同审计的拦截
// 合同审计逻辑：发起方军团（contract.issuer_corporation_id）或 接收方当前军团（character_affiliations）
// 任一在此表中即跳过整份合同
// 注：与现有 seat_audit_whitelist（角色白名单）独立维护，未来如扩展 alliance 再统筹

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSeatAuditCorporationWhitelistTable extends Migration
{
    public function up()
    {
        Schema::create('seat_audit_corporation_whitelist', function (Blueprint $table) {
            $table->bigIncrements('id');
            // 军团 ID — 不设外键，与 corporation_infos 通过应用层关联，避免影响主表迁移
            // unique 保证同一军团不重复加入
            $table->unsignedBigInteger('corporation_id')->unique();
            // 军团名快照，便于 UI 展示不依赖 JOIN
            $table->string('corporation_name');
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('seat_audit_corporation_whitelist');
    }
}
