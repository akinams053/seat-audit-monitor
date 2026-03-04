<?php

// D:\VS Code\Project test\seat-audit-monitor\src\Models\Whitelist.php
// 豁免白名单模型

namespace Seat\SeatAuditMonitor\Models;

use Illuminate\Database\Eloquent\Model;

class Whitelist extends Model
{
    protected $table = 'seat_audit_whitelist';

    // 允许批量赋值的字段
    protected $fillable = ['character_id', 'character_name'];
}
