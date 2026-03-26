<?php

// src/database/migrations/2026_03_03_000004_create_seat_audit_violations_table.php
// 违规记录表迁移文件（核心存储快照数据）

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSeatAuditViolationsTable extends Migration
{
    public function up()
    {
        Schema::create('seat_audit_violations', function (Blueprint $table) {
            $table->bigIncrements('id');
            // 违规角色 ID（不设外键，快照模式，防止原始数据删除后记录丢失）
            $table->unsignedBigInteger('character_id');
            // 角色名快照，不依赖关联查询
            $table->string('character_name');
            // 违规物品 ID（不设外键，快照模式）
            $table->unsignedBigInteger('type_id');
            // 物品名快照，不依赖关联查询
            $table->string('item_name');
            // 总金额 = 单价 × 数量，EVE 经济数值可能极大，使用 decimal(20,2) 保证精度
            $table->decimal('amount', 20, 2);
            // 交易实际发生时间（来自原始记录 date 字段）
            $table->timestamp('violation_time');
            // JSON 格式原始交易记录，用于审计追溯
            $table->json('details');
            // 仅记录创建时间，无需 updated_at
            $table->timestamp('created_at')->useCurrent();

            // 按角色 ID 建立索引，加速按角色筛选查询
            $table->index('character_id');
            // 按违规时间建立索引，加速时间范围筛选和排序
            $table->index('violation_time');
        });
    }

    public function down()
    {
        Schema::dropIfExists('seat_audit_violations');
    }
}
