{{-- D:\VS Code\Project test\seat-audit-monitor\src\resources\views\violations\index.blade.php --}}
{{-- 违规记录列表视图，继承 SeAT 原生布局，支持时间区间筛选和 CSV 导出 --}}

@extends('web::layouts.grids.12')

@section('title', '违规交易记录')

@section('full')
<div class="row">
    <div class="col-12">

        {{-- 操作反馈提示（审计扫描结果等） --}}
        @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
        @endif

        {{-- 时间区间筛选卡片 --}}
        <div class="card mb-3">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-filter"></i> 筛选条件</h3>
            </div>
            <div class="card-body">
                <form method="GET" action="{{ route('seat-audit.violations.index') }}" class="form-inline">
                    <div class="form-group mr-3">
                        <label for="start_date" class="mr-2">开始日期</label>
                        <input type="date"
                               id="start_date"
                               name="start_date"
                               class="form-control form-control-sm"
                               value="{{ $startDate ?? '' }}">
                    </div>
                    <div class="form-group mr-3">
                        <label for="end_date" class="mr-2">结束日期</label>
                        <input type="date"
                               id="end_date"
                               name="end_date"
                               class="form-control form-control-sm"
                               value="{{ $endDate ?? '' }}">
                    </div>
                    <button type="submit" class="btn btn-sm btn-primary mr-2">
                        <i class="fas fa-search"></i> 筛选
                    </button>
                    {{-- 清除筛选条件，跳回不带参数的列表页 --}}
                    <a href="{{ route('seat-audit.violations.index') }}" class="btn btn-sm btn-secondary mr-3">
                        <i class="fas fa-times"></i> 清除
                    </a>
                    {{-- 导出当前筛选条件下的全部记录为 CSV --}}
                    <a href="{{ route('seat-audit.violations.export', array_filter(['start_date' => $startDate ?? '', 'end_date' => $endDate ?? ''])) }}"
                       class="btn btn-sm btn-success">
                        <i class="fas fa-file-excel"></i> 导出 CSV (Excel)
                    </a>
                </form>
                {{-- 提示当前筛选状态 --}}
                @if($startDate || $endDate)
                <div class="mt-2 text-muted small">
                    <i class="fas fa-info-circle"></i>
                    当前筛选：
                    @if($startDate) 从 <strong>{{ $startDate }}</strong> @endif
                    @if($endDate) 至 <strong>{{ $endDate }}</strong> @endif
                    — 共 {{ $violations->total() }} 条记录
                </div>
                @else
                <div class="mt-2 text-muted small">
                    <i class="fas fa-info-circle"></i> 显示全部记录，共 {{ $violations->total() }} 条
                </div>
                @endif
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3 class="card-title">违规交易记录</h3>
                <div class="card-tools">
                    @can('seat-audit-monitor.admin')
                    {{-- 立即审查按钮，POST 表单防止刷新重复触发，仅管理员可见 --}}
                    <form method="POST" action="{{ route('seat-audit.violations.scan') }}" style="display: inline;">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-warning">
                            <i class="fas fa-search"></i> 立即审查
                        </button>
                    </form>
                    <a href="{{ route('seat-audit.admin.items') }}" class="btn btn-sm btn-primary ml-1">
                        <i class="fas fa-cog"></i> 管理监控名单
                    </a>
                    <a href="{{ route('seat-audit.admin.whitelist') }}" class="btn btn-sm btn-secondary ml-1">
                        <i class="fas fa-user-shield"></i> 管理白名单
                    </a>
                    @endcan
                </div>
            </div>
            <div class="card-body p-0">
                @if($violations->isEmpty())
                    <div class="p-3 text-muted">
                        @if($startDate || $endDate)
                            该时间区间内暂无违规记录。
                        @else
                            暂无违规记录。
                        @endif
                    </div>
                @else
                <table class="table table-striped table-hover mb-0">
                    <thead>
                        <tr>
                            <th>角色名</th>
                            <th>物品名称</th>
                            <th>交易金额 (ISK)</th>
                            <th>发生时间</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($violations as $v)
                        <tr>
                            <td>{{ $v->character_name }}</td>
                            <td>{{ $v->item_name }}</td>
                            <td>{{ number_format($v->amount, 2) }}</td>
                            <td>{{ $v->violation_time }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
                @endif
            </div>
            @if($violations->hasPages())
            <div class="card-footer">
                {{ $violations->links() }}
            </div>
            @endif
        </div>
    </div>
</div>
@stop
