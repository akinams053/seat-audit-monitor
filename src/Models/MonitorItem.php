<?php

// D:\VS Code\Project test\seat-audit-monitor\src\Models\MonitorItem.php
// 监控物品列表模型

namespace Seat\SeatAuditMonitor\Models;

use Illuminate\Database\Eloquent\Model;

class MonitorItem extends Model
{
    protected $table = 'seat_audit_monitor_items';

    // 允许批量赋值的字段
    protected $fillable = ['type_id', 'item_name'];
}
