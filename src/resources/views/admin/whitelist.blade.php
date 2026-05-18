{{-- src/resources/views/admin/whitelist.blade.php --}}
{{-- 白名单管理视图：两个 tab — 角色白名单（所有审计生效）+ 军团白名单（仅合同审计生效） --}}
{{-- $activeTab：'character' | 'corporation'，决定页面打开时默认激活的 tab --}}

@extends('web::layouts.grids.12')

@section('title', '白名单管理')

@section('full')
<div class="row">
    <div class="col-12">

        {{-- 操作反馈提示 --}}
        @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
        @endif

        {{-- 顶部 tab 导航 --}}
        <ul class="nav nav-tabs" id="whitelistTab" role="tablist">
            <li class="nav-item">
                <a class="nav-link {{ ($activeTab ?? 'character') === 'character' ? 'active' : '' }}"
                   id="tab-character" data-toggle="tab" href="#pane-character" role="tab">
                    <i class="fas fa-user"></i> 角色白名单
                    <span class="badge badge-secondary ml-1">{{ count($whitelist) }}</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link {{ ($activeTab ?? 'character') === 'corporation' ? 'active' : '' }}"
                   id="tab-corporation" data-toggle="tab" href="#pane-corporation" role="tab">
                    <i class="fas fa-building"></i> 军团白名单
                    <span class="badge badge-secondary ml-1">{{ count($corporationWhitelist) }}</span>
                    <small class="text-muted ml-1">(仅合同生效)</small>
                </a>
            </li>
        </ul>

        <div class="tab-content border-left border-right border-bottom p-3" style="background:#fff;">

            {{-- ============= Tab 1：角色白名单 ============= --}}
            <div class="tab-pane fade {{ ($activeTab ?? 'character') === 'character' ? 'show active' : '' }}"
                 id="pane-character" role="tabpanel">

                {{-- 添加角色白名单表单 --}}
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">添加豁免角色</h3>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="{{ route('seat-audit.admin.whitelist.store') }}" id="whitelist-form">
                            @csrf
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
                                    <div id="search-results" class="list-group"
                                         style="position: absolute; z-index: 1000; width: calc(100% - 30px); display: none; max-height: 300px; overflow-y: auto; box-shadow: 0 2px 8px rgba(0,0,0,0.15);">
                                    </div>
                                    <small class="text-muted">
                                        本地搜索来自 SeAT 已收录的角色；如果搜不到外部角色，列表底部会出现「在 ESI 中精确查找」按钮，用 EVE 官方接口按完整名字查找。
                                    </small>
                                </div>
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

                {{-- 角色白名单列表 --}}
                <div class="card mt-3">
                    <div class="card-header">
                        <h3 class="card-title">当前角色白名单 ({{ count($whitelist) }} 人)</h3>
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
            </div>

            {{-- ============= Tab 2：军团白名单 ============= --}}
            <div class="tab-pane fade {{ ($activeTab ?? 'character') === 'corporation' ? 'show active' : '' }}"
                 id="pane-corporation" role="tabpanel">

                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i>
                    <strong>军团白名单仅对合同审计生效</strong>：合同发起方军团或接收方当前军团命中即跳过整份合同；钱包交易审计不受此名单影响。
                </div>

                {{-- 添加军团白名单表单 --}}
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">添加豁免军团</h3>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="{{ route('seat-audit.admin.corporation-whitelist.store') }}" id="corp-whitelist-form">
                            @csrf
                            <input type="hidden" name="corporation_id" id="corp-id-input">
                            <div class="form-row">
                                <div class="form-group col-md-8" style="position: relative;">
                                    <label>搜索军团（输入军团名或 ticker）</label>
                                    <input type="text" id="corp-search"
                                           class="form-control @error('corporation_id') is-invalid @enderror"
                                           placeholder="输入至少 2 个字符搜索军团..." autocomplete="off">
                                    @error('corporation_id')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                    <div id="corp-search-results" class="list-group"
                                         style="position: absolute; z-index: 1000; width: calc(100% - 30px); display: none; max-height: 300px; overflow-y: auto; box-shadow: 0 2px 8px rgba(0,0,0,0.15);">
                                    </div>
                                </div>
                                <div class="form-group col-md-2 d-flex align-items-end">
                                    <input type="text" id="corp-name-display"
                                           class="form-control" placeholder="未选择" readonly>
                                </div>
                                <div class="form-group col-md-2 d-flex align-items-end">
                                    <button type="submit" class="btn btn-primary w-100" id="corp-submit-btn" disabled>添加</button>
                                </div>
                            </div>
                            <small class="text-muted">
                                只能选择 SeAT 已收录的军团（即任一成员授权 ESI 后会自动入库）。未收录的军团请先让该军团内任一角色登录 SeAT。
                            </small>
                        </form>
                    </div>
                </div>

                {{-- 军团白名单列表 --}}
                <div class="card mt-3">
                    <div class="card-header">
                        <h3 class="card-title">当前军团白名单 ({{ count($corporationWhitelist) }} 个)</h3>
                    </div>
                    <div class="card-body p-0">
                        @if($corporationWhitelist->isEmpty())
                            <div class="p-3 text-muted">暂无豁免军团。</div>
                        @else
                        <table class="table table-striped mb-0">
                            <thead>
                                <tr>
                                    <th>军团 ID</th>
                                    <th>军团名称</th>
                                    <th>操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($corporationWhitelist as $entry)
                                <tr>
                                    <td>{{ $entry->corporation_id }}</td>
                                    <td>{{ $entry->corporation_name }}</td>
                                    <td>
                                        <form method="POST"
                                              action="{{ route('seat-audit.admin.corporation-whitelist.destroy', $entry->id) }}"
                                              onsubmit="return confirm('确认将此军团从白名单移除？')">
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
            </div>

        </div>

        <div class="mt-2">
            <a href="{{ route('seat-audit.violations.index') }}" class="btn btn-secondary">
                &larr; 返回违规记录
            </a>
        </div>
    </div>
