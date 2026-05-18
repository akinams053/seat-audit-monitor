<?php

// src/Models/CorporationWhitelist.php
// 军团白名单模型 — 与 Whitelist（角色白名单）独立维护

namespace Seat\SeatAuditMonitor\Models;

use Seat\Services\Models\ExtensibleModel;

class CorporationWhitelist extends ExtensibleModel
{
    protected $table = 'seat_audit_corporation_whitelist';

    // 允许批量赋值的字段
    protected $fillable = ['corporation_id', 'corporation_name'];
}
