{{-- src/resources/views/violations/index.blade.php --}}
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
        @if(session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
        @endif

        {{-- 审计类型显示名称，用于筛选提示和导出参数 --}}
        @php
            $auditTypeLabels = [
                'all' => '全部',
                'wallet_transactions' => '钱包交易',
                'contracts' => '合同',
            ];
        @endphp

        {{-- 时间区间筛选卡片 --}}
        <div class="card mb-3">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-filter"></i> 筛选条件</h3>
            </div>
            <div class="card-body">
                <form method="GET" action="{{ route('seat-audit.violations.index') }}" class="form-inline">
                    <div class="form-group mr-3">
                        <label for="audit_type" class="mr-2">审计类型</label>
                        <select id="audit_type"
                                name="audit_type"
                                class="form-control form-control-sm">
                            <option value="all" @selected(($auditType ?? 'all') === 'all')>全部</option>
                            <option value="wallet_transactions" @selected(($auditType ?? 'all') === 'wallet_transactions')>钱包交易</option>
                            <option value="contracts" @selected(($auditType ?? 'all') === 'contracts')>合同</option>
                        </select>
                    </div>
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
                    {{-- 清除日期筛选条件，保留非全部审计类型 --}}
                    <a href="{{ route('seat-audit.violations.index', array_filter(['audit_type' => ($auditType ?? 'all') !== 'all' ? $auditType : ''])) }}" class="btn btn-sm btn-secondary mr-3">
                        <i class="fas fa-times"></i> 清除
                    </a>
                    {{-- 导出当前筛选条件下的全部记录为 CSV --}}
                    <a href="{{ route('seat-audit.violations.export', array_filter([
                        'audit_type' => ($auditType ?? 'all') !== 'all' ? $auditType : '',
                        'start_date' => $startDate ?? '',
                        'end_date' => $endDate ?? '',
                    ])) }}"
                       class="btn btn-sm btn-success">
                        <i class="fas fa-file-excel"></i> 导出 CSV (Excel)
                    </a>
                </form>
                {{-- 提示当前筛选状态 --}}
                @if($startDate || $endDate || ($auditType ?? 'all') !== 'all')
                <div class="mt-2 text-muted small">
                    <i class="fas fa-info-circle"></i>
                    当前筛选：
                    @if(($auditType ?? 'all') !== 'all')
                        <br>审计类型：<strong>{{ $auditTypeLabels[$auditType] ?? $auditType }}</strong>
                    @endif
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
                    {{-- 立即审查表单，POST body 包含审计类型，仅管理员可见 --}}
                    <form method="POST" action="{{ route('seat-audit.violations.scan') }}" class="form-inline d-inline-flex align-items-center">
                        @csrf
                        <select name="type"
                                class="form-control form-control-sm mr-1"
                                aria-label="审计类型">
                            <option value="all">全部</option>
                            <option value="wallet">钱包交易</option>
                            <option value="contracts">合同</option>
                        </select>
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
                            <th>来源</th>
                            <th>Contract ID</th>
                            <th>发生时间</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($violations as $v)
                        <tr class="{{ $v->audit_type === 'contracts' && (float) $v->amount === 0.0 ? 'table-secondary' : '' }}">
                            <td>{{ $v->character_name }}</td>
                            <td>{{ $v->item_name }}</td>
                            <td>
                                {{ number_format($v->amount, 2) }}
                                @if($v->audit_type === 'contracts' && (float) $v->amount === 0.0)
                                    <small class="text-muted">(零金额)</small>
                                @endif
                            </td>
                            <td>
                                @if($v->audit_type === 'wallet_transactions')
                                    <span class="badge badge-primary">钱包</span>
                                @elseif($v->audit_type === 'contracts')
                                    <span class="badge badge-warning">合同</span>
                                @else
                                    -
                                @endif
                            </td>
                            <td>
                                @if($v->audit_type === 'contracts')
                                    <code>{{ $v->contract_id }}</code>
                                @else
                                    -
                                @endif
                            </td>
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
