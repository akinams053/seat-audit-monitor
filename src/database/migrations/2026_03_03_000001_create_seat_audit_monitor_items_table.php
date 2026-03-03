<?php

// D:\VS Code\Project test\seat-audit-monitor\src\database\migrations\2026_03_03_000001_create_seat_audit_monitor_items_table.php
// 监控物品列表表迁移文件

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSeatAuditMonitorItemsTable extends Migration
{
    public function up()
    {
        Schema::create('seat_audit_monitor_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            // Eve 物品 ID，唯一约束防止重复添加同一物品
            $table->unsignedBigInteger('type_id')->unique();
            // 物品名称，用于快照展示
            $table->string('item_name');
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('seat_audit_monitor_items');
    }
}
