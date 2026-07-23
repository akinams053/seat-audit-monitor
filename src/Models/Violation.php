<?php

// /Users/akina/project/seat-audit-monitor/src/Models/Violation.php
// 违规记录模型

namespace Seat\SeatAuditMonitor\Models;

use Seat\Services\Models\ExtensibleModel;

class Violation extends ExtensibleModel
{
    protected $table = 'seat_audit_violations';

    // 仅有 created_at，无 updated_at
    const UPDATED_AT = null;

    // 允许批量赋值的字段（与表 schema 保持同步，兼容旧物品审计和新军团对外审计）
    protected $fillable = [
        'source_event_key',
        'source_reference',
        'character_id',
        'character_name',
        'counterparty_id',
        'counterparty_name',
        'type_id',
        'item_name',
        'amount',
        'violation_time',
        'details',
        'audit_type',
        'audit_corporation_id',
        'member_character_id',
        'external_party_id',
        'external_party_type',
        'direction',
        'character_corporation_id',
        'counterparty_corporation_id',
        'contract_id',
        'contract_availability',
    ];

    // 金额使用 decimal 字符串，避免 EVE 大额 ISK 转为 PHP float 后丢失精度；details 保持数组快照语义。
    protected $casts = [
        'amount'         => 'decimal:2',
        'violation_time' => 'datetime',
        'details'        => 'array',
    ];
}
