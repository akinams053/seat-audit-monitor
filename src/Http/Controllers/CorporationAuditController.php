<?php

// src/Http/Controllers/CorporationAuditController.php
// 固定军团 98588384 的 ISK 捐赠与成员低价合同浏览、异步扫描状态和 CSV 导出入口。

namespace Seat\SeatAuditMonitor\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Seat\SeatAuditMonitor\Enums\AuditType;
use Seat\SeatAuditMonitor\Jobs\AuditDonationsJob;
use Seat\SeatAuditMonitor\Jobs\AuditMemberContractsJob;
use Seat\SeatAuditMonitor\Models\AuditCorporation;
use Seat\SeatAuditMonitor\Services\Audit\ScanProgressStore;

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
        $auditType = $this->requestedAuditType();
        $allowedAuditTypes = AuditType::corporationAuditValues();
        $auditTypeLabels = AuditType::corporationAuditLabels();

        $violations = $this->corporationViolationsQuery($auditType, $startDate, $endDate)
            ->paginate(50)
            ->appends(array_filter([
                'audit_type' => $auditType,
                'start_date' => $startDate,
                'end_date'   => $endDate,
            ]));

        // scan_token 只用于恢复本浏览器的一次性轮询；非法值不传给前端或状态端点。
        $scanToken = $this->validScanToken((string) request('scan_token', ''));
        $scanStatusUrl = $scanToken === null
            ? null
            : route('seat-audit.corporation-audit.scan-status', ['token' => $scanToken]);
        $scanRefreshUrl = route('seat-audit.corporation-audit.index', array_filter([
            'audit_type' => $auditType,
            'start_date' => $startDate,
            'end_date'   => $endDate,
        ]));

        return view('seat-audit-monitor::corporation-audit.index', compact(
            'violations',
            'startDate',
            'endDate',
            'auditType',
            'auditTypeLabels',
            'allowedAuditTypes',
            'scanToken',
            'scanStatusUrl',
            'scanRefreshUrl',
        ));
    }

    /**
     * 将当前标签对应的军团审计任务投递到队列，并创建 30 分钟内有效的浏览器跟踪状态。
     *
     * 此处刻意不实现服务端去重：每个合法点击仍可创建独立 Job；按钮禁用和完成提醒仅减少
     * 普通使用者在等待期间的重复操作，数据正确性仍由 Job 的 cursor 事务和来源唯一键保证。
     */
    public function scan(ScanProgressStore $scanProgressStore)
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

        $scanToken = (string) Str::uuid();
        $scanProgressStore->create($scanToken, $auditType);

        try {
            match ($auditType) {
                AuditType::IskDonations->value => dispatch(new AuditDonationsJob($scanToken)),
                AuditType::MemberContracts->value => dispatch(new AuditMemberContractsJob($scanToken)),
            };
        } catch (\Throwable) {
            // 队列投递本身失败时也将 Cache 标记为失败，页面不会无期限显示“等待队列”。
            $scanProgressStore->markFailed($scanToken);

            return redirect()->route('seat-audit.corporation-audit.index', [
                ...$redirectParameters,
                'audit_type' => $auditType,
            ])->with('error', '扫描任务提交失败，请检查队列服务后重试。');
        }

        return redirect()->route('seat-audit.corporation-audit.index', [
            ...$redirectParameters,
            'audit_type' => $auditType,
            'scan_token' => $scanToken,
        ])->with('success', '扫描任务已提交，请勿重复点击；完成后本页会自动刷新并显示结果。');
    }

    /**
     * 返回本次浏览器扫描的短生命周期进度。
     *
     * Cache 过期或被清理不会影响后台 Job，只会使页面无法继续关联本次请求，绝不能据此伪造成功。
     */
    public function scanStatus(ScanProgressStore $scanProgressStore, string $token)
    {
        if (Gate::denies('seat-audit-monitor.admin')) {
            abort(403, '您没有权限查看军团审计扫描状态。');
        }

        $token = $this->validScanToken($token);
        if ($token === null) {
            abort(404);
        }

        $progress = $scanProgressStore->read($token);
        if ($progress === null) {
            return response()->json([
                'status'   => 'unavailable',
                'terminal' => false,
                'chunks'   => 0,
                'inserted' => 0,
                'reason'   => 'tracking_unavailable',
            ], 200, ['Cache-Control' => 'no-store, private']);
        }

        // 进度值来源于本插件自身写入的 Cache；仍限制类型和值，避免缓存脏数据影响前端逻辑。
        $status = in_array($progress['status'] ?? null, ['queued', 'running', 'succeeded', 'skipped', 'failed'], true)
            ? $progress['status']
            : 'unavailable';
        $auditType = in_array($progress['audit_type'] ?? null, AuditType::corporationAuditValues(), true)
            ? $progress['audit_type']
            : null;

        return response()->json([
            'audit_type' => $auditType,
            'status'     => $status,
            'terminal'   => in_array($status, ['succeeded', 'skipped', 'failed'], true),
            'chunks'     => max(0, (int) ($progress['chunks'] ?? 0)),
            'inserted'   => max(0, (int) ($progress['inserted'] ?? 0)),
            'reason'     => is_string($progress['reason'] ?? null) ? $progress['reason'] : null,
        ], 200, ['Cache-Control' => 'no-store, private']);
    }

    /**
     * 导出当前军团审计标签和日期范围内的全部记录。
     *
     * 导出必须独立于旧 1.0 违规导出：2.0 的白名单已在写入扫描时按“仅外部方”语义处理，
     * 不能套用 ViolationController 的旧查询层软过滤，否则会错误隐藏军团审计记录。
     */
    public function export()
    {
        if (Gate::denies('seat-audit-monitor.view')) {
            abort(403, '您没有权限导出军团审计记录。');
        }

        $startDate = request('start_date');
        $endDate = request('end_date');
        $auditType = $this->requestedAuditType();
        $auditTypeLabels = AuditType::corporationAuditLabels();
        $filename = 'seat-corporation-audit-' . str_replace('_', '-', $auditType)
            . '-' . now('UTC')->format('Ymd_His') . '-UTC.csv';

        $query = $this->corporationViolationsQuery($auditType, $startDate, $endDate)
            ->select([
                'audit_type',
                'member_character_id',
                'character_name',
                'external_party_id',
                'counterparty_name',
                'external_party_type',
                'direction',
                'amount',
                'source_reference',
                'contract_id',
                'character_corporation_id',
                'counterparty_corporation_id',
                'violation_time',
            ]);

        return response()->stream(function () use ($query, $auditTypeLabels): void {
            $handle = fopen('php://output', 'w');

            // UTF-8 BOM 使 Excel/WPS 能正确识别中文角色名、方向和表头。
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, [
                '审计类型',
                '成员 ID',
                '成员名',
                '外部方 ID',
                '外部方名称',
                '外部方类型',
                '方向',
                '金额（ISK）',
                '来源引用',
                '合同 ID',
                '成员军团 ID 快照',
                '外部方军团 ID 快照',
                '违规发生时间',
            ]);

            // cursor() 逐行消费 PDO 结果，不将全量导出记录加载到 PHP 内存。
            foreach ($query->cursor() as $violation) {
                $direction = match ($violation->direction) {
                    'outbound' => '成员转出',
                    'inbound'  => '成员接收',
                    default    => '未知方向',
                };

                fputcsv($handle, array_map($this->csvCell(...), [
                    $auditTypeLabels[$violation->audit_type] ?? '未知审计类型',
                    $violation->member_character_id,
                    $violation->character_name,
                    $violation->external_party_id,
                    $violation->counterparty_name,
                    $violation->external_party_type,
                    $direction,
                    $violation->amount,
                    $violation->source_reference,
                    $violation->contract_id,
                    $violation->character_corporation_id,
                    $violation->counterparty_corporation_id,
                    $violation->violation_time,
                ]));
            }

            fclose($handle);
        }, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control'       => 'no-cache, no-store, must-revalidate',
            'Pragma'              => 'no-cache',
            'Expires'             => '0',
        ]);
    }

    /**
     * 构建军团审计页面和 CSV 共用的基础查询。
     *
     * 日期条件只过滤已入库违规的业务发生时间，不改变扫描 Job 的 audit_from 或 cursor。
     */
    private function corporationViolationsQuery(string $auditType, mixed $startDate, mixed $endDate)
    {
        $query = DB::table('seat_audit_violations')
            ->where('audit_corporation_id', AuditCorporation::TARGET_CORPORATION_ID)
            ->where('audit_type', $auditType)
            ->orderBy('violation_time', 'desc')
            ->orderBy('id', 'desc');

        if ($startDate) {
            $query->where('violation_time', '>=', $startDate . ' 00:00:00');
        }
        if ($endDate) {
            $query->where('violation_time', '<=', $endDate . ' 23:59:59');
        }

        return $query;
    }

    /**
     * 页面只展示两种固定军团审计类型；旧 all 值和非法值均回退到 Donation 标签。
     */
    private function requestedAuditType(): string
    {
        $auditType = request('audit_type', AuditType::IskDonations->value);

        return in_array($auditType, AuditType::corporationAuditValues(), true)
            ? $auditType
            : AuditType::IskDonations->value;
    }

    /**
     * CSV 可被 Excel/WPS 解释为公式；角色名、外部方名和来源引用均来自外部同步数据，不可信。
     */
    private function csvCell(mixed $value): string
    {
        $cell = (string) $value;
        $visibleValue = preg_replace('/^[\s\p{C}]*/u', '', $cell);
        if ($visibleValue === null) {
            // 历史数据若不是有效 UTF-8，仍必须覆盖常见 ASCII 空白和控制字符前缀。
            $visibleValue = ltrim($cell, " \t\n\r\0\x0B");
        }

        return $visibleValue !== '' && in_array($visibleValue[0], ['=', '+', '-', '@'], true)
            ? "'" . $cell
            : $cell;
    }

    private function validScanToken(string $token): ?string
    {
        return Str::isUuid($token) ? strtolower($token) : null;
    }
}