</div>

{{-- 自动补全脚本：两套独立的 search box（角色 + 军团） --}}
@push('javascript')
<script>
$(function() {
    // ============== 角色搜索（保留原逻辑） ==============
    var searchInput = $('#character-search');
    var resultsBox = $('#search-results');
    var idInput = $('#character-id-input');
    var nameDisplay = $('#character-name-display');
    var submitBtn = $('#submit-btn');
    var debounceTimer = null;

    searchInput.on('input', function() {
        var keyword = $(this).val().trim();
        clearTimeout(debounceTimer);

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
                        '<div class="list-group-item text-muted">本地未找到匹配角色</div>'
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

                // 兜底：本地搜不全可点击「在 ESI 中精确查找」按钮，调外部接口（需完整精确名字）
                // 长度 3+ 才显示，避免「ab」等过短关键词触发无意义 ESI 调用
                if (keyword.length >= 3) {
                    resultsBox.append(
                        '<a href="#" class="list-group-item list-group-item-action list-group-item-info esi-search-trigger" ' +
                        'data-name="' + $('<span>').text(keyword).html() + '">' +
                        '<i class="fas fa-globe"></i> 在 ESI 中精确查找 <strong>"' + $('<span>').text(keyword).html() + '"</strong>' +
                        ' <small class="text-muted">（用于加入非 SeAT 内角色，要求完整名字精确匹配）</small>' +
                        '</a>'
                    );
                }

                resultsBox.show();
            });
        }, 300);
    });

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

    // ESI 兜底搜索：点击触发，调外部接口按完整名字查 ID
    resultsBox.on('click', '.esi-search-trigger', function(e) {
        e.preventDefault();
        var name = $(this).data('name');
        var $trigger = $(this);

        // loading 状态：替换文案，禁止重复点击
        $trigger.removeClass('list-group-item-info').addClass('disabled')
                .html('<i class="fas fa-spinner fa-spin"></i> 正在 ESI 查找...');

        $.getJSON('{{ route("seat-audit.api.characters.esi") }}', { name: name })
            .done(function (data) {
                // 把外部结果作为可点击项追加到列表前部，让用户像本地结果一样选择
                if (!data.characters || data.characters.length === 0) {
                    $trigger.removeClass('disabled')
                            .html('<i class="fas fa-exclamation-circle"></i> ESI 未找到该角色（名字必须完整精确匹配）');
                    return;
                }

                // 移除触发按钮
                $trigger.remove();

                // 把每个 ESI 结果作为 .character-option 加入列表顶部
                $.each(data.characters, function (i, char) {
                    resultsBox.prepend(
                        '<a href="#" class="list-group-item list-group-item-action list-group-item-warning character-option" ' +
                        'data-id="' + char.character_id + '" data-name="' + $('<span>').text(char.name).html() + '">' +
                        '<i class="fas fa-globe"></i> <strong>' + $('<span>').text(char.name).html() + '</strong>' +
                        '<small class="text-muted ml-2">ID: ' + char.character_id + '（来自 ESI 外部）</small>' +
                        '</a>'
                    );
                });
            })
            .fail(function (xhr) {
                var msg = xhr.responseJSON && xhr.responseJSON.error
                    ? xhr.responseJSON.error
                    : 'ESI 调用失败（HTTP ' + xhr.status + '）';
                $trigger.removeClass('disabled')
                        .html('<i class="fas fa-exclamation-triangle text-danger"></i> ' + $('<span>').text(msg).html());
            });
    });

    // ============== 军团搜索 ==============
    var corpInput = $('#corp-search');
    var corpResultsBox = $('#corp-search-results');
    var corpIdInput = $('#corp-id-input');
    var corpNameDisplay = $('#corp-name-display');
    var corpSubmitBtn = $('#corp-submit-btn');
    var corpDebounceTimer = null;

    corpInput.on('input', function() {
        var keyword = $(this).val().trim();
        clearTimeout(corpDebounceTimer);

        corpIdInput.val('');
        corpNameDisplay.val('');
        corpSubmitBtn.prop('disabled', true);

        if (keyword.length < 2) {
            corpResultsBox.hide().empty();
            return;
        }

        corpDebounceTimer = setTimeout(function() {
            $.getJSON('{{ route("seat-audit.api.corporations") }}', { q: keyword }, function(data) {
                corpResultsBox.empty();

                if (data.length === 0) {
                    corpResultsBox.append(
                        '<div class="list-group-item text-muted">未找到匹配的军团（SeAT 仅收录已被授权过的军团）</div>'
                    );
                } else {
                    $.each(data, function(i, corp) {
                        var safeName = $('<span>').text(corp.name).html();
                        var safeTicker = $('<span>').text(corp.ticker || '').html();
                        corpResultsBox.append(
                            '<a href="#" class="list-group-item list-group-item-action corp-option" ' +
                            'data-id="' + corp.corporation_id + '" data-name="' + safeName + '">' +
                            '<strong>' + safeName + '</strong>' +
                            (safeTicker ? ' <span class="badge badge-secondary">' + safeTicker + '</span>' : '') +
                            '<small class="text-muted ml-2">ID: ' + corp.corporation_id + '</small>' +
                            '</a>'
                        );
                    });
                }

                corpResultsBox.show();
            });
        }, 300);
    });

    corpResultsBox.on('click', '.corp-option', function(e) {
        e.preventDefault();
        var corpId = $(this).data('id');
        var corpName = $(this).data('name');

        corpIdInput.val(corpId);
        corpNameDisplay.val(corpName);
        corpInput.val(corpName);
        corpSubmitBtn.prop('disabled', false);
        corpResultsBox.hide().empty();
    });

    // ============== 点击页面其他区域关闭两个下拉 ==============
    $(document).on('click', function(e) {
        if (!$(e.target).closest('#character-search, #search-results').length) {
            resultsBox.hide();
        }
        if (!$(e.target).closest('#corp-search, #corp-search-results').length) {
            corpResultsBox.hide();
        }
    });
});
</script>
@endpush
@stop
