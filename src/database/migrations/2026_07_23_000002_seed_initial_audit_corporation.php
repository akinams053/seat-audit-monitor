<?php

// src/database/migrations/2026_07_23_000002_seed_initial_audit_corporation.php
// 初始化现有排查流程使用的军团 98588384；默认保持停用，待管理页面明确启用后再开始扫描

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SeedInitialAuditCorporation extends Migration
{
    private const INITIAL_CORPORATION_ID = 98588384;

    public function up()
    {
        // insertOrIgnore 保证升级或人工预先配置过该军团时，不覆盖管理员设置的名称、开关和起始时间。
        $corporationName = $this->resolveCorporationName();
        $now = now();

        DB::table('seat_audit_corporations')->insertOrIgnore([
            'corporation_id'   => self::INITIAL_CORPORATION_ID,
            'corporation_name' => $corporationName,
            'enabled'          => false,
            'audit_donations'  => true,
            'audit_contracts'  => true,
            // 默认停用，因此该时间只是安全基线；后续首次启用时管理逻辑会把 audit_from 更新为实际启用时间。
            'audit_from'       => $now->toDateTimeString(),
            'created_at'       => $now->toDateTimeString(),
            'updated_at'       => $now->toDateTimeString(),
        ]);
    }

    public function down()
    {
        // 这是租户审计范围配置，不在 migration 回滚时自动删除，避免误删管理员后续修改过的配置。
    }

    /**
     * 优先从 SeAT 已同步的军团资料取名称，再使用 universe_names 兜底。
     * 两个缓存表均没有命中时保留可识别的 ID 占位符，不能因为名称缺失阻断安装。
     */
    private function resolveCorporationName(): string
    {
        if (Schema::hasTable('corporation_infos')) {
            $name = DB::table('corporation_infos')
                ->where('corporation_id', self::INITIAL_CORPORATION_ID)
                ->value('name');

            if (is_string($name) && $name !== '') {
                return $name;
            }
        }

        if (Schema::hasTable('universe_names')) {
            $name = DB::table('universe_names')
                ->where('entity_id', self::INITIAL_CORPORATION_ID)
                ->where('category', 'corporation')
                ->value('name');

            if (is_string($name) && $name !== '') {
                return $name;
            }
        }

        return 'Corporation ' . self::INITIAL_CORPORATION_ID;
    }
}
