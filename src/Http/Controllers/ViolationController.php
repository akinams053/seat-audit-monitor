<?php

// src/Http/Controllers/ViolationController.php
// 违规记录查看控制器，包含手动触发审计扫描功能及 CSV 导出功能

namespace Seat\SeatAuditMonitor\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Gate;
use Seat\SeatAuditMonitor\Enums\AuditType;
use Seat\SeatAuditMonitor\Jobs\AuditContractsJob;
use Seat\SeatAuditMonitor\Jobs\AuditWalletTransactionsJob;
use Seat\SeatAuditMonitor\Jobs\ResolveUnknownNamesJob;

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
        $auditType = request('audit_type', 'all');
        // 通用关键词：模糊匹配 character_name / counterparty_name / 双方 corp name 或 ticker 任一命中
        // URL 参数名改为 keyword（旧的 character_name 也兼容，平滑过渡）
        $keyword = trim((string) (request('keyword', request('character_name', ''))));
        if ($keyword !== '') {
            $keyword = mb_substr($keyword, 0, 100);
        }

        // 审计类型筛选仅允许已知值，非法值按全部处理
        if (! in_array($auditType, array_merge(['all'], AuditType::itemViolationValues()), true)) {
            $auditType = 'all';
        }

        // 构建基础查询：LEFT JOIN 角色白名单 + 军团白名单 + corporation_infos + universe_names（用于 UI 显示双方军团）
        // 豁免语义（两套白名单不同）：
        //  - 角色白名单：任一方在角色白名单 → 豁免（OR，单方拦截，钱包+合同均生效）
        //  - 军团白名单：发起方军团 AND 接收方军团 都在军团白名单 → 豁免（AND，仅合同生效）
        // 显示条件 = NOT(任一豁免) = 钱包+发起方不在角色白名单 OR 合同+(双方都不在角色白名单 AND 至少一方军团不在白名单)
        // 军团名字双源 COALESCE：
        //  - corporation_infos：SeAT 内部已收录的军团（有 ticker，UI 优先用）
        //  - universe_names：外部军团 fallback（无 ticker，但至少能拿到 name）
        $query = DB::table('seat_audit_violations')
            ->leftJoin('seat_audit_whitelist as wl_chr', 'wl_chr.character_id', '=', 'seat_audit_violations.character_id')
            ->leftJoin('seat_audit_whitelist as wl_ctp', 'wl_ctp.character_id', '=', 'seat_audit_violations.counterparty_id')
            ->leftJoin('character_affiliations as aff_chr', function ($join) {
                $join->on('aff_chr.character_id', '=', 'seat_audit_violations.character_id')
                    ->where('seat_audit_violations.audit_type', '=', AuditType::Contracts->value);
            })
            ->leftJoin('seat_audit_corporation_whitelist as corp_wl_chr', 'corp_wl_chr.corporation_id', '=', 'aff_chr.corporation_id')
            ->leftJoin('corporation_infos as corp_chr_info', 'corp_chr_info.corporation_id', '=', 'aff_chr.corporation_id')
            ->leftJoin('universe_names as corp_chr_un', function ($join) {
                $join->on('corp_chr_un.entity_id', '=', 'aff_chr.corporation_id')
                    ->where('corp_chr_un.category', '=', 'corporation');
            })
            ->leftJoin('character_affiliations as aff_ctp', function ($join) {
                $join->on('aff_ctp.character_id', '=', 'seat_audit_violations.counterparty_id')
                    ->where('seat_audit_violations.audit_type', '=', AuditType::Contracts->value);
            })
            ->leftJoin('seat_audit_corporation_whitelist as corp_wl_ctp', 'corp_wl_ctp.corporation_id', '=', 'aff_ctp.corporation_id')
            ->leftJoin('corporation_infos as corp_ctp_info', 'corp_ctp_info.corporation_id', '=', 'aff_ctp.corporation_id')
            ->leftJoin('universe_names as corp_ctp_un', function ($join) {
                $join->on('corp_ctp_un.entity_id', '=', 'aff_ctp.corporation_id')
                    ->where('corp_ctp_un.category', '=', 'corporation');
            })
            ->where(function ($q) {
                $q->where(function ($qw) {
                    $qw->where('seat_audit_violations.audit_type', '=', AuditType::WalletTransactions->value)
                       ->whereNull('wl_chr.id');
                })
                ->orWhere(function ($qc) {
                    $qc->where('seat_audit_violations.audit_type', '=', AuditType::Contracts->value)
                       ->whereNull('wl_chr.id')
                       ->whereNull('wl_ctp.id')
                       ->where(function ($qcorp) {
                           $qcorp->whereNull('corp_wl_chr.id')
                                 ->orWhereNull('corp_wl_ctp.id');
                       });
                });
            })
            ->select(
                'seat_audit_violations.*',
                // corp 名字：corporation_infos 优先（含 ticker），universe_names 兜底（外部 corp）
                DB::raw('COALESCE(corp_chr_info.name, corp_chr_un.name) as issuer_corp_name'),
                'corp_chr_info.ticker as issuer_corp_ticker',
                DB::raw('COALESCE(corp_ctp_info.name, corp_ctp_un.name) as acceptor_corp_name'),
                'corp_ctp_info.ticker as acceptor_corp_ticker'
            )
            ->orderBy('seat_audit_violations.violation_time', 'desc');

        // 应用审计类型筛选
        if ($auditType !== 'all') {
            $query->where('seat_audit_violations.audit_type', $auditType);
        }

        // 应用起始时间筛选（violation_time >= start_date 00:00:00）
        if ($startDate) {
            $query->where('seat_audit_violations.violation_time', '>=', $startDate . ' 00:00:00');
        }

        // 应用截止时间筛选（violation_time <= end_date 23:59:59）
        if ($endDate) {
            $query->where('seat_audit_violations.violation_time', '<=', $endDate . ' 23:59:59');
        }

        // 应用通用关键词模糊筛选：角色名（双方）或 军团名/ticker（双方，corporation_infos + universe_names 兜底）任一命中即返回
        // 用 where(Closure) 包成 OR 子句，避免和外层 AND 优先级冲突
        if ($keyword !== '') {
            $like = '%' . $keyword . '%';
            $query->where(function ($q) use ($like) {
                $q->where('seat_audit_violations.character_name', 'LIKE', $like)
                  ->orWhere('seat_audit_violations.counterparty_name', 'LIKE', $like)
                  ->orWhere('corp_chr_info.name', 'LIKE', $like)
                  ->orWhere('corp_chr_info.ticker', 'LIKE', $like)
                  ->orWhere('corp_ctp_info.name', 'LIKE', $like)
                  ->orWhere('corp_ctp_info.ticker', 'LIKE', $like)
                  ->orWhere('corp_chr_un.name', 'LIKE', $like)
                  ->orWhere('corp_ctp_un.name', 'LIKE', $like);
            });
        }

        // 分页展示，每页 50 条，保留筛选参数以便翻页时不丢失条件
        $paginationParams = array_filter([
            'start_date' => $startDate,
            'end_date'   => $endDate,
            'audit_type' => $auditType !== 'all' ? $auditType : '',
            'keyword'    => $keyword,
        ]);
        $violations = $query->paginate(50)->appends($paginationParams);

        // Blade 只消费集中定义的旧页面审计类型和标签，不再自行维护字符串白名单。
        $auditTypeLabels = AuditType::itemViolationLabels(includeAll: true);
        $auditTypes = [
            'wallet'    => AuditType::WalletTransactions->value,
            'contracts' => AuditType::Contracts->value,
        ];

        return view(
            'seat-audit-monitor::violations.index',
            compact(
                'violations',
                'startDate',
                'endDate',
                'auditType',
                'keyword',
                'auditTypeLabels',
                'auditTypes'
            )
        );
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

        // 获取手动审计类型，非法值直接返回错误提示
        $type = request('type', 'all');
        if (! in_array($type, ['wallet', 'contracts', 'all'], true)) {
            return redirect()->back()
                ->with('error', '无效的审计类型。');
        }

        // 记录扫描前的违规记录数，用于对比扫描结果
        $beforeCount = DB::table('seat_audit_violations')->count();

        // 按选择同步执行审计 Job（在当前请求进程中运行）
        if ($type === 'wallet' || $type === 'all') {
            Bus::dispatchSync(new AuditWalletTransactionsJob());
        }

        if ($type === 'contracts' || $type === 'all') {
            Bus::dispatchSync(new AuditContractsJob());
        }

        // 计算本次扫描新增的违规记录数
        $afterCount = DB::table('seat_audit_violations')->count();
        $newCount = $afterCount - $beforeCount;

        // 把内部 type 标识转成中文标签，便于管理员阅读
        $typeLabel = [
            'wallet'    => '钱包交易',
            'contracts' => '合同',
            'all'       => '全部类型',
        ][$type];
        $message = '审计扫描完成（' . $typeLabel . '），新发现 ' . $newCount . ' 条违规记录。';

        return redirect()->route('seat-audit.violations.index')
            ->with('success', $message);
    }

    /**
     * 触发批量 ESI 外部角色名解析（异步入队列）
     * 需要 seat-audit-monitor.admin 权限
     */
    public function resolveUnknown()
    {
        if (Gate::denies('seat-audit-monitor.admin')) {
            abort(403, '您没有权限执行名字解析任务。');
        }

        // 进队列异步跑——SeAT 用 Horizon 调度，UI 立即返回避免 PHP-FPM 超时
        // 解析结果几秒~几十秒后可见，用户刷新列表查看
        dispatch(new ResolveUnknownNamesJob());

        return redirect()->route('seat-audit.violations.index')
            ->with('success', '名字解析任务已加入队列。数秒后刷新本页可见结果（具体进度见 laravel.log 中 [seat-audit:resolve-unknown] 前缀）。');
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
        $auditType = request('audit_type', 'all');
        // 通用关键词：与 index() 一致的模糊匹配语义（兼容旧参数 character_name）
        $keyword = trim((string) (request('keyword', request('character_name', ''))));
        if ($keyword !== '') {
            $keyword = mb_substr($keyword, 0, 100);
        }

        // 审计类型筛选仅允许已知值，非法值按全部处理
        if (! in_array($auditType, array_merge(['all'], AuditType::itemViolationValues()), true)) {
            $auditType = 'all';
        }

        // 构建查询（不分页，导出全部匹配记录）
        // 与 index() 保持一致的软过滤豁免语义 + 双方军团 JOIN + universe_names 兜底
        $query = DB::table('seat_audit_violations')
            ->leftJoin('seat_audit_whitelist as wl_chr', 'wl_chr.character_id', '=', 'seat_audit_violations.character_id')
            ->leftJoin('seat_audit_whitelist as wl_ctp', 'wl_ctp.character_id', '=', 'seat_audit_violations.counterparty_id')
            ->leftJoin('character_affiliations as aff_chr', function ($join) {
                $join->on('aff_chr.character_id', '=', 'seat_audit_violations.character_id')
                    ->where('seat_audit_violations.audit_type', '=', AuditType::Contracts->value);
            })
            ->leftJoin('seat_audit_corporation_whitelist as corp_wl_chr', 'corp_wl_chr.corporation_id', '=', 'aff_chr.corporation_id')
            ->leftJoin('corporation_infos as corp_chr_info', 'corp_chr_info.corporation_id', '=', 'aff_chr.corporation_id')
            ->leftJoin('universe_names as corp_chr_un', function ($join) {
                $join->on('corp_chr_un.entity_id', '=', 'aff_chr.corporation_id')
                    ->where('corp_chr_un.category', '=', 'corporation');
            })
            ->leftJoin('character_affiliations as aff_ctp', function ($join) {
                $join->on('aff_ctp.character_id', '=', 'seat_audit_violations.counterparty_id')
                    ->where('seat_audit_violations.audit_type', '=', AuditType::Contracts->value);
            })
            ->leftJoin('seat_audit_corporation_whitelist as corp_wl_ctp', 'corp_wl_ctp.corporation_id', '=', 'aff_ctp.corporation_id')
            ->leftJoin('corporation_infos as corp_ctp_info', 'corp_ctp_info.corporation_id', '=', 'aff_ctp.corporation_id')
            ->leftJoin('universe_names as corp_ctp_un', function ($join) {
                $join->on('corp_ctp_un.entity_id', '=', 'aff_ctp.corporation_id')
                    ->where('corp_ctp_un.category', '=', 'corporation');
            })
            ->where(function ($q) {
                $q->where(function ($qw) {
                    $qw->where('seat_audit_violations.audit_type', '=', AuditType::WalletTransactions->value)
                       ->whereNull('wl_chr.id');
                })
                ->orWhere(function ($qc) {
                    $qc->where('seat_audit_violations.audit_type', '=', AuditType::Contracts->value)
                       ->whereNull('wl_chr.id')
                       ->whereNull('wl_ctp.id')
                       ->where(function ($qcorp) {
                           $qcorp->whereNull('corp_wl_chr.id')
                                 ->orWhereNull('corp_wl_ctp.id');
                       });
                });
            })
            ->orderBy('seat_audit_violations.violation_time', 'desc')
            ->select([
                'seat_audit_violations.character_name',
                'seat_audit_violations.counterparty_name',
                DB::raw('COALESCE(corp_chr_info.name, corp_chr_un.name) as issuer_corp_name'),
                'corp_chr_info.ticker as issuer_corp_ticker',
                DB::raw('COALESCE(corp_ctp_info.name, corp_ctp_un.name) as acceptor_corp_name'),
                'corp_ctp_info.ticker as acceptor_corp_ticker',
                'seat_audit_violations.item_name',
                'seat_audit_violations.amount',
                'seat_audit_violations.violation_time',
                'seat_audit_violations.type_id',
                'seat_audit_violations.character_id',
                'seat_audit_violations.audit_type',
                'seat_audit_violations.contract_id',
                'seat_audit_violations.contract_availability',
            ]);

        // 应用审计类型筛选
        if ($auditType !== 'all') {
            $query->where('seat_audit_violations.audit_type', $auditType);
        }

        // 应用起始时间筛选
        if ($startDate) {
            $query->where('seat_audit_violations.violation_time', '>=', $startDate . ' 00:00:00');
        }

        // 应用截止时间筛选
        if ($endDate) {
            $query->where('seat_audit_violations.violation_time', '<=', $endDate . ' 23:59:59');
        }

        // 通用关键词筛选与 index() 同语义
        if ($keyword !== '') {
            $like = '%' . $keyword . '%';
            $query->where(function ($q) use ($like) {
                $q->where('seat_audit_violations.character_name', 'LIKE', $like)
                  ->orWhere('seat_audit_violations.counterparty_name', 'LIKE', $like)
                  ->orWhere('corp_chr_info.name', 'LIKE', $like)
                  ->orWhere('corp_chr_info.ticker', 'LIKE', $like)
                  ->orWhere('corp_ctp_info.name', 'LIKE', $like)
                  ->orWhere('corp_ctp_info.ticker', 'LIKE', $like)
                  ->orWhere('corp_chr_un.name', 'LIKE', $like)
                  ->orWhere('corp_ctp_un.name', 'LIKE', $like);
            });
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

        $auditTypeLabels = AuditType::itemViolationLabels();

        $callback = function () use ($records, $auditTypeLabels) {
            $handle = fopen('php://output', 'w');

            // 写入 UTF-8 BOM，确保 Excel 正确识别中文编码
            fwrite($handle, "\xEF\xBB\xBF");

            // 写入 CSV 表头：拆双方角色名 + 双方军团 + 来源细分（availability）
            fputcsv($handle, [
                '发起方',
                '发起方军团',
                '接收方',
                '接收方军团',
                '物品名称',
                '交易金额 (ISK)',
                '发生时间',
                'Type ID',
                'Character ID',
                '审计类型',
                '合同可见性',
                'Contract ID',
            ]);

            // 逐行写入违规记录数据
            foreach ($records as $row) {
                // 内部审计类型转中文显示，标签由 AuditType 集中维护。
                $auditTypeLabel = $auditTypeLabels[$row->audit_type] ?? $row->audit_type;

                // 接收方：合同行用快照中的 acceptor 名字；钱包行旧记录可能为 NULL，统一兜底为「市场」
                $counterpartyName = $row->counterparty_name
                    ?? ($row->audit_type === AuditType::WalletTransactions->value ? '市场' : '');

                // 军团：合同行才有；钱包行接收方为「市场」
                $issuerCorp = '';
                $acceptorCorp = '';
                if ($row->audit_type === AuditType::Contracts->value) {
                    $issuerCorp = $row->issuer_corp_name
                        ? $row->issuer_corp_name . (($row->issuer_corp_ticker) ? ' [' . $row->issuer_corp_ticker . ']' : '')
                        : '';
                    $acceptorCorp = $row->acceptor_corp_name
                        ? $row->acceptor_corp_name . (($row->acceptor_corp_ticker) ? ' [' . $row->acceptor_corp_ticker . ']' : '')
                        : '';
                } elseif ($row->audit_type === AuditType::WalletTransactions->value) {
                    $acceptorCorp = '市场';
                }

                // availability 中文标签
                $availabilityLabel = [
                    'public'       => '公开',
                    'personal'     => '私人',
                    'corporation'  => '军团',
                    'alliance'     => '联盟',
                ][$row->contract_availability] ?? ($row->contract_availability ?? '');

                fputcsv($handle, [
                    $row->character_name,
                    $issuerCorp,
                    $counterpartyName,
                    $acceptorCorp,
                    $row->item_name,
                    number_format($row->amount, 2, '.', ''),
                    $row->violation_time,
                    $row->type_id,
                    $row->character_id,
                    $auditTypeLabel,
                    $availabilityLabel,
                    $row->audit_type === AuditType::Contracts->value ? $row->contract_id : '',
                ]);
            }

            fclose($handle);
        };

        return response()->stream($callback, 200, $headers);
    }
}
