{{-- D:\VS Code\Project test\seat-audit-monitor\src\resources\views\admin\whitelist.blade.php --}}
{{-- 白名单管理视图，支持角色名搜索自动补全 --}}

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
                <form method="POST" action="{{ route('seat-audit.admin.whitelist.store') }}" id="whitelist-form">
                    @csrf
                    {{-- character_id 隐藏字段，由选择角色后自动填入 --}}
                    <input type="hidden" name="character_id" id="character-id-input">
                    <div class="form-row">
                        <div class="form-group col-md-8" style="position: relative;">
                            <label>搜索角色（输入角色名称）</label>
                            <input type="text" id="character-search"
                                   class="form-control @error('character_id') is-invalid @enderror"
                                   placeholder="输入至少 2 个字符搜索角色..." autocomplete="off">
                            @error('character_id')
                            <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                            {{-- 搜索结果下拉列表 --}}
                            <div id="search-results" class="list-group"
                                 style="position: absolute; z-index: 1000; width: calc(100% - 30px); display: none; max-height: 300px; overflow-y: auto; box-shadow: 0 2px 8px rgba(0,0,0,0.15);">
                            </div>
                        </div>
                        {{-- 选中角色后显示已选信息 --}}
                        <div class="form-group col-md-2 d-flex align-items-end">
                            <input type="text" name="character_name" id="character-name-display"
                                   class="form-control" placeholder="未选择" readonly>
                        </div>
                        <div class="form-group col-md-2 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary w-100" id="submit-btn" disabled>添加</button>
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

{{-- 角色搜索自动补全脚本（使用 SeAT 已内置的 jQuery） --}}
<script>
$(function() {
    var searchInput = $('#character-search');
    var resultsBox = $('#search-results');
    var idInput = $('#character-id-input');
    var nameDisplay = $('#character-name-display');
    var submitBtn = $('#submit-btn');
    var debounceTimer = null;

    // 输入时防抖搜索（300ms）
    searchInput.on('input', function() {
        var keyword = $(this).val().trim();
        clearTimeout(debounceTimer);

        // 清除之前的选择状态
        idInput.val('');
        nameDisplay.val('');
        submitBtn.prop('disabled', true);

        if (keyword.length < 2) {
            resultsBox.hide().empty();
            return;
        }

        debounceTimer = setTimeout(function() {
            $.getJSON('{{ route("seat-audit.api.characters") }}', { q: keyword }, function(data) {
                resultsBox.empty();

                if (data.length === 0) {
                    resultsBox.append(
                        '<div class="list-group-item text-muted">未找到匹配的角色</div>'
                    );
                } else {
                    $.each(data, function(i, char) {
                        resultsBox.append(
                            '<a href="#" class="list-group-item list-group-item-action character-option" ' +
                            'data-id="' + char.character_id + '" data-name="' + $('<span>').text(char.name).html() + '">' +
                            '<strong>' + $('<span>').text(char.name).html() + '</strong>' +
                            '<small class="text-muted ml-2">ID: ' + char.character_id + '</small>' +
                            '</a>'
                        );
                    });
                }

                resultsBox.show();
            });
        }, 300);
    });

    // 选中某个角色
    resultsBox.on('click', '.character-option', function(e) {
        e.preventDefault();
        var charId = $(this).data('id');
        var charName = $(this).data('name');

        idInput.val(charId);
        nameDisplay.val(charName);
        searchInput.val(charName);
        submitBtn.prop('disabled', false);
        resultsBox.hide().empty();
    });

    // 点击页面其他区域时关闭下拉
    $(document).on('click', function(e) {
        if (!$(e.target).closest('#character-search, #search-results').length) {
            resultsBox.hide();
        }
    });
});
</script>
@stop
