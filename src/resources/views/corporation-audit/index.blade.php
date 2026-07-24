{{-- src/resources/views/corporation-audit/index.blade.php --}}
{{-- 固定军团 98588384 的 2.0 审计结果页：标签只筛选已入库记录，扫描按钮仅异步提交当前类型任务。 --}}

@extends('web::layouts.grids.12')

@section('title', '军团审计')

@section('full')
<div class="row">
    <div class="col-12">
        <div class="card mb-3">
            <div class="card-header d-flex p-0 with-border">
                <h3 class="card-title p-3 mb-0"><i class="fas fa-shield-alt"></i> 军团审计</h3>
                <ul class="nav nav-pills ml-auto p-2">
                    @foreach($allowedAuditTypes as $type)
                        @php
                            // 标签链接保留日期过滤，但不保留页码；切换类型后应从新类型的第一页开始显示。
                            $tabParameters = array_filter([
                                'audit_type' => $type,
                                'start_date' => $startDate,
                                'end_date'   => $endDate,
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
                    日期仅筛选已入库记录；管理员点击扫描后，任务将按 <code>audit_from</code> 与 cursor 异步增量执行。
                </p>

                <div class="d-flex flex-wrap align-items-center">
                    <form method="GET" action="{{ route('seat-audit.corporation-audit.index') }}" class="form-inline mr-2 mb-2">
                        {{-- 保持当前标签；日期表单只改变展示范围，绝不改变实际扫描的 cursor 或 audit_from。 --}}
                        <input type="hidden" name="audit_type" value="{{ $auditType }}">
                        <div class="form-group mr-3">
                            <label for="start_date" class="mr-2">开始日期</label>
                            <input type="date" id="start_date" name="start_date" class="form-control form-control-sm" value="{{ $startDate ?? '' }}">
                        </div>
                        <div class="form-group mr-3">
                            <label for="end_date" class="mr-2">结束日期</label>
                            <input type="date" id="end_date" name="end_date" class="form-control form-control-sm" value="{{ $endDate ?? '' }}">
                        </div>
                        <button type="submit" class="btn btn-sm btn-primary mr-2"><i class="fas fa-filter"></i> 筛选</button>
                        <a href="{{ route('seat-audit.corporation-audit.index', ['audit_type' => $auditType]) }}" class="btn btn-sm btn-secondary"><i class="fas fa-times"></i> 清除</a>
                    </form>
                    {{-- 导出只继承业务筛选，不携带页码或 scan_token，保证下载的是全部匹配记录而非当前浏览状态。 --}}
                    <a href="{{ route('seat-audit.corporation-audit.export', array_filter([
                        'audit_type' => $auditType,
                        'start_date' => $startDate,
                        'end_date' => $endDate,
                    ])) }}" class="btn btn-sm btn-outline-success mr-2 mb-2"><i class="fas fa-download"></i> 导出 CSV</a>

                    @can('seat-audit-monitor.admin')
                        {{-- 不能嵌套在 GET 筛选表单内；POST + CSRF 使刷新页面不会意外重复提交扫描。 --}}
                        <form method="POST" action="{{ route('seat-audit.corporation-audit.scan') }}" class="form-inline mb-2 js-corporation-audit-scan">
                            @csrf
                            <input type="hidden" name="audit_type" value="{{ $auditType }}">
                            <input type="hidden" name="start_date" value="{{ $startDate ?? '' }}">
                            <input type="hidden" name="end_date" value="{{ $endDate ?? '' }}">
                            <button type="submit" class="btn btn-sm btn-warning" title="仅提交当前标签的异步扫描任务；完成后本页会自动刷新">
                                <i class="fas fa-play"></i> 扫描{{ $auditTypeLabels[$auditType] }}
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
                <div class="card-tools text-muted small">扫描提交后由队列异步执行；页面保持打开时完成后会自动刷新。</div>
            </div>
            <div class="card-body p-0">
                @if($violations->isEmpty())
                    <div class="p-3 text-muted">当前筛选条件下暂无{{ $auditTypeLabels[$auditType] }}记录。</div>
                @else
                    <table class="table table-striped table-hover mb-0">
                        <thead>
                            <tr>
                                <th>类型</th>
                                <th>成员</th>
                                <th>外部方</th>
                                <th>方向</th>
                                <th>金额 (ISK)</th>
                                <th>来源 / 合同</th>
                                <th>发生时间</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($violations as $violation)
                                <tr>
                                    <td>
                                        @if($violation->audit_type === 'isk_donations')
                                            <span class="badge badge-success">{{ $auditTypeLabels[$violation->audit_type] }}</span>
                                        @else
                                            <span class="badge badge-primary">{{ $auditTypeLabels[$violation->audit_type] }}</span>
                                        @endif
                                    </td>
                                    <td>{{ $violation->character_name }} <small class="text-muted">#{{ $violation->member_character_id }}</small></td>
                                    <td>{{ $violation->counterparty_name }} <small class="text-muted">#{{ $violation->external_party_id }}</small></td>
                                    <td>
                                        @if($violation->direction === 'outbound')
                                            <span class="badge badge-warning">成员转出</span>
                                        @else
                                            <span class="badge badge-info">成员接收</span>
                                        @endif
                                    </td>
                                    <td>{{ number_format($violation->amount, 2) }}</td>
                                    <td>
                                        @if($violation->audit_type === 'member_contracts')
                                            <code>#{{ $violation->contract_id }}</code>
                                        @else
                                            <code>{{ $violation->source_reference }}</code>
                                        @endif
                                    </td>
                                    <td>{{ $violation->violation_time }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
            @if($violations->hasPages())
                <div class="card-footer">{{ $violations->links() }}</div>
            @endif
        </div>
    </div>
</div>
@stop

@push('javascript')
<script>
$(function () {
    var noticeStorageKey = 'seatAuditCorporationScanNotice';
    var $noticeContainer = $('.js-corporation-audit-scan-notice');

    // 所有动态文本使用 text() 写入，避免来自状态 API 的内容被浏览器当作 HTML 解析。
    function showNotice(level, message) {
        var allowedLevels = ['success', 'warning', 'danger'];
        var normalizedLevel = allowedLevels.indexOf(level) === -1 ? 'warning' : level;
        var $notice = $('<div>', {
            'class': 'alert alert-' + normalizedLevel + ' alert-dismissible fade show',
            'role': 'alert'
        });
        $notice.text(message);
        $notice.append('<button type="button" class="close" data-dismiss="alert" aria-label="关闭"><span aria-hidden="true">&times;</span></button>');
        $noticeContainer.append($notice);
    }

    // 自动刷新前把终态通知暂存到当前浏览器 tab；队列 worker 不写 HTTP session。
    try {
        var storedNotice = window.sessionStorage.getItem(noticeStorageKey);
        if (storedNotice !== null) {
            window.sessionStorage.removeItem(noticeStorageKey);
            var parsedNotice = JSON.parse(storedNotice);
            if (typeof parsedNotice.message === 'string') {
                showNotice(parsedNotice.level, parsedNotice.message);
            }
        }
    } catch (error) {
        // 浏览器禁用 sessionStorage 时不影响页面查询或后台扫描，只放弃跨刷新提示。
    }

    // 仅减少同一浏览器窗口的双击；本需求不引入跨标签页或跨管理员的服务端扫描去重。
    $('.js-corporation-audit-scan').on('submit', function () {
        var $button = $(this).find('button[type="submit"]');
        $button.prop('disabled', true)
            .html('<i class="fas fa-spinner fa-spin"></i> 已提交，请勿重复点击…');
    });

    var $progress = $('#corporation_audit_scan_progress');
    if ($progress.length === 0) {
        return;
    }

    var statusUrl = $progress.data('status-url');
    var refreshUrl = $progress.data('refresh-url');
    var $scanButton = $('.js-corporation-audit-scan').find('button[type="submit"]');
    var consecutiveFailures = 0;
    var timer = null;

    // 页面因 scan_token 重定向回来后，继续禁用当前按钮并提示任务进度，降低重复点击概率。
    $scanButton.prop('disabled', true)
        .html('<i class="fas fa-spinner fa-spin"></i> 正在跟踪扫描…');

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
            return {
                level: 'success',
                message: data.inserted > 0
                    ? '扫描完成：已处理 ' + data.chunks + ' 批，新增 ' + data.inserted + ' 条审计记录。'
                    : '扫描完成：已处理 ' + data.chunks + ' 批，未发现新的审计记录。'
            };
        }

        if (data.status === 'skipped') {
            return {
                level: 'warning',
                message: '本次扫描未执行：' + skippedReason(data.reason)
            };
        }

        return {
            level: 'danger',
            message: '扫描最终失败。失败批次不会推进 cursor，请检查队列服务后重试。'
        };
    }

    function refreshWithNotice(notice) {
        if (timer !== null) {
            window.clearInterval(timer);
        }

        try {
            window.sessionStorage.setItem(noticeStorageKey, JSON.stringify(notice));
        } catch (error) {
            // 无法跨刷新保存提示时仍必须刷新，不能让已完成任务一直停留在轮询状态。
        }

        window.location.replace(refreshUrl);
    }

    function pollStatus() {
        $.ajax({
            url: statusUrl,
            dataType: 'json',
            cache: false
        }).done(function (data) {
            consecutiveFailures = 0;

            if (data.status === 'unavailable') {
                if (timer !== null) {
                    window.clearInterval(timer);
                }
                $progress.text('无法继续跟踪本次扫描，可能是进度缓存已过期或被清理；请手动刷新查看记录。');
                // 跟踪状态丢失不等于 Job 已结束；重新允许用户自行决定下一步，但不伪造完成提示。
                $scanButton.prop('disabled', false)
                    .html('<i class="fas fa-play"></i> 扫描{{ $auditTypeLabels[$auditType] }}');
                return;
            }

            if (data.terminal) {
                refreshWithNotice(terminalNotice(data));
                return;
            }

            if (data.status === 'queued') {
                $progress.text('扫描任务正在等待队列处理，请勿重复点击。');
                return;
            }

            $progress.text('扫描处理中：已完成 ' + data.chunks + ' 批，当前新增 ' + data.inserted + ' 条记录；完成后将自动刷新。');
        }).fail(function () {
            consecutiveFailures += 1;
            if (consecutiveFailures >= 3) {
                $progress.text('暂时无法读取扫描状态，正在继续重试；请勿重复点击。');
            }
        });
    }

    pollStatus();
    timer = window.setInterval(pollStatus, 3000);
});
</script>
@endpush
