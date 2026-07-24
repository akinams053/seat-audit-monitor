<?php

// src/Http/Controllers/CorporationAuditController.php
// 固定军团 98588384 的 ISK 捐赠与成员低价合同只读浏览入口

namespace Seat\SeatAuditMonitor\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Seat\SeatAuditMonitor\Enums\AuditType;
use Seat\SeatAuditMonitor\Jobs\AuditDonationsJob;
use Seat\SeatAuditMonitor\Jobs\AuditMemberContractsJob;
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
        $allowedAuditTypes = AuditType::corporationAuditValues();
        // 军团审计页按两类来源分标签展示；旧 all 参数或非法值统一回退到 Donation，
        // 避免页面没有激活标签却混合显示两类记录。
        $auditType = request('audit_type', AuditType::IskDonations->value);

        if (! in_array($auditType, $allowedAuditTypes, true)) {
            $auditType = AuditType::IskDonations->value;
        }

        // 只显示固定 98588384 的两类新审计记录；既不显示 1.0 物品审计，也不做多军团选择。
        $query = DB::table('seat_audit_violations')
            ->where('audit_corporation_id', AuditCorporation::TARGET_CORPORATION_ID)
            ->whereIn('audit_type', $allowedAuditTypes)
            ->orderBy('violation_time', 'desc')
            ->orderBy('id', 'desc');

        $query->where('audit_type', $auditType);

        if ($startDate) {
            $query->where('violation_time', '>=', $startDate . ' 00:00:00');
        }
        if ($endDate) {
            $query->where('violation_time', '<=', $endDate . ' 23:59:59');
        }

        $paginationParams = array_filter([
            'audit_type' => $auditType,
            'start_date' => $startDate,
            'end_date'   => $endDate,
        ]);
        $violations = $query->paginate(50)->appends($paginationParams);
        $auditTypeLabels = AuditType::corporationAuditLabels();

        return view('seat-audit-monitor::corporation-audit.index', compact(
            'violations',
            'startDate',
            'endDate',
            'auditType',
            'auditTypeLabels',
            'allowedAuditTypes'
        ));
    }

    /**
     * 将当前标签对应的军团审计任务投递到队列。
     *
     * 新审计 Job 会按 cursor 分批读取来源；首次扫描或 SeAT 延迟积压时耗时不可预期，
     * 因此不能复用旧 1.0 页面中 dispatchSync() 的同步 HTTP 行为，以免 PHP-FPM 请求超时。
     */
    public function scan()
    {
        if (Gate::denies('seat-audit-monitor.admin')) {
            abort(403, '您没有权限执行军团审计扫描。');
        }

        $auditType = request('audit_type');
        $allowedAuditTypes = AuditType::corporationAuditValues();
        $redirectParameters = array_filter([
            'start_date' => request('start_date'),
            'end_date'   => request('end_date'),
        ]);

        // 请求只能选择当前固定军团的两类审计；不得让浏览器传入军团 ID、回扫时间或任意 Job。
        if (! in_array($auditType, $allowedAuditTypes, true)) {
            return redirect()->route('seat-audit.corporation-audit.index', $redirectParameters)
                ->with('error', '无效的军团审计类型。');
        }

        $redirectParameters['audit_type'] = $auditType;
        match ($auditType) {
            AuditType::IskDonations->value => dispatch(new AuditDonationsJob()),
            AuditType::MemberContracts->value => dispatch(new AuditMemberContractsJob()),
        };

        return redirect()->route('seat-audit.corporation-audit.index', $redirectParameters)
            ->with('success', AuditType::from($auditType)->label() . '扫描任务已提交到队列。任务完成后刷新本页查看最新记录。');
    }
}
