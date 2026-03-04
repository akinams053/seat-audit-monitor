{{-- D:\VS Code\Project test\seat-audit-monitor\src\resources\views\admin\whitelist.blade.php --}}
{{-- 白名单管理视图 --}}

@extends('web::layouts.grids.12')

@section('title', '白名单管理')

@section('full')
<div class="row">
    <div class="col-12">

        {{-- 操作反馈提示 --}}
        @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
        @endif

        {{-- 添加白名单表单 --}}
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">添加豁免角色</h3>
            </div>
            <div class="card-body">
                <form method="POST" action="{{ route('seat-audit.admin.whitelist.store') }}">
                    @csrf
                    <div class="form-row">
                        <div class="form-group col-md-4">
                            <label>角色 ID (character_id)</label>
                            <input type="number" name="character_id"
                                   class="form-control @error('character_id') is-invalid @enderror"
                                   placeholder="例如：12345678" value="{{ old('character_id') }}" required>
                            @error('character_id')
                            <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="form-group col-md-6">
                            <label>角色名称</label>
                            <input type="text" name="character_name"
                                   class="form-control @error('character_name') is-invalid @enderror"
                                   placeholder="例如：Pilot Name" value="{{ old('character_name') }}" required>
                            @error('character_name')
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

        {{-- 白名单角色列表 --}}
        <div class="card mt-3">
            <div class="card-header">
                <h3 class="card-title">当前白名单 ({{ count($whitelist) }} 人)</h3>
            </div>
            <div class="card-body p-0">
                @if($whitelist->isEmpty())
                    <div class="p-3 text-muted">暂无豁免角色。</div>
                @else
                <table class="table table-striped mb-0">
                    <thead>
                        <tr>
                            <th>角色 ID</th>
                            <th>角色名称</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($whitelist as $entry)
                        <tr>
                            <td>{{ $entry->character_id }}</td>
                            <td>{{ $entry->character_name }}</td>
                            <td>
                                <form method="POST"
                                      action="{{ route('seat-audit.admin.whitelist.destroy', $entry->id) }}"
                                      onsubmit="return confirm('确认将此角色从白名单移除？')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-danger">移除</button>
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
@stop
