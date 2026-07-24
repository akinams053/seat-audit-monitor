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
    private const ALLOWED_JOINED_WITHIN_FILTERS = ['all', 'within_30', 'within_60'];
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
        // last_seen 沿用既有 URL 参数，筛选数据源在读取服务中改为 character_onlines.last_login。
        $lastSeen = $this->allowedValue((string) request('last_seen', 'all'), self::ALLOWED_LAST_SEEN_FILTERS, 'all');
        // 入团范围与最后上线独立组合，非法参数必须回退为全部，避免用户输入产生不可预测的筛选结果。
        $joinedWithin = $this->allowedValue((string) request('joined_within', 'all'), self::ALLOWED_JOINED_WITHIN_FILTERS, 'all');
        $perPage = $this->allowedPerPage(request('per_page'));
        $page = max(1, (int) request('page', 1));
        $search = $this->limitSearch((string) request('q', ''));

        // 审查基准固定为本次 HTTP 请求开始时的 UTC；同一页所有角色使用同一个基准，避免跨午夜边界不一致。
        $characters = $readService->readCurrentMembers(CarbonImmutable::now('UTC'));
        $statusCounts = $groupingService->statusCounts($characters);
        $groups = $groupingService->filterAndGroup($characters, $status, $lastSeen, $joinedWithin, $search);

        // 以账号组分页而非按角色分页，保证主/子角色列表不会在相邻页面失去其分组标题。
        $totalGroups = count($groups);
        $pageGroups = array_slice($groups, ($page - 1) * $perPage, $perPage);
        $paginationParameters = [
            'status'        => $status,
            'last_seen'     => $lastSeen,
            'joined_within' => $joinedWithin,
            'q'             => $search,
            'per_page'      => $perPage,
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
        $joinedWithinLabels = [
            'all'       => '全部入团时间',
            'within_30' => '30 天内入团',
            'within_60' => '60 天内入团',
        ];

        return view('seat-audit-monitor::token-audit.index', compact(
            'auditGroups',
            'status',
            'lastSeen',
            'joinedWithin',
            'search',
            'perPage',
            'statusCounts',
            'statusLabels',
            'lastSeenLabels',
            'joinedWithinLabels',
        ));
    }

    /**
     * 将当前令牌审查筛选结果导出为 CSV。
     *
     * 导出刻意复用列表页的读取与筛选链路，但不应用账号组分页：
     * CSV 必须包含全部命中筛选条件的角色行，而不是当前页面的一小段账号组。
     * 读取服务只返回脱敏 DTO，因此这里无法也绝不能接触 token、refresh token、scope、JWT 或 expires_on。
     */
    public function export(
        SeatTokenAuditReadService $readService,
        SeatTokenAuditGroupingService $groupingService,
    ) {
        if (Gate::denies('seat-audit-monitor.view')) {
            abort(403, '您没有权限导出令牌审查记录。');
        }

        // 与 index() 完全使用同一组业务筛选；刻意忽略 page/per_page，避免导出被当前浏览页截断。
        $status = $this->allowedValue((string) request('status', 'all'), self::ALLOWED_STATUSES, 'all');
        $lastSeen = $this->allowedValue((string) request('last_seen', 'all'), self::ALLOWED_LAST_SEEN_FILTERS, 'all');
        $joinedWithin = $this->allowedValue((string) request('joined_within', 'all'), self::ALLOWED_JOINED_WITHIN_FILTERS, 'all');
        $search = $this->limitSearch((string) request('q', ''));

        // 同一份 UTC 审查基准同时驱动读取、时间分段和 CSV 精确时间，避免导出过程跨午夜改变筛选边界。
        $asOf = CarbonImmutable::now('UTC');
        $characters = $readService->readCurrentMembers($asOf);
        $groups = $groupingService->filterAndGroup($characters, $status, $lastSeen, $joinedWithin, $search);
        $filename = 'seat-token-audit-' . $asOf->format('Ymd_His') . '-UTC.csv';

        $headers = [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control'       => 'no-cache, no-store, must-revalidate',
            'Pragma'              => 'no-cache',
            'Expires'             => '0',
        ];

        return response()->stream(function () use ($groups): void {
            $handle = fopen('php://output', 'w');

            // UTF-8 BOM 使 Excel/WPS 以 UTF-8 正确识别中文标题、角色名与头衔。
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, [
                '令牌状态',
                '主角色 ID',
                '主角色名',
                '主角色是否当前成员范围',
                '角色 ID',
                '角色名',
                '是否主角色',
                '角色头衔',
                '入团时间（UTC）',
                '最后上线（UTC）',
            ]);

            foreach ($groups as $group) {
                foreach ($group->characters as $character) {
                    // 没有 SeAT 用户的角色没有主角色；已绑定但未配置主角色时也必须明确区分，不能泄露内部 user_id 或 group key。
                    $primaryCharacterName = $character->primaryCharacterId === null
                        ? ($character->seatUserId === null ? '独立角色（无 SeAT 用户）' : '—（主角色未配置）')
                        : $character->primaryCharacterName;
                    $primaryInScope = $character->primaryCharacterId === null
                        ? '—（未配置）'
                        : ($group->primaryCharacterInScope ? '是' : '否');

                    // tooltip 已是稳定的精确 UTC 时间；缺失或无效值没有可审计时间时保留页面的明确状态标签。
                    $joinedAt = $character->joinedAt->tooltip ?? $character->joinedAt->label;
                    $lastLoginAt = $character->lastLoginAt->tooltip ?? $character->lastLoginAt->label;

                    fputcsv($handle, array_map($this->csvCell(...), [
                        $this->tokenStatusLabel($character->tokenStatus),
                        $character->primaryCharacterId ?? '',
                        $primaryCharacterName,
                        $primaryInScope,
                        $character->characterId,
                        $character->characterName,
                        $character->isPrimaryCharacter() ? '是' : '否',
                        $character->displayTitle(),
                        $joinedAt,
                        $lastLoginAt,
                    ]));
                }
            }

            fclose($handle);
        }, 200, $headers);
    }

    /**
     * CSV 可被 Excel/WPS 当作公式解析；外部同步的角色名、头衔等任何字段均不能信任。
     *
     * 保留原始值，只在前导空白/控制字符后的第一个可见字符是公式起始符时前置单引号，
     * 覆盖 =、+、-、@ 和 TAB/换行等常见绕过形式，同时不改写正常显示文本。
     */
    private function csvCell(mixed $value): string
    {
        $cell = (string) $value;
        $visibleValue = preg_replace('/^[\s\p{C}]*/u', '', $cell);
        if ($visibleValue === null) {
            // 数据库历史脏数据若不是有效 UTF-8，仍需至少覆盖 ASCII 空白和控制字符前缀。
            $visibleValue = ltrim($cell, " \t\n\r\0\x0B");
        }

        return $visibleValue !== '' && in_array($visibleValue[0], ['=', '+', '-', '@'], true)
            ? "'" . $cell
            : $cell;
    }

    /** @param 'normal'|'expired'|'unbound' $status */
    private function tokenStatusLabel(string $status): string
    {
        return match ($status) {
            'normal'  => '状态正常',
            'expired' => '账号过期',
            'unbound' => '无 SeAT 用户',
            default   => '未知状态',
        };
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
