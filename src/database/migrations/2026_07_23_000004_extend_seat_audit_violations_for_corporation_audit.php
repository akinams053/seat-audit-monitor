<?php

// src/database/migrations/2026_07_23_000004_extend_seat_audit_violations_for_corporation_audit.php
// 扩展违规快照表，使其可同时表达旧物品审计和新军团成员对外经济事件

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class ExtendSeatAuditViolationsForCorporationAudit extends Migration
{
    public function up()
    {
        Schema::table('seat_audit_violations', function (Blueprint $table) {
            // SHA-256 幂等键先建立普通索引；历史数据回填完成后由后续 migration 升级为唯一索引。
            $table->char('source_event_key', 64)->nullable()->after('id');
            $table->string('source_reference')->nullable()->after('source_event_key');

            // 历史旧策略不归属于某个受审军团，因此这些新字段全部允许 NULL。
            $table->unsignedBigInteger('audit_corporation_id')->nullable()->after('audit_type');
            $table->unsignedBigInteger('member_character_id')->nullable()->after('audit_corporation_id');
            $table->unsignedBigInteger('external_party_id')->nullable()->after('member_character_id');
            $table->string('external_party_type', 32)->nullable()->after('external_party_id');
            $table->string('direction', 32)->nullable()->after('external_party_type');

            // 保存扫描发生时的双方军团快照；页面可继续显示当前 affiliation，但审计详情仍能追溯当时判定依据。
            $table->unsignedBigInteger('character_corporation_id')->nullable()->after('direction');
            $table->unsignedBigInteger('counterparty_corporation_id')->nullable()->after('character_corporation_id');

            $table->index('source_event_key', 'idx_audit_source_key_lookup');
            $table->index(['audit_type', 'violation_time'], 'idx_audit_type_time');
            $table->index(
                ['audit_corporation_id', 'audit_type', 'violation_time'],
                'idx_audit_corp_type_time'
            );
            $table->index('member_character_id', 'idx_audit_member_character');
            $table->index('external_party_id', 'idx_audit_external_party');
        });

        Schema::table('seat_audit_violations', function (Blueprint $table) {
            // ISK 捐赠没有物品；成员合同按整份合同记录，也不应伪造 type_id 或 item_name。
            $table->unsignedBigInteger('type_id')->nullable()->change();
            $table->string('item_name')->nullable()->change();
        });
    }

    public function down()
    {
        Schema::table('seat_audit_violations', function (Blueprint $table) {
            $table->dropIndex('idx_audit_source_key_lookup');
            $table->dropIndex('idx_audit_type_time');
            $table->dropIndex('idx_audit_corp_type_time');
            $table->dropIndex('idx_audit_member_character');
            $table->dropIndex('idx_audit_external_party');

            $table->dropColumn([
                'source_event_key',
                'source_reference',
                'audit_corporation_id',
                'member_character_id',
                'external_party_id',
                'external_party_type',
                'direction',
                'character_corporation_id',
                'counterparty_corporation_id',
            ]);
        });

        // type_id/item_name 的 nullable 放宽不在这里强制收紧：一旦新审计已经写入无物品事件，
        // 改回 NOT NULL 必然失败，或者只能用假数据污染历史。回滚代码和回滚业务数据必须分开处理。
    }
}
