{{-- D:\VS Code\Project test\seat-audit-monitor\src\resources\views\violations\index.blade.php --}}
{{-- 违规记录列表视图，继承 SeAT 原生布局 --}}

@extends('web::layouts.grids.12')

@section('title', '违规交易记录')

@section('content')
<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">违规交易记录</h3>
                @can('seat-audit-monitor.admin')
                <div class="card-tools">
                    <a href="{{ route('seat-audit.admin.items') }}" class="btn btn-sm btn-primary">
                        <i class="fas fa-cog"></i> 管理监控名单
                    </a>
                    <a href="{{ route('seat-audit.admin.whitelist') }}" class="btn btn-sm btn-secondary ml-1">
                        <i class="fas fa-user-shield"></i> 管理白名单
                    </a>
                </div>
                @endcan
            </div>
            <div class="card-body p-0">
                @if($violations->isEmpty())
                    <div class="p-3 text-muted">暂无违规记录。</div>
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
@endsection
