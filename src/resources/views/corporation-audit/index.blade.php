{{-- src/resources/views/corporation-audit/index.blade.php --}}
{{-- 固定军团 98588384 的 2.0 只读审计列表；不复用旧物品违规页面的白名单软过滤 --}}

@extends('web::layouts.grids.12')

@section('title', '军团审计')

@section('full')
<div class="row">
    <div class="col-12">
        <div class="card mb-3">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-shield-alt"></i> 军团审计</h3>
            </div>
            <div class="card-body">
                <p class="text-muted small mb-3">
                    审计范围：军团 <code>98588384</code> 的当前成员与外部方之间的 ISK 捐赠及低价已完成合同。
                    白名单已在扫描入库时仅对外部方生效。
                </p>
                <form method="GET" action="{{ route('seat-audit.corporation-audit.index') }}" class="form-inline">
                    <div class="form-group mr-3">
                        <label for="audit_type" class="mr-2">审计类型</label>
                        <select id="audit_type" name="audit_type" class="form-control form-control-sm">
                            <option value="all" @selected($auditType === 'all')>全部</option>
                            @foreach($auditTypeLabels as $type => $label)
                                @if($type !== 'all')
                                    <option value="{{ $type }}" @selected($auditType === $type)>{{ $label }}</option>
                                @endif
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group mr-3">
                        <label for="start_date" class="mr-2">开始日期</label>
                        <input type="date" id="start_date" name="start_date" class="form-control form-control-sm" value="{{ $startDate ?? '' }}">
                    </div>
                    <div class="form-group mr-3">
                        <label for="end_date" class="mr-2">结束日期</label>
                        <input type="date" id="end_date" name="end_date" class="form-control form-control-sm" value="{{ $endDate ?? '' }}">
                    </div>
                    <button type="submit" class="btn btn-sm btn-primary mr-2"><i class="fas fa-search"></i> 筛选</button>
                    <a href="{{ route('seat-audit.corporation-audit.index') }}" class="btn btn-sm btn-secondary"><i class="fas fa-times"></i> 清除</a>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3 class="card-title">审计记录 <small class="text-muted ml-2">共 {{ $violations->total() }} 条</small></h3>
                <div class="card-tools text-muted small">仅供查看；请使用 <code>seat:audit:scan</code> 手动执行扫描。</div>
            </div>
            <div class="card-body p-0">
                @if($violations->isEmpty())
                    <div class="p-3 text-muted">当前筛选条件下暂无军团审计记录。</div>
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
