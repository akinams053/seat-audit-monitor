{{-- src/resources/views/corporation-audit/index.blade.php --}}
{{-- 固定军团 98588384 的 2.0 审计结果页：以交易双方视角展示 Donation 与成员低价合同。 --}}

@extends('web::layouts.grids.12')

@section('title', '军团审计')

@section('full')
<div class="row">
    <div class="col-12">
        @php
            // 与旧违规记录保持一致的合同来源视觉语义；实际白名单规则仍只在 2.0 扫描写入时处理。
            $availabilityLabels = [
                'public'      => '公开',
                'personal'    => '私人',
                'corporation' => '军团',
                'alliance'    => '联盟',
            ];
        @endphp
        <div class="card mb-3">
            <div class="card-header d-flex p-0 with-border">
                <h3 class="card-title p-3 mb-0"><i class="fas fa-shield-alt"></i> 军团审计</h3>
                <ul class="nav nav-pills ml-auto p-2">
                    @foreach($allowedAuditTypes as $type)
                        @php
                            // 标签链接保留业务筛选但不保留页码；切换类型后应从新类型第一页开始显示。
                            $tabParameters = array_filter([
                                'audit_type' => $type,
                                'start_date' => $startDate,
                                'end_date'   => $endDate,
                                'keyword'    => $keyword,
                            ]);
                        @endphp
                        <li class="nav-item">
                            <a href="{{ route('seat-audit.corporation-audit.index', $tabParameters) }}"
                               class="nav-link {{ $auditType === $type ? 'active' : '' }}"
                               @if($auditType === $type) aria-current="page" @endif>
                                {{ $auditTypeLabels[$type] }}
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
            <div class="card-body">
                @if(session('success'))
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        {{ session('success') }}
                        <button type="button" class="close" data-dismiss="alert" aria-label="关闭"><span aria-hidden="true">&times;</span></button>
                    </div>
                @endif
                @if(session('error'))
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        {{ session('error') }}
                        <button type="button" class="close" data-dismiss="alert" aria-label="关闭"><span aria-hidden="true">&times;</span></button>
                    </div>
                @endif
                {{-- sessionStorage 的一次性完成提示在页面自动刷新后注入此容器，不依赖队列 worker 写 HTTP session。 --}}
                <div class="js-corporation-audit-scan-notice"></div>

                <p class="text-muted small mb-3">
                    审计范围：军团 <code>98588384</code> 的当前成员与外部方之间的 {{ $auditTypeLabels[$auditType] }}。
                    双方军团 ID 是扫描时快照，名称由 SeAT 本地缓存补全；日期和关键词仅筛选已入库记录，管理员扫描仍按 <code>audit_from</code> 与 cursor 异步增量执行。
                </p>

                <div class="d-flex flex-wrap align-items-center">
                    <form method="GET" action="{{ route('seat-audit.corporation-audit.index') }}" class="form-inline mr-2 mb-2">
                        {{-- 保持当前标签；展示筛选绝不改变实际扫描的 cursor 或 audit_from。 --}}
                        <input type="hidden" name="audit_type" value="{{ $auditType }}">
                        <div class="form-group mr-3">
                            <label for="start_date" class="mr-2">开始日期</label>
                            <input type="date" id="start_date" name="start_date" class="form-control form-control-sm" value="{{ $startDate ?? '' }}">
                        </div>
                        <div class="form-group mr-3">
                            <label for="end_date" class="mr-2">结束日期</label>
                            <input type="date" id="end_date" name="end_date" class="form-control form-control-sm" value="{{ $endDate ?? '' }}">
                        </div>
                        <div class="form-group mr-3">
                            <label for="keyword" class="mr-2">角色 / 军团</label>
                            <input type="text" id="keyword" name="keyword" class="form-control form-control-sm"
                                   value="{{ $keyword ?? '' }}" maxlength="100" placeholder="角色名 / 军团名 / ticker">
                        </div>
                        <button type="submit" class="btn btn-sm btn-primary mr-2"><i class="fas fa-filter"></i> 筛选</button>
                        <a href="{{ route('seat-audit.corporation-audit.index', ['audit_type' => $auditType]) }}" class="btn btn-sm btn-secondary"><i class="fas fa-times"></i> 清除</a>
                    </form>
                    {{-- 导出只继承业务筛选，不携带页码或 scan_token，下载全部匹配记录。 --}}
                    <a href="{{ route('seat-audit.corporation-audit.export', array_filter([
                        'audit_type' => $auditType,
                        'start_date' => $startDate,
                        'end_date' => $endDate,
                        'keyword' => $keyword,
                    ])) }}" class="btn btn-sm btn-outline-success mr-2 mb-2"><i class="fas fa-download"></i> 导出 CSV</a>

                    @can('seat-audit-monitor.admin')
                        {{-- 不能嵌套在 GET 筛选表单内；POST + CSRF 使刷新页面不会意外重复提交扫描。 --}}
                        <form method="POST" action="{{ route('seat-audit.corporation-audit.scan') }}" class="form-inline mb-2 mr-2 js-corporation-audit-scan">
                            @csrf
                            <input type="hidden" name="audit_type" value="{{ $auditType }}">
                            <input type="hidden" name="start_date" value="{{ $startDate ?? '' }}">
                            <input type="hidden" name="end_date" value="{{ $endDate ?? '' }}">
                            <input type="hidden" name="keyword" value="{{ $keyword ?? '' }}">
                            <button type="submit" class="btn btn-sm btn-warning" title="仅提交当前标签的异步扫描任务；完成后本页会自动刷新">
                                <i class="fas fa-play"></i> 扫描{{ $auditTypeLabels[$auditType] }}
                            </button>
                        </form>
                        {{-- 解析任务独立入队，补全 Unknown 角色/实体和军团名称；不修改历史军团快照。 --}}
                        <form method="POST" action="{{ route('seat-audit.corporation-audit.resolve-unknown') }}" class="form-inline mb-2">
                            @csrf
                            <input type="hidden" name="audit_type" value="{{ $auditType }}">
                            <input type="hidden" name="start_date" value="{{ $startDate ?? '' }}">
                            <input type="hidden" name="end_date" value="{{ $endDate ?? '' }}">
                            <input type="hidden" name="keyword" value="{{ $keyword ?? '' }}">
                            <button type="submit" class="btn btn-sm btn-info" title="异步解析 Unknown 角色、外部实体及相关军团/联盟名称">
                                <i class="fas fa-sync"></i> 解析未知来源
                            </button>
                        </form>
                        @if($scanToken !== null && $scanStatusUrl !== null)
                            {{-- 仅管理员可轮询状态；令牌是短 TTL Cache key，不包含审计来源或队列 payload。 --}}
                            <div id="corporation_audit_scan_progress"
                                 class="small text-muted mb-2"
                                 data-status-url="{{ $scanStatusUrl }}"
                                 data-refresh-url="{{ $scanRefreshUrl }}"></div>
                        @endif
                    @endcan
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3 class="card-title">{{ $auditTypeLabels[$auditType] }}记录 <small class="text-muted ml-2">共 {{ $violations->total() }} 条</small></h3>
                <div class="card-tools text-muted small">成员/外部方会按实际资金或合同方向映射为发起方和接收方。</div>
            </div>
            <div class="card-body p-0">
                @if($violations->isEmpty())
                    <div class="p-3 text-muted">当前筛选条件下暂无{{ $auditTypeLabels[$auditType] }}记录。</div>
                @else
                    <div class="table-responsive">
                        <table class="table table-striped table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>发起方</th>
                                    <th>发起方军团</th>
                                    <th>接收方</th>
                                    <th>接收方军团</th>
                                    <th>物品名称</th>
                                    <th>交易金额 (ISK)</th>
                                    <th>来源</th>
                                    <th>合同详情</th>
                                    <th>发生时间</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($violations as $v)
                                    @php
                                        // Contract modal 只接收已入库的 2.0 快照，点击不会调用 ESI 或读取实时合同。
                                        $contractModalPayload = $v->audit_type === 'member_contracts' ? [
                                            'details' => $v->details,
                                            'amount' => $v->formatted_amount,
                                            'violation_time' => $v->violation_time,
                                            'contract_id' => $v->contract_id,
                                            'contract_availability' => $v->contract_availability,
                                            'item_summary' => $v->item_summary,
                                            'contract_items' => $v->contract_items,
                                            'direction_label' => $v->direction_label,
                                            'initiator_name' => $v->initiator_name,
                                            'recipient_name' => $v->recipient_name,
                                        ] : null;
                                    @endphp
                                    <tr class="{{ $v->audit_type === 'member_contracts' && $v->formatted_amount === '0.00' ? 'table-secondary' : '' }}">
                                        <td>
                                            {{ $v->initiator_name }}
                                            @if($v->initiator_id !== null)<small class="text-muted">#{{ $v->initiator_id }}</small>@endif
                                        </td>
                                        <td>
                                            @if($v->initiator_corporation_ticker)
                                                <span title="{{ $v->initiator_corporation_name }}">{{ $v->initiator_corporation_ticker }}</span>
                                            @elseif($v->initiator_corporation_name)
                                                {{ $v->initiator_corporation_name }}
                                            @elseif($v->initiator_corporation_id)
                                                <span class="text-muted">未知军团 #{{ $v->initiator_corporation_id }}</span>
                                            @else
                                                -
                                            @endif
                                        </td>
                                        <td>
                                            {{ $v->recipient_name }}
                                            @if($v->recipient_id !== null)<small class="text-muted">#{{ $v->recipient_id }}</small>@endif
                                        </td>
                                        <td>
                                            @if($v->recipient_corporation_ticker)
                                                <span title="{{ $v->recipient_corporation_name }}">{{ $v->recipient_corporation_ticker }}</span>
                                            @elseif($v->recipient_corporation_name)
                                                {{ $v->recipient_corporation_name }}
                                            @elseif($v->recipient_corporation_id)
                                                <span class="text-muted">未知军团 #{{ $v->recipient_corporation_id }}</span>
                                            @else
                                                -
                                            @endif
                                        </td>
                                        <td class="small">{{ $v->item_summary }}</td>
                                        <td>
                                            {{ $v->formatted_amount }}
                                            @if($v->audit_type === 'member_contracts' && $v->formatted_amount === '0.00')
                                                <small class="text-muted">(零金额)</small>
                                            @endif
                                        </td>
                                        <td>
                                            <span class="badge {{ $v->source_class }}">{{ $v->source_label }}</span>
                                            @if($v->source_reference)
                                                <small class="text-muted d-block"><code>{{ $v->source_reference }}</code></small>
                                            @endif
                                            @if($v->direction !== 'outbound' && $v->direction !== 'inbound')
                                                <small class="text-danger d-block">{{ $v->direction_label }}</small>
                                            @endif
                                        </td>
                                        <td>
                                            @if($v->audit_type === 'member_contracts' && $v->contract_id !== null)
                                                <button type="button" class="btn btn-outline-primary btn-sm corporation-contract-detail-btn"
                                                        data-toggle="modal" data-target="#corporationContractDetailModal"
                                                        {{-- JSON 放在单引号 HTML 属性中，必须 hex-escape 引号、标签和 &，防止外部标题或角色名突破属性边界。 --}}
                                                        data-violation='@json($contractModalPayload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)'
                                                        title="点击查看合同详情 (ID: {{ $v->contract_id }})">
                                                    <i class="fas fa-file-contract"></i> #{{ $v->contract_id }}
                                                </button>
                                            @else
                                                <span class="text-muted">—</span>
                                            @endif
                                        </td>
                                        <td>{{ $v->violation_time }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
            @if($violations->hasPages())
                <div class="card-footer">{{ $violations->links() }}</div>
            @endif
        </div>
    </div>
</div>

{{-- 仅成员低价合同使用。Donation 无合同数据，不显示伪造的合同或地点信息。 --}}
<div class="modal fade" id="corporationContractDetailModal" tabindex="-1" role="dialog" aria-labelledby="corporationContractDetailModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="corporationContractDetailModalLabel">成员低价合同详情</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="关闭"><span aria-hidden="true">&times;</span></button>
            </div>
            <div class="modal-body">
                <h6 class="text-muted"><i class="fas fa-file-contract"></i> 合同信息</h6>
                <table class="table table-sm table-bordered mb-3" id="corporationContractInfoTable"><tbody></tbody></table>

                <h6 class="text-muted"><i class="fas fa-cube"></i> 合同物品明细</h6>
                <p class="small text-muted" id="corporationContractItemSummary"></p>
                <table class="table table-sm table-bordered mb-3" id="corporationContractItemTable"><thead><tr><th>物品名称</th><th>Type ID</th><th>数量</th><th>合同语义</th><th>原始记录</th></tr></thead><tbody></tbody></table>

                <h6 class="text-muted"><i class="fas fa-users"></i> 三方实体快照</h6>
                <table class="table table-sm table-bordered mb-3" id="corporationContractPartiesTable"><tbody></tbody></table>

                <div class="text-muted small">
                    <i class="fas fa-info-circle"></i> 合同、物品和参与方来自违规发生时的快照。军团 ID 是扫描快照；后续名称解析只补全显示名称，不会把当前 affiliation 改写为历史归属。
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">关闭</button></div>
        </div>
    </div>
</div>
@stop

@push('javascript')
<script>
$(function () {
    var noticeStorageKey = 'seatAuditCorporationScanNotice';
    var $noticeContainer = $('.js-corporation-audit-scan-notice');

    // 所有动态文本使用 text() 或 esc() 写入，避免快照里的角色名、标题、来源数据被解释为 HTML。
    function showNotice(level, message) {
        var allowedLevels = ['success', 'warning', 'danger'];
        var normalizedLevel = allowedLevels.indexOf(level) === -1 ? 'warning' : level;
        var $notice = $('<div>', {'class': 'alert alert-' + normalizedLevel + ' alert-dismissible fade show', 'role': 'alert'});
        $notice.text(message);
        $notice.append('<button type="button" class="close" data-dismiss="alert" aria-label="关闭"><span aria-hidden="true">&times;</span></button>');
        $noticeContainer.append($notice);
    }

    function esc(value) {
        if (value === null || value === undefined || value === '') return '<span class="text-muted">-</span>';
        return $('<span>').text(String(value)).html();
    }

    function availabilityLabel(value) {
        return {'public': '公开', 'personal': '私人', 'corporation': '军团', 'alliance': '联盟'}[value] || value || '-';
    }

    function entityText(entity) {
        if (!entity) return '<span class="text-muted">-</span>';
        // display_* 来自本地 character_infos / corporation_infos / universe_names 缓存；原始快照
        // 字段仍会保留在 payload 中，以免把当前归属误呈现为合同发生时的历史事实。
        var text = esc(entity.display_name || entity.name) + ' <small class="text-muted">ID: ' + esc(entity.id) + '</small>';
        if (entity.entity_type) text += ' <span class="badge badge-light">' + esc(entity.entity_type) + '</span>';
        if (entity.corporation_id) {
            var corporation = entity.display_corporation_name || entity.corporation_name;
            text += '<br><small class="text-muted">军团快照：' + esc(corporation || ('#' + entity.corporation_id)) + ' (ID: ' + esc(entity.corporation_id) + ')</small>';
        }
        return text;
    }

    $('#corporationContractDetailModal').on('show.bs.modal', function (event) {
        var data = $(event.relatedTarget).data('violation');
        if (!data || !data.details) return;

        var details = typeof data.details === 'string' ? JSON.parse(data.details) : data.details;
        var contract = details.contract || {};
        var parties = details.parties || {};
        var assessment = details.assessment || {};
        var availability = data.contract_availability;
        var $contractTable = $('#corporationContractInfoTable tbody').empty();
        var rows = [
            ['Contract ID', esc(data.contract_id)],
            ['标题', esc(contract.title || '(无标题)')],
            ['合同类型', esc(contract.type === 'item_exchange' ? '物品交换' : (contract.type === 'auction' ? '拍卖' : contract.type))],
            ['可见性', esc(availabilityLabel(availability))],
            ['状态', esc(contract.status)],
            ['Price', esc(contract.price) + ' ISK'],
            ['Reward', esc(contract.reward) + ' ISK'],
            ['审计金额', esc(data.amount) + ' ISK <small class="text-muted">(成员低价合同固定使用 price)</small>'],
            ['审计方向', esc(data.direction_label)],
            ['发起时间', esc(contract.date_issued)],
            ['完成时间', esc(contract.date_completed)],
            ['违规时间快照', esc(data.violation_time)],
            ['低价门槛', esc(assessment.price_threshold)],
        ];
        rows.forEach(function (row) {
            $contractTable.append('<tr><th style="width:35%">' + row[0] + '</th><td>' + row[1] + '</td></tr>');
        });

        $('#corporationContractItemSummary').text(data.item_summary || '未同步物品明细');
        var $itemsTable = $('#corporationContractItemTable tbody').empty();
        var items = Array.isArray(data.contract_items) ? data.contract_items : [];
        if (items.length === 0) {
            $itemsTable.append('<tr><td colspan="5" class="text-muted">未同步物品明细</td></tr>');
        } else {
            items.forEach(function (item) {
                var recordIds = Array.isArray(item.record_ids) ? item.record_ids.join(', ') : (item.record_id || '-');
                var typeName = item.type_name || ('未知物品 #' + (item.type_id || '-'));
                $itemsTable.append('<tr><td>' + esc(typeName) + '</td><td><code>' + esc(item.type_id) + '</code></td><td>' + esc(item.quantity) + '</td><td>' + (Number(item.is_included) === 1 ? '包含在合同中' : '作为交换要求') + '</td><td><code>' + esc(recordIds) + '</code></td></tr>');
            });
        }

        var $partiesTable = $('#corporationContractPartiesTable tbody').empty();
        $partiesTable.append('<tr><th style="width:35%">Issuer（发起方）</th><td>' + entityText(parties.issuer) + '</td></tr>');
        $partiesTable.append('<tr><th>Assignee（指定方）</th><td>' + entityText(parties.assignee) + '</td></tr>');
        $partiesTable.append('<tr><th>Acceptor（接收方）</th><td>' + entityText(parties.acceptor) + '</td></tr>');
        $('#corporationContractDetailModalLabel').text('成员低价合同 #' + data.contract_id + ' 详情');
    });

    // 自动刷新前把终态通知暂存到当前浏览器 tab；队列 worker 不写 HTTP session。
    try {
        var storedNotice = window.sessionStorage.getItem(noticeStorageKey);
        if (storedNotice !== null) {
            window.sessionStorage.removeItem(noticeStorageKey);
            var parsedNotice = JSON.parse(storedNotice);
            if (typeof parsedNotice.message === 'string') showNotice(parsedNotice.level, parsedNotice.message);
        }
    } catch (error) {
        // 浏览器禁用 sessionStorage 时不影响页面查询或后台扫描，只放弃跨刷新提示。
    }

    $('.js-corporation-audit-scan').on('submit', function () {
        $(this).find('button[type="submit"]').prop('disabled', true)
            .html('<i class="fas fa-spinner fa-spin"></i> 已提交，请勿重复点击…');
    });

    var $progress = $('#corporation_audit_scan_progress');
    if ($progress.length === 0) return;

    var statusUrl = $progress.data('status-url');
    var refreshUrl = $progress.data('refresh-url');
    var $scanButton = $('.js-corporation-audit-scan').find('button[type="submit"]');
    var consecutiveFailures = 0;
    var timer = null;
    $scanButton.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> 正在跟踪扫描…');

    function skippedReason(reason) {
        return {
            audit_corporation_not_configured: '未找到受审军团配置。',
            audit_disabled: '军团审计当前未启用。',
            audit_type_disabled: '当前审计类型未启用。',
            audit_from_missing: '缺少审计起始时间配置。',
            member_roster_empty: '当前成员名册为空，未推进扫描进度。'
        }[reason] || '扫描前置条件不满足。';
    }

    function terminalNotice(data) {
        if (data.status === 'succeeded') {
            return {level: 'success', message: data.inserted > 0 ? '扫描完成：已处理 ' + data.chunks + ' 批，新增 ' + data.inserted + ' 条审计记录。' : '扫描完成：已处理 ' + data.chunks + ' 批，未发现新的审计记录。'};
        }
        if (data.status === 'skipped') return {level: 'warning', message: '本次扫描未执行：' + skippedReason(data.reason)};
        return {level: 'danger', message: '扫描最终失败。失败批次不会推进 cursor，请检查队列服务后重试。'};
    }

    function refreshWithNotice(notice) {
        if (timer !== null) window.clearInterval(timer);
        try { window.sessionStorage.setItem(noticeStorageKey, JSON.stringify(notice)); } catch (error) {}
        window.location.replace(refreshUrl);
    }

    function pollStatus() {
        $.ajax({url: statusUrl, dataType: 'json', cache: false}).done(function (data) {
            consecutiveFailures = 0;
            if (data.status === 'unavailable') {
                if (timer !== null) window.clearInterval(timer);
                $progress.text('无法继续跟踪本次扫描，可能是进度缓存已过期或被清理；请手动刷新查看记录。');
                $scanButton.prop('disabled', false).html('<i class="fas fa-play"></i> 扫描{{ $auditTypeLabels[$auditType] }}');
                return;
            }
            if (data.terminal) { refreshWithNotice(terminalNotice(data)); return; }
            if (data.status === 'queued') { $progress.text('扫描任务正在等待队列处理，请勿重复点击。'); return; }
            $progress.text('扫描处理中：已完成 ' + data.chunks + ' 批，当前新增 ' + data.inserted + ' 条记录；完成后将自动刷新。');
        }).fail(function () {
            consecutiveFailures += 1;
            if (consecutiveFailures >= 3) $progress.text('暂时无法读取扫描状态，正在继续重试；请勿重复点击。');
        });
    }

    pollStatus();
    timer = window.setInterval(pollStatus, 3000);
});
</script>
@endpush
