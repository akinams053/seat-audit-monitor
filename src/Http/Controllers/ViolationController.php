<?php

// D:\VS Code\Project test\seat-audit-monitor\src\Http\Controllers\ViolationController.php
// 违规记录查看控制器

namespace Seat\SeatAuditMonitor\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

class ViolationController extends Controller
{
    /**
     * 显示违规记录列表，按违规时间倒序分页展示
     */
    public function index()
    {
        // 使用 DB::table() 高性能查询，按违规时间倒序排列，每页显示 50 条
        $violations = DB::table('seat_audit_violations')
            ->orderBy('violation_time', 'desc')
            ->paginate(50);

        return view('seat-audit-monitor::violations.index', compact('violations'));
    }
}
