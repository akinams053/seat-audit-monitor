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

                    @can('seat-audit-monitor.admin')
                        {{-- 不能嵌套在 GET 筛选表单内；POST + CSRF 使刷新页面不会意外重复提交扫描。 --}}
                        <form method="POST" action="{{ route('seat-audit.corporation-audit.scan') }}" class="form-inline mb-2 js-corporation-audit-scan">
                            @csrf
                            <input type="hidden" name="audit_type" value="{{ $auditType }}">
                            <input type="hidden" name="start_date" value="{{ $startDate ?? '' }}">
                            <input type="hidden" name="end_date" value="{{ $endDate ?? '' }}">
                            <button type="submit" class="btn btn-sm btn-warning" title="仅提交当前标签的异步扫描任务">
                                <i class="fas fa-play"></i> 扫描{{ $auditTypeLabels[$auditType] }}
                            </button>
                        </form>
                    @endcan
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3 class="card-title">{{ $auditTypeLabels[$auditType] }}记录 <small class="text-muted ml-2">共 {{ $violations->total() }} 条</small></h3>
                <div class="card-tools text-muted small">扫描提交后由队列异步执行；完成后刷新页面查看结果。</div>
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
    // 仅减少同一浏览器窗口的双击；实际幂等性由 Job 的 cursor 事务与 source_event_key 唯一索引保证。
    $('.js-corporation-audit-scan').on('submit', function () {
        var $button = $(this).find('button[type="submit"]');
        $button.prop('disabled', true)
            .html('<i class="fas fa-spinner fa-spin"></i> 扫描请求提交中…');
    });
});
</script>
@endpush
