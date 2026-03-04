{{-- D:\VS Code\Project test\seat-audit-monitor\src\resources\views\admin\items.blade.php --}}
{{-- 监控物品管理视图，输入 type_id 后自动查询物品名称 --}}

@extends('web::layouts.grids.12')

@section('title', '监控物品管理')

@section('full')
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
                            <input type="number" name="type_id" id="type-id-input"
                                   class="form-control @error('type_id') is-invalid @enderror"
                                   placeholder="例如：34" value="{{ old('type_id') }}" required>
                            @error('type_id')
                            <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="form-group col-md-6">
                            <label>物品名称 <small class="text-muted">（根据 ID 自动填充）</small></label>
                            <input type="text" id="item-name-display" class="form-control"
                                   placeholder="输入物品 ID 后自动显示" readonly>
                            {{-- 查询状态提示 --}}
                            <small id="item-name-status" class="form-text" style="display: none;"></small>
                        </div>
                        <div class="form-group col-md-2 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary w-100" id="submit-btn">添加</button>
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

{{-- 物品名称自动查询脚本（使用 SeAT 已内置的 jQuery） --}}
<script>
$(function() {
    var typeIdInput = $('#type-id-input');
    var nameDisplay = $('#item-name-display');
    var statusText = $('#item-name-status');
    var debounceTimer = null;

    // type_id 输入变化时自动查询物品名称（防抖 500ms）
    typeIdInput.on('input', function() {
        var typeId = $(this).val().trim();
        clearTimeout(debounceTimer);

        if (!typeId || typeId < 1) {
            nameDisplay.val('');
            statusText.hide();
            return;
        }

        // 显示查询中状态
        statusText.text('查询中...').removeClass('text-danger text-success').addClass('text-muted').show();
        nameDisplay.val('');

        debounceTimer = setTimeout(function() {
            $.getJSON('{{ route("seat-audit.api.item-name") }}', { type_id: typeId })
                .done(function(data) {
                    nameDisplay.val(data.typeName);
                    statusText.text('已找到物品').removeClass('text-muted text-danger').addClass('text-success').show();
                })
                .fail(function(xhr) {
                    nameDisplay.val('');
                    if (xhr.status === 404) {
                        statusText.text('未找到该物品 ID，请确认是否正确').removeClass('text-muted text-success').addClass('text-danger').show();
                    } else {
                        statusText.text('查询失败，请稍后重试').removeClass('text-muted text-success').addClass('text-danger').show();
                    }
                });
        }, 500);
    });
});
</script>
@stop
