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

    // 允许批量赋值的字段（与表 schema 保持同步，便于未来通过 Eloquent 写入）
    protected $fillable = [
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
        'contract_id',
        'contract_availability',
    ];

    // details 字段自动序列化/反序列化为数组
    protected $casts = [
        'details' => 'array',
    ];
}
