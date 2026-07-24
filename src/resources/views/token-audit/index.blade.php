{{-- src/resources/views/token-audit/index.blade.php --}}
{{-- 固定军团 98588384 的只读令牌审查页：只接收脱敏 DTO，绝不渲染 token 或 scope。 --}}

@extends('web::layouts.grids.12')

@section('title', '令牌审查')

@section('full')
<div class="row">
    <div class="col-12">
        <div class="card mb-3">
            <div class="card-header d-flex p-0 with-border">
                <h3 class="card-title p-2 mb-0"><i class="fas fa-id-card"></i> 令牌审查</h3>
                <ul class="nav nav-pills ml-auto p-1">
                    @foreach($statusLabels as $statusKey => $statusLabel)
                        @php
                            // 状态标签只改变令牌状态过滤；保留两个时间范围、搜索和账号组分页数量。
                            // 刻意不保留 page，切换状态必须回到筛选结果的第一页。
                            $tabParameters = [
                                'status'        => $statusKey,
                                'last_seen'     => $lastSeen,
                                'joined_within' => $joinedWithin,
                                'q'             => $search,
                                'per_page'      => $perPage,
                            ];
                        @endphp
                        <li class="nav-item">
                            <a href="{{ route('seat-audit.token-audit.index', $tabParameters) }}"
                               class="nav-link py-1 px-2 {{ $status === $statusKey ? 'active' : '' }}"
                               @if($status === $statusKey) aria-current="page" @endif>
                                {{ $statusLabel }}
                                <span class="badge badge-light ml-1">{{ $statusCounts[$statusKey] }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
            <div class="card-body py-2">
                <p class="text-muted small mb-2">
                    审查范围：军团 <code>98588384</code> 的当前游戏内成员。页面只读取 SeAT 已有状态，不刷新令牌、不调用 ESI，亦不读取或显示 token 与 scope。
                </p>

                <form method="GET" action="{{ route('seat-audit.token-audit.index') }}" class="form-row align-items-end">
                    <input type="hidden" name="status" value="{{ $status }}">
                    <div class="col-sm-6 col-md-3 mb-1">
                        <label class="small mb-1" for="token_audit_search">搜索</label>
                        <input id="token_audit_search" type="search" name="q" value="{{ $search }}" maxlength="100" class="form-control form-control-sm" placeholder="角色、主角色、头衔或 ID">
                    </div>
                    <div class="col-sm-3 col-md-2 mb-1">
                        <label class="small mb-1" for="token_audit_last_seen">最后上线</label>
                        <select id="token_audit_last_seen" name="last_seen" class="form-control form-control-sm">
                            @foreach($lastSeenLabels as $lastSeenKey => $lastSeenLabel)
                                <option value="{{ $lastSeenKey }}" @selected($lastSeen === $lastSeenKey)>{{ $lastSeenLabel }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-sm-3 col-md-2 mb-1">
                        <label class="small mb-1" for="token_audit_joined_within">入团时间</label>
                        <select id="token_audit_joined_within" name="joined_within" class="form-control form-control-sm">
                            @foreach($joinedWithinLabels as $joinedWithinKey => $joinedWithinLabel)
                                <option value="{{ $joinedWithinKey }}" @selected($joinedWithin === $joinedWithinKey)>{{ $joinedWithinLabel }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-sm-3 col-md-2 mb-1">
                        <label class="small mb-1" for="token_audit_per_page">每页账号组</label>
                        <select id="token_audit_per_page" name="per_page" class="form-control form-control-sm">
                            @foreach([10, 25, 50, 100] as $perPageOption)
                                <option value="{{ $perPageOption }}" @selected($perPage === $perPageOption)>{{ $perPageOption }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-sm-3 col-md-3 mb-1">
                        <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-filter"></i> 筛选</button>
                        {{-- 清除所有附加条件但保留状态标签；新请求自然回到第一页。 --}}
                        <a href="{{ route('seat-audit.token-audit.index', ['status' => $status]) }}" class="btn btn-sm btn-secondary">清除</a>
                        {{-- 导出只透传角色级业务筛选；不能携带 page/per_page，否则会误导用户认为仅导出当前页。 --}}
                        <a href="{{ route('seat-audit.token-audit.export', [
                            'status' => $status,
                            'last_seen' => $lastSeen,
                            'joined_within' => $joinedWithin,
                            'q' => $search,
                        ]) }}" class="btn btn-sm btn-outline-success"><i class="fas fa-download"></i> 导出 CSV</a>
                    </div>
                </form>
                <p class="text-muted small mb-0 mt-2">CSV 导出当前筛选条件下的全部匹配角色，不受账号组分页限制；不包含 token、scope 或 SeAT 内部用户 ID。</p>
            </div>
        </div>

        <div class="card">
            <div class="card-header py-2">
                <h3 class="card-title">{{ $statusLabels[$status] }} <small class="text-muted ml-2">共 {{ $auditGroups->total() }} 个账号组</small></h3>
                <div class="card-tools text-muted small">相对时间按 UTC 计算；悬停可查看精确 UTC 时间。</div>
            </div>
            <div class="card-body p-0">
                @if($auditGroups->isEmpty())
                    <div class="p-3 text-muted">当前筛选条件下没有匹配的成员。</div>
                @else
                    <div class="table-responsive">
                        {{-- table-sm 与紧凑单元格共同降低行高；保留响应式容器以支持窄屏横向查看。 --}}
                        <table class="table table-sm table-hover mb-0">
                            <thead>
                                <tr>
                                    <th class="text-center">令牌</th>
                                    <th>名字</th>
                                    <th>角色头衔</th>
                                    <th>技能列表</th>
                                    <th class="text-nowrap">已加入</th>
                                    <th class="text-nowrap">最后上线</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($auditGroups as $group)
                                    <tr class="table-active small">
                                        <td colspan="6" class="py-1">
                                            @if($group->primaryCharacterId !== null)
                                                <strong><i class="fas fa-user-friends"></i> 主要角色：{{ $group->primaryCharacterName }}</strong>
                                                @if(! $group->primaryCharacterInScope)
                                                    <span class="text-muted ml-2">主角色不在当前军团成员范围</span>
                                                @endif
                                            @else
                                                <strong><i class="fas fa-user"></i> 独立角色：{{ $group->primaryCharacterName }}</strong>
                                            @endif
                                        </td>
                                    </tr>
                                    @foreach($group->characters as $character)
                                        @php
                                            // 颜色只表示 last_login 距当前 UTC 基准的时间段，不表示 token 有效性或当前在线状态。
                                            $lastLoginBadge = match ($character->lastLoginAt->band) {
                                                'within_30' => 'badge-success',
                                                'within_60' => 'badge-warning',
                                                'over_60' => 'badge-danger',
                                                default => 'badge-secondary',
                                            };
                                        @endphp
                                        <tr>
                                            <td class="align-middle text-center py-1">
                                                @if($character->tokenStatus === 'normal')
                                                    <i class="fas fa-check-circle text-success" title="状态正常" aria-label="状态正常"></i>
                                                @elseif($character->tokenStatus === 'expired')
                                                    <i class="fas fa-exclamation-triangle text-warning" title="账号过期" aria-label="账号过期"></i>
                                                @else
                                                    <i class="fas fa-user-times text-danger" title="无 SeAT 用户" aria-label="无 SeAT 用户"></i>
                                                @endif
                                            </td>
                                            <td class="align-middle py-1 text-nowrap">
                                                <div class="d-flex align-items-center">
                                                    {{-- 肖像由浏览器直接加载公开图片服务；失败时隐藏图片并展示本地图标，不经插件服务器。 --}}
                                                    <img src="https://images.evetech.net/characters/{{ $character->characterId }}/portrait?size=64"
                                                         alt="{{ $character->characterName }} 肖像"
                                                         width="28" height="28" loading="lazy" class="img-circle mr-1"
                                                         onerror="this.style.display='none'; this.nextElementSibling.classList.remove('d-none');">
                                                    <i class="fas fa-user-circle text-muted mr-1 d-none" aria-hidden="true"></i>
                                                    <span>{{ $character->characterName }}</span>
                                                    <small class="text-muted ml-1">#{{ $character->characterId }} · {{ $character->isPrimaryCharacter() ? '主角色' : '子角色' }}</small>
                                                </div>
                                            </td>
                                            <td class="align-middle py-1">{{ $character->displayTitle() }}</td>
                                            <td class="align-middle py-1"><span class="text-muted">暂未接入</span></td>
                                            <td class="align-middle py-1 text-nowrap" @if($character->joinedAt->tooltip) title="{{ $character->joinedAt->tooltip }}" @endif>
                                                {{ $character->joinedAt->label }}
                                            </td>
                                            <td class="align-middle py-1 text-nowrap" @if($character->lastLoginAt->tooltip) title="{{ $character->lastLoginAt->tooltip }}" @endif>
                                                <span class="badge {{ $lastLoginBadge }}">{{ $character->lastLoginAt->label }}</span>
                                            </td>
                                        </tr>
                                    @endforeach
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
            @if($auditGroups->hasPages())
                <div class="card-footer py-2">{{ $auditGroups->links() }}</div>
            @endif
        </div>
    </div>
</div>
@stop
