<?php

// src/database/migrations/2026_07_23_000001_create_seat_audit_corporations_table.php
// 创建受审军团配置表：它定义“哪些军团需要审查”，与现有军团白名单的豁免语义严格分离

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSeatAuditCorporationsTable extends Migration
{
    public function up()
    {
        Schema::create('seat_audit_corporations', function (Blueprint $table) {
            $table->bigIncrements('id');

            // 受审军团 ID。一个军团只能有一份配置，避免同一成员范围被重复扫描。
            $table->unsignedBigInteger('corporation_id')->unique();
            // 军团名称快照仅用于管理页面显示；成员资格仍以 corporation_members 为准。
            $table->string('corporation_name');

            // 停用时只关闭后续扫描，不删除该军团已经产生的历史违规记录。
            $table->boolean('enabled')->default(false);
            // 两类新策略可独立开关，便于逐步启用和单独排障。
            $table->boolean('audit_donations')->default(true);
            $table->boolean('audit_contracts')->default(true);

            // 新策略只处理该时间点之后发生的业务事件，防止当前成员名册误判历史入退团关系。
            $table->dateTime('audit_from');
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('seat_audit_corporations');
    }
}
