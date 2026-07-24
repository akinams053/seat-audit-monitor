<?php

// src/Http/Controllers/SeatTokenAuditController.php
// 固定军团 98588384 的 SeAT 令牌状态只读审查入口；不刷新 token、不调用 ESI。

namespace Seat\SeatAuditMonitor\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Seat\SeatAuditMonitor\Services\TokenAudit\SeatTokenAuditGroupingService;
use Seat\SeatAuditMonitor\Services\TokenAudit\SeatTokenAuditReadService;

final class SeatTokenAuditController extends Controller
{
    private const ALLOWED_STATUSES = ['all', 'normal', 'expired', 'unbound'];
    private const ALLOWED_LAST_SEEN_FILTERS = ['all', 'within_30', 'within_60'];
    private const ALLOWED_PER_PAGE = [10, 25, 50, 100];

    /**
     * 展示当前游戏内成员的令牌状态。
     *
     * 页面没有任何扫描、同步或修复动作：每次请求只读取 SeAT 已有成员、token 状态和角色追踪数据。
     * refresh_tokens 的敏感值在读取服务中从未被 select，因此 Controller、分页器与 Blade 均无法接触。
     */
    public function index(
        SeatTokenAuditReadService $readService,
        SeatTokenAuditGroupingService $groupingService,
    ) {
        if (Gate::denies('seat-audit-monitor.view')) {
            abort(403, '您没有权限查看令牌审查。');
        }

        $status = $this->allowedValue((string) request('status', 'all'), self::ALLOWED_STATUSES, 'all');
        $lastSeen = $this->allowedValue((string) request('last_seen', 'all'), self::ALLOWED_LAST_SEEN_FILTERS, 'all');
        $perPage = $this->allowedPerPage(request('per_page'));
        $page = max(1, (int) request('page', 1));
        $search = $this->limitSearch((string) request('q', ''));

        // 审查基准固定为本次 HTTP 请求开始时的 UTC；同一页所有角色使用同一个基准，避免跨午夜边界不一致。
        $characters = $readService->readCurrentMembers(CarbonImmutable::now('UTC'));
        $statusCounts = $groupingService->statusCounts($characters);
        $groups = $groupingService->filterAndGroup($characters, $status, $lastSeen, $search);

        // 以账号组分页而非按角色分页，保证主/子角色列表不会在相邻页面失去其分组标题。
        $totalGroups = count($groups);
        $pageGroups = array_slice($groups, ($page - 1) * $perPage, $perPage);
        $paginationParameters = [
            'status'    => $status,
            'last_seen' => $lastSeen,
            'q'         => $search,
            'per_page'  => $perPage,
        ];
        $auditGroups = (new LengthAwarePaginator(
            $pageGroups,
            $totalGroups,
            $perPage,
            $page,
            [
                'path'     => request()->url(),
                'pageName' => 'page',
            ],
        ))->appends($paginationParameters);

        $statusLabels = [
            'all'     => '所有追踪',
            'normal'  => '状态正常',
            'expired' => '账号过期',
            'unbound' => '无 SeAT 用户',
        ];
        $lastSeenLabels = [
            'all'       => '全部上线时间',
            'within_30' => '30 天内上线',
            'within_60' => '60 天内上线',
        ];

        return view('seat-audit-monitor::token-audit.index', compact(
            'auditGroups',
            'status',
            'lastSeen',
            'search',
            'perPage',
            'statusCounts',
            'statusLabels',
            'lastSeenLabels',
        ));
    }

    /**
     * @param array<int, string> $allowedValues
     */
    private function allowedValue(string $value, array $allowedValues, string $fallback): string
    {
        return in_array($value, $allowedValues, true) ? $value : $fallback;
    }

    private function allowedPerPage(mixed $value): int
    {
        $perPage = (int) $value;

        return in_array($perPage, self::ALLOWED_PER_PAGE, true) ? $perPage : 25;
    }

    private function limitSearch(string $search): string
    {
        $search = trim($search);

        return function_exists('mb_substr')
            ? mb_substr($search, 0, 100, 'UTF-8')
            : substr($search, 0, 100);
    }
}
