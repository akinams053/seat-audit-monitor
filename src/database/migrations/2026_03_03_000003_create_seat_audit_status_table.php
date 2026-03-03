<?php

// D:\VS Code\Project test\seat-audit-monitor\src\database\migrations\2026_03_03_000003_create_seat_audit_status_table.php
// 增量扫描水位线表迁移文件

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSeatAuditStatusTable extends Migration
{
    public function up()
    {
        Schema::create('seat_audit_status', function (Blueprint $table) {
            $table->bigIncrements('id');
            // 审计类型标识，如 'wallet_transactions'，唯一约束确保每种类型只有一条水位线记录
            $table->string('audit_type')->unique();
            // 上次扫描到的最大记录 ID，下次扫描从此 ID 之后开始（增量逻辑核心）
            $table->unsignedBigInteger('last_id')->default(0);
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('seat_audit_status');
    }
}
