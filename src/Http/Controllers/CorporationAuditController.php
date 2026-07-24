<?php

// src/Http/Controllers/CorporationAuditController.php
// 固定军团 98588384 的 ISK 捐赠与成员低价合同只读浏览入口

namespace Seat\SeatAuditMonitor\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Seat\SeatAuditMonitor\Enums\AuditType;
use Seat\SeatAuditMonitor\Models\AuditCorporation;

class CorporationAuditController extends Controller
{
    /**
     * 显示固定军团的 2.0 审计记录。
     *
     * 不能复用 ViolationController 的旧查询：旧页面按 1.0 的“角色任一方白名单 OR、军团
     * 双方白名单 AND”实时软过滤，而 2.0 在写入时只对外部方使用白名单。混用会错误隐藏记录。
     */
    public function index()
    {
        if (Gate::denies('seat-audit-monitor.view')) {
            abort(403, '您没有权限查看军团审计记录。');
        }

        $startDate = request('start_date');
        $endDate = request('end_date');
        $auditType = request('audit_type', 'all');
        $allowedAuditTypes = AuditType::corporationAuditValues();

        if (! in_array($auditType, array_merge(['all'], $allowedAuditTypes), true)) {
            $auditType = 'all';
        }

        // 只显示固定 98588384 的两类新审计记录；既不显示 1.0 物品审计，也不做多军团选择。
        $query = DB::table('seat_audit_violations')
            ->where('audit_corporation_id', AuditCorporation::TARGET_CORPORATION_ID)
            ->whereIn('audit_type', $allowedAuditTypes)
            ->orderBy('violation_time', 'desc')
            ->orderBy('id', 'desc');

        if ($auditType !== 'all') {
            $query->where('audit_type', $auditType);
        }
        if ($startDate) {
            $query->where('violation_time', '>=', $startDate . ' 00:00:00');
        }
        if ($endDate) {
            $query->where('violation_time', '<=', $endDate . ' 23:59:59');
        }

        $paginationParams = array_filter([
            'audit_type' => $auditType !== 'all' ? $auditType : '',
            'start_date' => $startDate,
            'end_date'   => $endDate,
        ]);
        $violations = $query->paginate(50)->appends($paginationParams);
        $auditTypeLabels = AuditType::corporationAuditLabels(includeAll: true);

        return view('seat-audit-monitor::corporation-audit.index', compact(
            'violations',
            'startDate',
            'endDate',
            'auditType',
            'auditTypeLabels'
        ));
    }
}
