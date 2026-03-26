<?php

// src/Models/Whitelist.php
// 豁免白名单模型

namespace Seat\SeatAuditMonitor\Models;

use Seat\Services\Models\ExtensibleModel;

class Whitelist extends ExtensibleModel
{
    protected $table = 'seat_audit_whitelist';

    // 允许批量赋值的字段
    protected $fillable = ['character_id', 'character_name'];
}
