<?php

// D:\VS Code\Project test\seat-audit-monitor\src\Http\Controllers\ViolationController.php
// 违规记录查看控制器，包含手动触发审计扫描功能

namespace Seat\SeatAuditMonitor\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Seat\SeatAuditMonitor\Jobs\AuditWalletTransactionsJob;

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

    /**
     * 手动触发一次审计扫描
     * 同步执行 Job，完成后跳转回违规记录页并显示结果
     */
    public function scan()
    {
        // 记录扫描前的违规记录数，用于对比扫描结果
        $beforeCount = DB::table('seat_audit_violations')->count();

        // 同步执行审计 Job（在当前请求进程中运行）
        dispatch_now(new AuditWalletTransactionsJob());

        // 计算本次扫描新增的违规记录数
        $afterCount = DB::table('seat_audit_violations')->count();
        $newCount = $afterCount - $beforeCount;

        if ($newCount > 0) {
            $message = '审计扫描完成，新发现 ' . $newCount . ' 条违规记录。';
        } else {
            $message = '审计扫描完成，未发现新的违规记录。';
        }

        return redirect()->route('seat-audit.violations.index')
            ->with('success', $message);
    }
}
