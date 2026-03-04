<?php

// D:\VS Code\Project test\seat-audit-monitor\src\Models\Violation.php
// 违规记录模型

namespace Seat\SeatAuditMonitor\Models;

use Illuminate\Database\Eloquent\Model;

class Violation extends Model
{
    protected $table = 'seat_audit_violations';

    // 仅有 created_at，无 updated_at
    const UPDATED_AT = null;

    // 允许批量赋值的字段
    protected $fillable = [
        'character_id',
        'character_name',
        'type_id',
        'item_name',
        'amount',
        'violation_time',
        'details',
    ];

    // details 字段自动序列化/反序列化为数组
    protected $casts = [
        'details' => 'array',
    ];
}
