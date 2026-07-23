<?php

// src/database/migrations/2026_07_23_000006_add_source_event_key_unique_index_to_seat_audit_violations.php
// 历史回填完成后，把普通查询索引升级为唯一索引，阻止重试、回扫和并发产生新重复

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddSourceEventKeyUniqueIndexToSeatAuditViolations extends Migration
{
    public function up()
    {
        // 防御性检查：若回填逻辑或人工数据留下非 NULL 重复键，必须停止迁移而不是静默删除审计记录。
        $hasDuplicates = DB::table('seat_audit_violations')
            ->whereNotNull('source_event_key')
            ->select('source_event_key')
            ->groupBy('source_event_key')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($hasDuplicates) {
            throw new RuntimeException('seat_audit_violations 存在重复 source_event_key，已停止创建唯一索引。');
        }

        Schema::table('seat_audit_violations', function (Blueprint $table) {
            $table->dropIndex('idx_audit_source_key_lookup');
            // MySQL/MariaDB 允许唯一索引中存在多个 NULL，因此无法解析的旧行和历史重复副本可以继续保留。
            $table->unique('source_event_key', 'seat_audit_violations_source_key_unique');
        });
    }

    public function down()
    {
        Schema::table('seat_audit_violations', function (Blueprint $table) {
            $table->dropUnique('seat_audit_violations_source_key_unique');
            $table->index('source_event_key', 'idx_audit_source_key_lookup');
        });
    }
}
