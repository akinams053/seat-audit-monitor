<?php

// src/Http/Controllers/ViolationController.php
// 违规记录查看控制器，包含手动触发审计扫描功能及 CSV 导出功能

namespace Seat\SeatAuditMonitor\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Gate;
use Seat\SeatAuditMonitor\Jobs\AuditWalletTransactionsJob;

class ViolationController extends Controller
{
    /**
     * 显示违规记录列表，按违规时间倒序分页展示
     * 支持时间区间筛选（start_date / end_date 参数）
     * 需要 seat-audit-monitor.view 权限
     */
    public function index()
    {
        // 权限检查：无 view 权限的用户返回 403
        if (Gate::denies('seat-audit-monitor.view')) {
            abort(403, '您没有权限查看审计记录。');
        }

        // 获取时间区间筛选参数（来自 GET 请求）
        $startDate = request('start_date');
        $endDate   = request('end_date');

        // 构建基础查询，支持按时间区间过滤
        $query = DB::table('seat_audit_violations')
            ->orderBy('violation_time', 'desc');

        // 应用起始时间筛选（violation_time >= start_date 00:00:00）
        if ($startDate) {
            $query->where('violation_time', '>=', $startDate . ' 00:00:00');
        }

        // 应用截止时间筛选（violation_time <= end_date 23:59:59）
        if ($endDate) {
            $query->where('violation_time', '<=', $endDate . ' 23:59:59');
        }

        // 分页展示，每页 50 条，保留筛选参数以便翻页时不丢失条件
        $violations = $query->paginate(50)->appends(request()->only(['start_date', 'end_date']));

        return view('seat-audit-monitor::violations.index', compact('violations', 'startDate', 'endDate'));
    }

    /**
     * 手动触发一次审计扫描
     * 需要 seat-audit-monitor.admin 权限
     * 同步执行 Job，完成后跳转回违规记录页并显示结果
     */
    public function scan()
    {
        // 权限检查：仅管理员可触发扫描
        if (Gate::denies('seat-audit-monitor.admin')) {
            abort(403, '您没有权限执行审计扫描。');
        }

        // 记录扫描前的违规记录数，用于对比扫描结果
        $beforeCount = DB::table('seat_audit_violations')->count();

        // 同步执行审计 Job（在当前请求进程中运行）
        Bus::dispatchSync(new AuditWalletTransactionsJob());

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

    /**
     * 导出违规记录为 CSV 文件（Excel/WPS 可直接打开）
     * 支持时间区间筛选（start_date / end_date 参数）
     * 需要 seat-audit-monitor.view 权限
     */
    public function export()
    {
        // 权限检查：无 view 权限的用户返回 403
        if (Gate::denies('seat-audit-monitor.view')) {
            abort(403, '您没有权限导出审计记录。');
        }

        // 获取时间区间筛选参数
        $startDate = request('start_date');
        $endDate   = request('end_date');

        // 构建查询（不分页，导出全部匹配记录）
        $query = DB::table('seat_audit_violations')
            ->orderBy('violation_time', 'desc')
            ->select(['character_name', 'item_name', 'amount', 'violation_time', 'type_id', 'character_id']);

        // 应用起始时间筛选
        if ($startDate) {
            $query->where('violation_time', '>=', $startDate . ' 00:00:00');
        }

        // 应用截止时间筛选
        if ($endDate) {
            $query->where('violation_time', '<=', $endDate . ' 23:59:59');
        }

        $records = $query->get();

        // 生成文件名，包含导出时间和筛选区间信息
        $dateRange = '';
        if ($startDate || $endDate) {
            $dateRange = '_' . ($startDate ?: 'start') . '_to_' . ($endDate ?: 'end');
        }
        $filename = 'violations' . $dateRange . '_' . date('Ymd_His') . '.csv';

        // 使用流式响应输出 CSV，避免大数据量时内存溢出
        $headers = [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control'       => 'no-cache, no-store, must-revalidate',
            'Pragma'              => 'no-cache',
            'Expires'             => '0',
        ];

        $callback = function () use ($records) {
            $handle = fopen('php://output', 'w');

            // 写入 UTF-8 BOM，确保 Excel 正确识别中文编码
            fwrite($handle, "\xEF\xBB\xBF");

            // 写入 CSV 表头
            fputcsv($handle, ['角色名', '物品名称', '交易金额 (ISK)', '发生时间', 'Type ID', 'Character ID']);

            // 逐行写入违规记录数据
            foreach ($records as $row) {
                fputcsv($handle, [
                    $row->character_name,
                    $row->item_name,
                    number_format($row->amount, 2, '.', ''),  // 纯数字格式，便于 Excel 计算
                    $row->violation_time,
                    $row->type_id,
                    $row->character_id,
                ]);
            }

            fclose($handle);
        };

        return response()->stream($callback, 200, $headers);
    }
}
