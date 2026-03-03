{{-- D:\VS Code\Project test\seat-audit-monitor\src\resources\views\admin\items.blade.php --}}
{{-- 监控物品管理视图 --}}

@extends('web::layouts.grids.12')

@section('title', '监控物品管理')

@section('content')
<div class="row">
    <div class="col-12">

        {{-- 操作反馈提示 --}}
        @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
        @endif

        {{-- 添加监控物品表单 --}}
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">添加监控物品</h3>
            </div>
            <div class="card-body">
                <form method="POST" action="{{ route('seat-audit.admin.items.store') }}">
                    @csrf
                    <div class="form-row">
                        <div class="form-group col-md-4">
                            <label>Eve 物品 ID (type_id)</label>
                            <input type="number" name="type_id" class="form-control @error('type_id') is-invalid @enderror"
                                   placeholder="例如：34" value="{{ old('type_id') }}" required>
                            @error('type_id')
                            <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="form-group col-md-6">
                            <label>物品名称</label>
                            <input type="text" name="item_name" class="form-control @error('item_name') is-invalid @enderror"
                                   placeholder="例如：Tritanium" value="{{ old('item_name') }}" required>
                            @error('item_name')
                            <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="form-group col-md-2 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary w-100">添加</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        {{-- 监控物品列表 --}}
        <div class="card mt-3">
            <div class="card-header">
                <h3 class="card-title">当前监控物品 ({{ count($items) }} 项)</h3>
            </div>
            <div class="card-body p-0">
                @if($items->isEmpty())
                    <div class="p-3 text-muted">暂无监控物品，请添加。</div>
                @else
                <table class="table table-striped mb-0">
                    <thead>
                        <tr>
                            <th>物品 ID (type_id)</th>
                            <th>物品名称</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($items as $item)
                        <tr>
                            <td>{{ $item->type_id }}</td>
                            <td>{{ $item->item_name }}</td>
                            <td>
                                <form method="POST"
                                      action="{{ route('seat-audit.admin.items.destroy', $item->id) }}"
                                      onsubmit="return confirm('确认删除此监控物品？')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-danger">删除</button>
                                </form>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
                @endif
            </div>
        </div>

        <div class="mt-2">
            <a href="{{ route('seat-audit.violations.index') }}" class="btn btn-secondary">
                &larr; 返回违规记录
            </a>
        </div>
    </div>
</div>
@endsection
