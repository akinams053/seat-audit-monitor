<?php

// src/Http/Controllers/ViolationController.php
// 违规记录查看控制器，包含手动触发审计扫描功能及 CSV 导出功能

namespace Seat\SeatAuditMonitor\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Gate;
use Seat\SeatAuditMonitor\Jobs\AuditContractsJob;
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
        $auditType = request('audit_type', 'all');
        // 角色名关键词：模糊匹配 character_name（发起方）或 counterparty_name（接收方）
        // 留空表示不限；trim 后取最多 100 字符避免异常输入
        $characterName = trim((string) request('character_name', ''));
        if ($characterName !== '') {
            $characterName = mb_substr($characterName, 0, 100);
        }

        // 审计类型筛选仅允许已知值，非法值按全部处理
        if (! in_array($auditType, ['all', 'wallet_transactions', 'contracts'], true)) {
            $auditType = 'all';
        }

        // 构建基础查询：LEFT JOIN 角色白名单 + 军团白名单（仅合同行 ON 条件化）
        // 软过滤豁免语义：
        //  - 钱包行：发起方在角色白名单 → 豁免（对手方是市场，无双方概念）
        //  - 合同行：发起方 AND 接收方 都在白名单（角色或军团）→ 豁免；任一方在白名单外即显示
        // 「单方在白名单」= 个人在角色白名单 OR 当前所属军团在军团白名单
        // 显示条件（按德摩根）= 钱包+发起方不在角色白名单 OR 合同+任一方完全不在任何白名单
        $query = DB::table('seat_audit_violations')
            ->leftJoin('seat_audit_whitelist as wl_chr', 'wl_chr.character_id', '=', 'seat_audit_violations.character_id')
            ->leftJoin('seat_audit_whitelist as wl_ctp', 'wl_ctp.character_id', '=', 'seat_audit_violations.counterparty_id')
            ->leftJoin('character_affiliations as aff_chr', function ($join) {
                $join->on('aff_chr.character_id', '=', 'seat_audit_violations.character_id')
                    ->where('seat_audit_violations.audit_type', '=', 'contracts');
            })
            ->leftJoin('seat_audit_corporation_whitelist as corp_wl_chr', 'corp_wl_chr.corporation_id', '=', 'aff_chr.corporation_id')
            ->leftJoin('character_affiliations as aff_ctp', function ($join) {
                $join->on('aff_ctp.character_id', '=', 'seat_audit_violations.counterparty_id')
                    ->where('seat_audit_violations.audit_type', '=', 'contracts');
            })
            ->leftJoin('seat_audit_corporation_whitelist as corp_wl_ctp', 'corp_wl_ctp.corporation_id', '=', 'aff_ctp.corporation_id')
            ->where(function ($q) {
                // 钱包行：发起方不在角色白名单则显示（counterparty 为市场，不参与判定）
                $q->where(function ($qw) {
                    $qw->where('seat_audit_violations.audit_type', '=', 'wallet_transactions')
                       ->whereNull('wl_chr.id');
                })
                // 合同行：发起方"完全不在白名单" 或 接收方"完全不在白名单" → 显示
                ->orWhere(function ($qc) {
                    $qc->where('seat_audit_violations.audit_type', '=', 'contracts')
                       ->where(function ($qcc) {
                           // 发起方完全不在任何白名单
                           $qcc->where(function ($qi) {
                               $qi->whereNull('wl_chr.id')
                                  ->whereNull('corp_wl_chr.id');
                           })
                           // 或 接收方完全不在任何白名单
                           ->orWhere(function ($qa) {
                               $qa->whereNull('wl_ctp.id')
                                  ->whereNull('corp_wl_ctp.id');
                           });
                       });
                });
            })
            ->select('seat_audit_violations.*')
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

        // 应用角色名模糊筛选：发起方或接收方任一命中即返回
        // 用 where(Closure) 包成 (col LIKE OR col LIKE)，避免和外层 AND 优先级问题
        if ($characterName !== '') {
            $like = '%' . $characterName . '%';
            $query->where(function ($q) use ($like) {
                $q->where('seat_audit_violations.character_name', 'LIKE', $like)
                  ->orWhere('seat_audit_violations.counterparty_name', 'LIKE', $like);
            });
        }

        // 分页展示，每页 50 条，保留筛选参数以便翻页时不丢失条件
        $paginationParams = array_filter([
            'start_date'     => $startDate,
            'end_date'       => $endDate,
            'audit_type'     => $auditType !== 'all' ? $auditType : '',
            'character_name' => $characterName,
        ]);
        $violations = $query->paginate(50)->appends($paginationParams);

        return view(
            'seat-audit-monitor::violations.index',
            compact('violations', 'startDate', 'endDate', 'auditType', 'characterName')
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
        // 角色名关键词：与 index() 一致的模糊匹配语义
        $characterName = trim((string) request('character_name', ''));
        if ($characterName !== '') {
            $characterName = mb_substr($characterName, 0, 100);
        }

        // 审计类型筛选仅允许已知值，非法值按全部处理
        if (! in_array($auditType, ['all', 'wallet_transactions', 'contracts'], true)) {
            $auditType = 'all';
        }

        // 构建查询（不分页，导出全部匹配记录）
        // 与 index() 保持一致的软过滤豁免语义（钱包：单方拦截；合同：双方都在白名单才豁免）
        $query = DB::table('seat_audit_violations')
            ->leftJoin('seat_audit_whitelist as wl_chr', 'wl_chr.character_id', '=', 'seat_audit_violations.character_id')
            ->leftJoin('seat_audit_whitelist as wl_ctp', 'wl_ctp.character_id', '=', 'seat_audit_violations.counterparty_id')
            ->leftJoin('character_affiliations as aff_chr', function ($join) {
                $join->on('aff_chr.character_id', '=', 'seat_audit_violations.character_id')
                    ->where('seat_audit_violations.audit_type', '=', 'contracts');
            })
            ->leftJoin('seat_audit_corporation_whitelist as corp_wl_chr', 'corp_wl_chr.corporation_id', '=', 'aff_chr.corporation_id')
            ->leftJoin('character_affiliations as aff_ctp', function ($join) {
                $join->on('aff_ctp.character_id', '=', 'seat_audit_violations.counterparty_id')
                    ->where('seat_audit_violations.audit_type', '=', 'contracts');
            })
            ->leftJoin('seat_audit_corporation_whitelist as corp_wl_ctp', 'corp_wl_ctp.corporation_id', '=', 'aff_ctp.corporation_id')
            ->where(function ($q) {
                $q->where(function ($qw) {
                    $qw->where('seat_audit_violations.audit_type', '=', 'wallet_transactions')
                       ->whereNull('wl_chr.id');
                })
                ->orWhere(function ($qc) {
                    $qc->where('seat_audit_violations.audit_type', '=', 'contracts')
                       ->where(function ($qcc) {
                           $qcc->where(function ($qi) {
                               $qi->whereNull('wl_chr.id')
                                  ->whereNull('corp_wl_chr.id');
                           })
                           ->orWhere(function ($qa) {
                               $qa->whereNull('wl_ctp.id')
                                  ->whereNull('corp_wl_ctp.id');
                           });
                       });
                });
            })
            ->orderBy('seat_audit_violations.violation_time', 'desc')
            ->select([
                'seat_audit_violations.character_name',
                'seat_audit_violations.counterparty_name',
                'seat_audit_violations.item_name',
                'seat_audit_violations.amount',
                'seat_audit_violations.violation_time',
                'seat_audit_violations.type_id',
                'seat_audit_violations.character_id',
                'seat_audit_violations.audit_type',
                'seat_audit_violations.contract_id',
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

        // 角色名模糊筛选与 index() 同语义
        if ($characterName !== '') {
            $like = '%' . $characterName . '%';
            $query->where(function ($q) use ($like) {
                $q->where('seat_audit_violations.character_name', 'LIKE', $like)
                  ->orWhere('seat_audit_violations.counterparty_name', 'LIKE', $like);
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

        $callback = function () use ($records) {
            $handle = fopen('php://output', 'w');

            // 写入 UTF-8 BOM，确保 Excel 正确识别中文编码
            fwrite($handle, "\xEF\xBB\xBF");

            // 写入 CSV 表头：原「角色名」列拆分为「发起方」+「接收方」，方便审计核对交易双方
            fputcsv($handle, [
                '发起方',
                '接收方',
                '物品名称',
                '交易金额 (ISK)',
                '发生时间',
                'Type ID',
                'Character ID',
                '审计类型',
                'Contract ID',
            ]);

            // 逐行写入违规记录数据
            foreach ($records as $row) {
                // 将内部审计类型转换为导出用中文显示
                $auditTypeLabel = [
                    'wallet_transactions' => '钱包交易',
                    'contracts'           => '合同',
                ][$row->audit_type] ?? $row->audit_type;

                // 接收方：合同行用快照中的 acceptor 名字；钱包行旧记录可能为 NULL，统一兜底为「市场」
                $counterpartyName = $row->counterparty_name
                    ?? ($row->audit_type === 'wallet_transactions' ? '市场' : '');

                fputcsv($handle, [
                    $row->character_name,
                    $counterpartyName,
                    $row->item_name,
                    number_format($row->amount, 2, '.', ''),  // 纯数字格式，便于 Excel 计算
                    $row->violation_time,
                    $row->type_id,
                    $row->character_id,
                    $auditTypeLabel,
                    $row->audit_type === 'contracts' ? $row->contract_id : '',
                ]);
            }

            fclose($handle);
        };

        return response()->stream($callback, 200, $headers);
    }
}
