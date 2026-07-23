<?php

// src/database/migrations/2026_07_23_000003_create_seat_audit_scan_cursors_table.php
// 创建统一扫描游标表：按扫描器和受审军团分别保存进度，支持时间游标、主 ID 和次级 ID

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSeatAuditScanCursorsTable extends Migration
{
    public function up()
    {
        Schema::create('seat_audit_scan_cursors', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('scanner', 64);

            // 0 表示旧钱包/旧合同等全局扫描范围；新成员策略使用真实军团 ID。
            // 不设置外键，因为全局 scope=0 并不对应 seat_audit_corporations 中的实体行。
            $table->unsignedBigInteger('audit_corporation_id')->default(0);

            // cursor_at 负责按源表摄取/更新时间推进，两个整数游标用于同秒稳定排序和复合主键消歧。
            $table->dateTime('cursor_at')->nullable();
            $table->unsignedBigInteger('cursor_id')->default(0);
            $table->unsignedBigInteger('cursor_sub_id')->default(0);

            // 分开记录开始和成功时间，便于识别长期失败、卡住或从未成功的扫描器。
            $table->dateTime('last_started_at')->nullable();
            $table->dateTime('last_succeeded_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['scanner', 'audit_corporation_id'],
                'seat_audit_cursor_scanner_corp_unique'
            );
        });
    }

    public function down()
    {
        Schema::dropIfExists('seat_audit_scan_cursors');
    }
}
