<?php

// D:\VS Code\Project test\seat-audit-monitor\src\database\migrations\2026_03_03_000002_create_seat_audit_whitelist_table.php
// 豁免白名单表迁移文件

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSeatAuditWhitelistTable extends Migration
{
    public function up()
    {
        Schema::create('seat_audit_whitelist', function (Blueprint $table) {
            $table->bigIncrements('id');
            // 豁免角色 ID，唯一约束防止重复添加
            $table->unsignedBigInteger('character_id')->unique();
            // 角色名称，用于人工识别，快照存储
            $table->string('character_name');
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('seat_audit_whitelist');
    }
}
