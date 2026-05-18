<?php

// /Users/akina/project/seat-audit-monitor/src/database/migrations/2026_05_18_000002_extend_seat_audit_status_for_contracts.php
// 为合同审计扩展增量水位线，按合同完成时间推进扫描进度

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class ExtendSeatAuditStatusForContracts extends Migration
{
    public function up()
    {
        Schema::table('seat_audit_status', function (Blueprint $table) {
            // 合同审计使用 date_completed 作为增量基准，钱包交易仍继续使用 last_id
            $table->dateTime('last_completed_at')
                ->nullable();
        });
    }

    public function down()
    {
        Schema::table('seat_audit_status', function (Blueprint $table) {
            $table->dropColumn('last_completed_at');
        });
    }
}
