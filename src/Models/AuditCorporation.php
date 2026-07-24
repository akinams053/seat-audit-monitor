<?php

// src/Models/AuditCorporation.php
// 受审军团配置模型：定义成员经济行为审查范围和功能启用时间

namespace Seat\SeatAuditMonitor\Models;

use Seat\Services\Models\ExtensibleModel;

class AuditCorporation extends ExtensibleModel
{
    // 当前插件仅审查这一支军团；所有 2.0 cursor、来源键和违规快照均使用此 EVE corporation ID。
    public const TARGET_CORPORATION_ID = 98588384;

    protected $table = 'seat_audit_corporations';

    protected $fillable = [
        'corporation_id',
        'corporation_name',
        'enabled',
        'audit_donations',
        'audit_contracts',
        'audit_from',
    ];

    protected $casts = [
        'enabled'         => 'boolean',
        'audit_donations' => 'boolean',
        'audit_contracts' => 'boolean',
        'audit_from'      => 'datetime',
    ];
}
