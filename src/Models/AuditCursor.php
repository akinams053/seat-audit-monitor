<?php

// src/Models/AuditCursor.php
// 统一扫描游标模型：每个 scanner + audit_corporation_id 对应一条独立进度记录

namespace Seat\SeatAuditMonitor\Models;

use Seat\Services\Models\ExtensibleModel;

class AuditCursor extends ExtensibleModel
{
    protected $table = 'seat_audit_scan_cursors';

    protected $fillable = [
        'scanner',
        'audit_corporation_id',
        'cursor_at',
        'cursor_id',
        'cursor_sub_id',
        'last_started_at',
        'last_succeeded_at',
    ];

    protected $casts = [
        'cursor_at'        => 'datetime',
        'last_started_at'   => 'datetime',
        'last_succeeded_at' => 'datetime',
    ];
}
