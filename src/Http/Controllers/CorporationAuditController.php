<?php

// src/Http/Controllers/CorporationAuditController.php
// 固定军团 98588384 的 ISK 捐赠与成员低价合同浏览、异步扫描状态、未知实体解析和 CSV 导出入口。

namespace Seat\SeatAuditMonitor\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Seat\SeatAuditMonitor\Enums\AuditType;
use Seat\SeatAuditMonitor\Jobs\AuditDonationsJob;
use Seat\SeatAuditMonitor\Jobs\AuditMemberContractsJob;
use Seat\SeatAuditMonitor\Jobs\ResolveUnknownNamesJob;
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
        $keyword = $this->keyword();
        $auditType = $this->requestedAuditType();
        $allowedAuditTypes = AuditType::corporationAuditValues();
        $auditTypeLabels = AuditType::corporationAuditLabels();

        $violations = $this->corporationViolationsQuery($auditType, $startDate, $endDate, $keyword)
            ->paginate(50)
            ->appends($this->filterParameters($auditType, $startDate, $endDate, $keyword));

        // member_contracts 一条记录对应整份合同，物品位于 details.items。先收集当前页的所有
        // type_id 再一次性查询 SDE，避免每行合同或每件物品各查询一次。
        $itemNames = $this->itemNamesFor($violations->getCollection());
        // 合同详情保留原始快照，但为快照中的 ID 附加本地缓存 display_name；不以当前 affiliation
        // 改写历史 corporation_id，从而让管理员可在解析 Unknown 后直接看到补全结果。
        $entityNames = $this->entityNamesFor($violations->getCollection());
        $violations->setCollection($violations->getCollection()->map(
            fn (object $violation): object => $this->presentViolation($violation, $itemNames, $entityNames)
        ));

        // scan_token 只用于恢复本浏览器的一次性轮询；非法值不传给前端或状态端点。
        $scanToken = $this->validScanToken((string) request('scan_token', ''));
        $scanStatusUrl = $scanToken === null
            ? null
            : route('seat-audit.corporation-audit.scan-status', ['token' => $scanToken]);
        $scanRefreshUrl = route('seat-audit.corporation-audit.index', $this->filterParameters(
            $auditType,
            $startDate,
            $endDate,
            $keyword,
        ));

        return view('seat-audit-monitor::corporation-audit.index', compact(
            'violations',
            'startDate',
            'endDate',
            'keyword',
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
        $redirectParameters = $this->filterParameters(
            is_string($auditType) ? $auditType : null,
            request('start_date'),
            request('end_date'),
            $this->keyword(),
        );

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
     * 从军团审计页异步提交未知实体解析。
     *
     * 名称解析可能调用公开 ESI，绝不能放在 Web 请求内同步执行；Job 会覆盖旧违规和 2.0 审计
     * 的 Unknown 参与方，并把补全名称写入 SeAT 本地缓存。该操作不改写军团审计的历史军团快照。
     */
    public function resolveUnknown()
    {
        if (Gate::denies('seat-audit-monitor.admin')) {
            abort(403, '您没有权限执行未知来源解析。');
        }

        $auditType = $this->requestedAuditType();
        $parameters = $this->filterParameters(
            $auditType,
            request('start_date'),
            request('end_date'),
            $this->keyword(),
        );

        try {
            dispatch(new ResolveUnknownNamesJob());
        } catch (\Throwable) {
            return redirect()->route('seat-audit.corporation-audit.index', $parameters)
                ->with('error', '未知来源解析任务提交失败，请检查队列服务后重试。');
        }

        return redirect()->route('seat-audit.corporation-audit.index', $parameters)
            ->with('success', '未知来源解析任务已加入队列。任务完成后刷新本页即可查看补全的角色、实体和军团名称。');
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
        $keyword = $this->keyword();
        $auditType = $this->requestedAuditType();
        $filename = 'seat-corporation-audit-' . str_replace('_', '-', $auditType)
            . '-' . now('UTC')->format('Ymd_His') . '-UTC.csv';

        $query = $this->corporationViolationsQuery($auditType, $startDate, $endDate, $keyword);

        return response()->stream(function () use ($query): void {
            $handle = fopen('php://output', 'w');

            // UTF-8 BOM 使 Excel/WPS 能正确识别中文角色名、方向和表头。
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, [
                '发起方',
                '发起方军团',
                '接收方',
                '接收方军团',
                '物品名称',
                '交易金额（ISK）',
                '来源',
                '合同详情',
                '发生时间',
            ]);

            /** @var array<int, string> $itemNames */
            $itemNames = [];
            // cursor() 逐行消费 PDO 结果，不将全量导出记录加载到 PHP 内存。合同物品名称只在
            // 本次导出内缓存；每条记录只批量查询其尚未缓存的 type_id，避免按物品 N+1 查询。
            foreach ($query->cursor() as $violation) {
                $this->appendMissingItemNames($itemNames, $this->contractItemTypeIds($violation));
                $record = $this->presentViolation($violation, $itemNames);

                fputcsv($handle, array_map($this->csvCell(...), [
                    $record->initiator_name,
                    $this->corporationDisplay($record->initiator_corporation_name, $record->initiator_corporation_ticker, $record->initiator_corporation_id),
                    $record->recipient_name,
                    $this->corporationDisplay($record->recipient_corporation_name, $record->recipient_corporation_ticker, $record->recipient_corporation_id),
                    $record->item_summary,
                    $record->formatted_amount,
                    $record->source_label . ($record->source_reference === null ? '' : '（' . $record->source_reference . '）'),
                    $record->contract_id === null ? '' : '#' . $record->contract_id,
                    $record->violation_time,
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
     * 日期和关键词条件只过滤已入库违规，不改变扫描 Job 的 audit_from 或 cursor。双方军团名称
     * 仅从扫描时写入的军团 ID 快照补全；不能使用 character_affiliations 的当前归属替代历史事实。
     */
    private function corporationViolationsQuery(string $auditType, mixed $startDate, mixed $endDate, string $keyword)
    {
        $query = DB::table('seat_audit_violations')
            ->leftJoin('corporation_infos as member_corp_info', 'member_corp_info.corporation_id', '=', 'seat_audit_violations.character_corporation_id')
            ->leftJoin('universe_names as member_corp_un', function ($join) {
                $join->on('member_corp_un.entity_id', '=', 'seat_audit_violations.character_corporation_id')
                    ->where('member_corp_un.category', '=', 'corporation');
            })
            ->leftJoin('corporation_infos as external_corp_info', 'external_corp_info.corporation_id', '=', 'seat_audit_violations.counterparty_corporation_id')
            ->leftJoin('universe_names as external_corp_un', function ($join) {
                $join->on('external_corp_un.entity_id', '=', 'seat_audit_violations.counterparty_corporation_id')
                    ->where('external_corp_un.category', '=', 'corporation');
            })
            ->where('seat_audit_violations.audit_corporation_id', AuditCorporation::TARGET_CORPORATION_ID)
            ->where('seat_audit_violations.audit_type', $auditType)
            ->select([
                'seat_audit_violations.*',
                DB::raw('COALESCE(member_corp_info.name, member_corp_un.name) as member_corporation_name'),
                'member_corp_info.ticker as member_corporation_ticker',
                DB::raw('COALESCE(external_corp_info.name, external_corp_un.name) as external_corporation_name'),
                'external_corp_info.ticker as external_corporation_ticker',
            ])
            ->orderBy('seat_audit_violations.violation_time', 'desc')
            ->orderBy('seat_audit_violations.id', 'desc');

        if ($startDate) {
            $query->where('seat_audit_violations.violation_time', '>=', $startDate . ' 00:00:00');
        }
        if ($endDate) {
            $query->where('seat_audit_violations.violation_time', '<=', $endDate . ' 23:59:59');
        }
        if ($keyword !== '') {
            $like = '%' . $keyword . '%';
            $query->where(function ($keywordQuery) use ($like) {
                $keywordQuery->where('seat_audit_violations.character_name', 'LIKE', $like)
                    ->orWhere('seat_audit_violations.counterparty_name', 'LIKE', $like)
                    ->orWhere('member_corp_info.name', 'LIKE', $like)
                    ->orWhere('member_corp_info.ticker', 'LIKE', $like)
                    ->orWhere('member_corp_un.name', 'LIKE', $like)
                    ->orWhere('external_corp_info.name', 'LIKE', $like)
                    ->orWhere('external_corp_info.ticker', 'LIKE', $like)
                    ->orWhere('external_corp_un.name', 'LIKE', $like);
            });
        }

        return $query;
    }

    /**
     * 将一条“成员 / 外部方”结构的 2.0 违规转换为页面和 CSV 共用的“发起方 / 接收方”视图。
     *
     * 顶层字段不可直接照搬到旧页面列：它们的含义固定为成员与外部方。只有 direction 才决定
     * 资金或合同的业务方向。未知 direction 必须可见地降级，不能无声地伪装为任一正常方向。
     *
     * @param array<int, string> $itemNames
     * @param array<string, array<int, string>> $entityNames
     */
    private function presentViolation(object $violation, array $itemNames, array $entityNames = []): object
    {
        $record = clone $violation;
        $record->details = $this->enrichPartyDisplayNames(
            $this->detailsArray($violation->details ?? null),
            $entityNames,
        );

        $member = [
            'id'                 => $this->positiveInteger($violation->member_character_id ?? $violation->character_id ?? null),
            'name'               => (string) ($violation->character_name ?? 'Unknown'),
            'corporation_id'     => $this->positiveInteger($violation->character_corporation_id ?? null),
            'corporation_name'   => $this->nullableString($violation->member_corporation_name ?? null),
            'corporation_ticker' => $this->nullableString($violation->member_corporation_ticker ?? null),
        ];
        $external = [
            'id'                 => $this->positiveInteger($violation->external_party_id ?? $violation->counterparty_id ?? null),
            'name'               => (string) ($violation->counterparty_name ?? 'Unknown'),
            'corporation_id'     => $this->positiveInteger($violation->counterparty_corporation_id ?? null),
            'corporation_name'   => $this->nullableString($violation->external_corporation_name ?? null),
            'corporation_ticker' => $this->nullableString($violation->external_corporation_ticker ?? null),
        ];

        if ($violation->direction === 'outbound') {
            $initiator = $member;
            $recipient = $external;
            $record->direction_label = '成员转出';
        } elseif ($violation->direction === 'inbound') {
            $initiator = $external;
            $recipient = $member;
            $record->direction_label = '成员接收';
        } else {
            // 旧数据或人工导入记录若方向异常，仍保留成员/外部方原顺序并显式告知，方便审计追查。
            $initiator = $member;
            $recipient = $external;
            $record->direction_label = '未知方向（成员 / 外部方）';
        }

        $record->initiator_id = $initiator['id'];
        $record->initiator_name = $initiator['name'];
        $record->initiator_corporation_id = $initiator['corporation_id'];
        $record->initiator_corporation_name = $initiator['corporation_name'];
        $record->initiator_corporation_ticker = $initiator['corporation_ticker'];
        $record->recipient_id = $recipient['id'];
        $record->recipient_name = $recipient['name'];
        $record->recipient_corporation_id = $recipient['corporation_id'];
        $record->recipient_corporation_name = $recipient['corporation_name'];
        $record->recipient_corporation_ticker = $recipient['corporation_ticker'];
        $record->item_summary = $this->itemSummary($violation, $record->details, $itemNames);
        // 弹窗展示完整 items 时使用同一批 SDE 名称，保证表格摘要与详情不会出现不同名称口径。
        $record->contract_items = $this->contractItemsWithNames($record->details, $itemNames);
        $record->formatted_amount = $this->formatIsk($violation->amount ?? null);

        [$record->source_label, $record->source_class] = $this->sourcePresentation($violation);
        $record->source_reference = $this->nullableString($violation->source_reference ?? null);
        $record->contract_id = $this->positiveInteger($violation->contract_id ?? null);

        return $record;
    }

    /**
     * 当前分页中的成员合同物品按 type_id 收集后一次性读 SDE 名称。
     *
     * @param Collection<int, object> $violations
     * @return array<int, string>
     */
    private function itemNamesFor(Collection $violations): array
    {
        $typeIds = [];
        foreach ($violations as $violation) {
            foreach ($this->contractItemTypeIds($violation) as $typeId) {
                $typeIds[$typeId] = $typeId;
            }
        }

        $itemNames = [];
        $this->appendMissingItemNames($itemNames, array_values($typeIds));

        return $itemNames;
    }

    /**
     * 将尚未解析的 type ID 一次性从 SDE 缓存到当前页面/导出过程。
     *
     * @param array<int, string> $itemNames
     * @param array<int, int> $typeIds
     */
    private function appendMissingItemNames(array &$itemNames, array $typeIds): void
    {
        $missingTypeIds = array_values(array_filter(
            array_unique($typeIds),
            static fn (int $typeId): bool => ! array_key_exists($typeId, $itemNames),
        ));
        if ($missingTypeIds === []) {
            return;
        }

        $resolved = DB::table('invTypes')
            ->whereIn('typeID', $missingTypeIds)
            ->pluck('typeName', 'typeID')
            ->mapWithKeys(static fn ($name, $typeId): array => [(int) $typeId => (string) $name])
            ->all();

        // 未在当前 SDE 中的 type ID 也缓存为空字符串，避免导出中相同未知 ID 重复查询。
        foreach ($missingTypeIds as $typeId) {
            $itemNames[$typeId] = $resolved[$typeId] ?? '';
        }
    }

    /**
     * @return array<int, int>
     */
    private function contractItemTypeIds(object $violation): array
    {
        if (($violation->audit_type ?? null) !== AuditType::MemberContracts->value) {
            return [];
        }

        $typeIds = [];
        foreach ($this->detailsArray($violation->details ?? null)['items'] ?? [] as $item) {
            if (! is_array($item)) {
                continue;
            }

            $typeId = $this->positiveInteger($item['type_id'] ?? null);
            if ($typeId !== null) {
                $typeIds[$typeId] = $typeId;
            }
        }

        return array_values($typeIds);
    }

    /**
     * 返回成员低价合同的物品摘要。Donation 没有物品来源，必须明确标注而非伪造物品。
     *
     * @param array<string, mixed> $details
     * @param array<int, string> $itemNames
     */
    private function itemSummary(object $violation, array $details, array $itemNames): string
    {
        if (($violation->audit_type ?? null) === AuditType::IskDonations->value) {
            return '无物品（ISK 捐赠）';
        }

        $items = $details['items'] ?? null;
        if (! is_array($items) || $items === []) {
            return '未同步物品明细';
        }

        // 以 type_id + is_included 聚合：同种物品若一部分是合同提供物、一部分是交换要求，必须
        // 保持两种业务语义，不可简单合并为一个“转移数量”。
        $summaries = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $typeId = $this->positiveInteger($item['type_id'] ?? null);
            if ($typeId === null) {
                continue;
            }
            $included = (int) ($item['is_included'] ?? 0) === 1;
            $quantity = max(0, (int) ($item['quantity'] ?? 0));
            $key = $typeId . ':' . ($included ? 'included' : 'requested');
            $summaries[$key] = [
                'type_id'  => $typeId,
                'included' => $included,
                'quantity' => ($summaries[$key]['quantity'] ?? 0) + $quantity,
            ];
        }
        if ($summaries === []) {
            return '未同步物品明细';
        }

        $labels = [];
        foreach ($summaries as $summary) {
            $typeId = $summary['type_id'];
            $name = $itemNames[$typeId] ?? '';
            $displayName = $name === '' ? '未知物品 #' . $typeId : $name;
            $labels[] = $displayName . ' × ' . $summary['quantity'] . ($summary['included'] ? '（包含）' : '（需求）');
        }

        $visible = array_slice($labels, 0, 2);
        $suffix = count($labels) > 2 ? '；共 ' . count($labels) . ' 项' : '';

        return implode('；', $visible) . $suffix;
    }

    /**
     * 为成员合同的完整物品快照补齐 SDE 名称，保持原有 record_id、数量和 is_included 不变。
     *
     * @param array<string, mixed> $details
     * @param array<int, string> $itemNames
     * @return array<int, array<string, mixed>>
     */
    private function contractItemsWithNames(array $details, array $itemNames): array
    {
        $items = $details['items'] ?? [];
        if (! is_array($items)) {
            return [];
        }

        $presentedItems = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $typeId = $this->positiveInteger($item['type_id'] ?? null);
            $item['type_name'] = $typeId === null
                ? null
                : (($itemNames[$typeId] ?? '') === '' ? null : $itemNames[$typeId]);
            $presentedItems[] = $item;
        }

        return $presentedItems;
    }

    /**
     * 从当前分页的 2.0 合同 / Donation 参与方快照批量收集实体和军团 ID，并读取本地缓存名称。
     *
     * 这些名称仅用于 modal 的 display_name；原始快照 name、entity_type、corporation_id 不变，
     * 因而不会将现在的 affiliation 伪装为合同发生或扫描时的状态。
     *
     * @param Collection<int, object> $violations
     * @return array<string, array<int, string>>
     */
    private function entityNamesFor(Collection $violations): array
    {
        $characterIds = [];
        $corporationIds = [];
        $universeIds = [];

        foreach ($violations as $violation) {
            $details = $this->detailsArray($violation->details ?? null);
            $parties = $details['parties'] ?? [];
            if (! is_array($parties)) {
                continue;
            }

            foreach (['issuer', 'assignee', 'acceptor', 'donor', 'recipient'] as $partyKey) {
                $party = $parties[$partyKey] ?? null;
                if (! is_array($party)) {
                    continue;
                }

                $entityId = $this->positiveInteger($party['id'] ?? null);
                $corporationId = $this->positiveInteger($party['corporation_id'] ?? null);
                if ($entityId !== null) {
                    $universeIds[$entityId] = $entityId;
                    if (($party['entity_type'] ?? null) === 'character') {
                        $characterIds[$entityId] = $entityId;
                    }
                    if (($party['entity_type'] ?? null) === 'corporation') {
                        $corporationIds[$entityId] = $entityId;
                    }
                }
                if ($corporationId !== null) {
                    $corporationIds[$corporationId] = $corporationId;
                    $universeIds[$corporationId] = $corporationId;
                }
            }
        }

        $characterNames = $characterIds === []
            ? []
            : DB::table('character_infos')->whereIn('character_id', array_values($characterIds))
                ->pluck('name', 'character_id')
                ->mapWithKeys(static fn ($name, $id): array => [(int) $id => (string) $name])
                ->all();
        $corporationNames = $corporationIds === []
            ? []
            : DB::table('corporation_infos')->whereIn('corporation_id', array_values($corporationIds))
                ->pluck('name', 'corporation_id')
                ->mapWithKeys(static fn ($name, $id): array => [(int) $id => (string) $name])
                ->all();
        $universeNames = $universeIds === []
            ? []
            : DB::table('universe_names')->whereIn('entity_id', array_values($universeIds))
                ->pluck('name', 'entity_id')
                ->mapWithKeys(static fn ($name, $id): array => [(int) $id => (string) $name])
                ->all();

        return [
            'characters' => $characterNames,
            'corporations' => $corporationNames,
            'universe' => $universeNames,
        ];
    }

    /**
     * 在 parties 快照上增添仅供显示的名称，供 Blade 的 modal 优先使用缓存解析值。
     *
     * @param array<string, mixed> $details
     * @param array<string, array<int, string>> $entityNames
     * @return array<string, mixed>
     */
    private function enrichPartyDisplayNames(array $details, array $entityNames): array
    {
        $characterNames = $entityNames['characters'] ?? [];
        $corporationNames = $entityNames['corporations'] ?? [];
        $universeNames = $entityNames['universe'] ?? [];
        $parties = $details['parties'] ?? null;
        if (! is_array($parties)) {
            return $details;
        }

        foreach (['issuer', 'assignee', 'acceptor', 'donor', 'recipient'] as $partyKey) {
            $party = $parties[$partyKey] ?? null;
            if (! is_array($party)) {
                continue;
            }

            $entityId = $this->positiveInteger($party['id'] ?? null);
            $corporationId = $this->positiveInteger($party['corporation_id'] ?? null);
            $entityType = $party['entity_type'] ?? null;
            $displayName = $entityId === null ? null : match ($entityType) {
                'character' => $characterNames[$entityId] ?? $universeNames[$entityId] ?? null,
                'corporation' => $corporationNames[$entityId] ?? $universeNames[$entityId] ?? null,
                'alliance' => $universeNames[$entityId] ?? null,
                default => $universeNames[$entityId] ?? null,
            };
            $displayCorporationName = $corporationId === null
                ? null
                : ($corporationNames[$corporationId] ?? $universeNames[$corporationId] ?? null);

            // 缓存找不到时保留历史 name/corporation_name；缓存存在时只增强显示，不改动事实快照字段。
            $party['display_name'] = $displayName ?? ($party['name'] ?? null);
            $party['display_corporation_name'] = $displayCorporationName ?? ($party['corporation_name'] ?? null);
            $parties[$partyKey] = $party;
        }

        $details['parties'] = $parties;

        return $details;
    }

    /**
     * 来源列只表达审计事件类型、合同可见性和来源引用，不把未经验证的地点字段误称为“位置来源”。
     *
     * @return array{0: string, 1: string}
     */
    private function sourcePresentation(object $violation): array
    {
        if (($violation->audit_type ?? null) === AuditType::IskDonations->value) {
            return ['ISK 捐赠', 'badge-success'];
        }

        $availability = $violation->contract_availability ?? null;
        $labels = [
            'public'      => '合同·公开',
            'personal'    => '合同·私人',
            'corporation' => '合同·军团',
            'alliance'    => '合同·联盟',
        ];
        $classes = [
            'public'      => 'badge-success',
            'personal'    => 'badge-warning',
            'corporation' => 'badge-info',
            'alliance'    => 'badge-primary',
        ];

        return [$labels[$availability] ?? '合同', $classes[$availability] ?? 'badge-secondary'];
    }

    /**
     * 将 decimal 文本格式化为千分位 + 两位小数，不经过 PHP float，避免大额 ISK 精度失真。
     */
    private function formatIsk(mixed $amount): string
    {
        $value = trim((string) ($amount ?? '0'));
        if (preg_match('/^(-?)([0-9]+)(?:\.([0-9]+))?$/', $value, $matches) !== 1) {
            return $value === '' ? '0.00' : $value;
        }

        $integer = ltrim($matches[2], '0') ?: '0';
        $fraction = substr(str_pad($matches[3] ?? '', 2, '0'), 0, 2);
        $grouped = preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $integer) ?? $integer;

        return $matches[1] . $grouped . '.' . $fraction;
    }

    private function corporationDisplay(?string $name, ?string $ticker, ?int $corporationId): string
    {
        if ($ticker !== null && $ticker !== '') {
            return $name === null || $name === '' ? $ticker : $name . ' [' . $ticker . ']';
        }
        if ($name !== null && $name !== '') {
            return $name;
        }

        return $corporationId === null ? '—' : '未知军团 #' . $corporationId;
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

    private function keyword(): string
    {
        $keyword = trim((string) request('keyword', ''));

        return function_exists('mb_substr')
            ? mb_substr($keyword, 0, 100, 'UTF-8')
            : substr($keyword, 0, 100);
    }

    /**
     * @return array<string, string>
     */
    private function filterParameters(?string $auditType, mixed $startDate, mixed $endDate, string $keyword): array
    {
        return array_filter([
            'audit_type' => $auditType,
            'start_date' => is_string($startDate) ? $startDate : null,
            'end_date'   => is_string($endDate) ? $endDate : null,
            'keyword'    => $keyword,
        ], static fn ($value): bool => $value !== null && $value !== '');
    }

    /**
     * @return array<string, mixed>
     */
    private function detailsArray(mixed $details): array
    {
        if (is_array($details)) {
            return $details;
        }
        if (! is_string($details) || $details === '') {
            return [];
        }

        $decoded = json_decode($details, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function positiveInteger(mixed $value): ?int
    {
        $normalized = trim((string) ($value ?? ''));
        if (preg_match('/^[1-9][0-9]*$/', $normalized) !== 1) {
            return null;
        }

        return (int) $normalized;
    }

    private function nullableString(mixed $value): ?string
    {
        $normalized = trim((string) ($value ?? ''));

        return $normalized === '' ? null : $normalized;
    }

    /**
     * CSV 可被 Excel/WPS 解释为公式；角色名、军团名、来源引用和物品名均来自外部同步数据，不可信。
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
