{{-- src/resources/views/violations/index.blade.php --}}
{{-- 违规记录列表视图：双方角色/双方军团/来源细分/Contract 详情 modal --}}
{{-- 控制器变量：$violations / $startDate / $endDate / $auditType / $keyword --}}

@extends('web::layouts.grids.12')

@section('title', '违规交易记录')

@section('full')
<div class="row">
    <div class="col-12">

        {{-- 操作反馈提示 --}}
        @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
        @endif
        @if(session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
        @endif

        {{-- auditTypeLabels / auditTypes 由 Controller 基于 AuditType enum 注入，Blade 不再重复维护类型字符串。 --}}
        @php
            // availability 中文 + 颜色映射，UI 来源 badge 用
            $availabilityLabels = [
                'public'      => '公开',
                'personal'    => '私人',
                'corporation' => '军团',
                'alliance'    => '联盟',
            ];
            $availabilityClasses = [
                'public'      => 'badge-success',
                'personal'    => 'badge-warning',
                'corporation' => 'badge-info',
                'alliance'    => 'badge-primary',
            ];
        @endphp

        {{-- 筛选区 --}}
        <div class="card mb-3">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-filter"></i> 筛选条件</h3>
            </div>
            <div class="card-body">
                <form method="GET" action="{{ route('seat-audit.violations.index') }}" class="form-inline">
                    <div class="form-group mr-3">
                        <label for="audit_type" class="mr-2">审计类型</label>
                        <select id="audit_type" name="audit_type" class="form-control form-control-sm">
                            <option value="all" @selected(($auditType ?? 'all') === 'all')>全部</option>
                            <option value="{{ $auditTypes['wallet'] }}" @selected(($auditType ?? 'all') === $auditTypes['wallet'])>{{ $auditTypeLabels[$auditTypes['wallet']] }}</option>
                            <option value="{{ $auditTypes['contracts'] }}" @selected(($auditType ?? 'all') === $auditTypes['contracts'])>{{ $auditTypeLabels[$auditTypes['contracts']] }}</option>
                        </select>
                    </div>
                    <div class="form-group mr-3">
                        <label for="start_date" class="mr-2">开始日期</label>
                        <input type="date" id="start_date" name="start_date"
                               class="form-control form-control-sm" value="{{ $startDate ?? '' }}">
                    </div>
                    <div class="form-group mr-3">
                        <label for="end_date" class="mr-2">结束日期</label>
                        <input type="date" id="end_date" name="end_date"
                               class="form-control form-control-sm" value="{{ $endDate ?? '' }}">
                    </div>
                    {{-- 通用关键词：模糊匹配角色名（双方）和军团名/ticker（双方，仅合同行有值） --}}
                    <div class="form-group mr-3">
                        <label for="keyword" class="mr-2">角色 / 军团</label>
                        <input type="text" id="keyword" name="keyword"
                               class="form-control form-control-sm"
                               placeholder="角色名 / 军团名 / ticker"
                               value="{{ $keyword ?? '' }}"
                               maxlength="100"
                               style="min-width: 200px;">
                    </div>
                    <button type="submit" class="btn btn-sm btn-primary mr-2">
                        <i class="fas fa-search"></i> 筛选
                    </button>
                    <a href="{{ route('seat-audit.violations.index') }}" class="btn btn-sm btn-secondary mr-3">
                        <i class="fas fa-times"></i> 清除
                    </a>
                    <a href="{{ route('seat-audit.violations.export', array_filter([
                        'audit_type' => ($auditType ?? 'all') !== 'all' ? $auditType : '',
                        'start_date' => $startDate ?? '',
                        'end_date'   => $endDate ?? '',
                        'keyword'    => $keyword ?? '',
                    ])) }}" class="btn btn-sm btn-success">
                        <i class="fas fa-file-excel"></i> 导出 CSV (Excel)
                    </a>
                </form>
                @if($startDate || $endDate || ($auditType ?? 'all') !== 'all' || !empty($keyword))
                <div class="mt-2 text-muted small">
                    <i class="fas fa-info-circle"></i>
                    当前筛选：
                    @if(($auditType ?? 'all') !== 'all')
                        <br>审计类型：<strong>{{ $auditTypeLabels[$auditType] ?? $auditType }}</strong>
                    @endif
                    @if(!empty($keyword))
                        <br>关键词包含：<strong>{{ $keyword }}</strong>（角色名 / 军团名 / ticker 任一字段命中）
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

        {{-- 违规列表卡片 --}}
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">
                    违规交易记录
                    <small class="text-muted ml-2" title="角色白名单 OR 单方拦截 + 军团白名单 AND 双方豁免（仅合同生效）">
                        <i class="fas fa-user-shield"></i> 白名单实时过滤
                    </small>
                </h3>
                <div class="card-tools">
                    @can('seat-audit-monitor.admin')
                    <form method="POST" action="{{ route('seat-audit.violations.scan') }}" class="form-inline d-inline-flex align-items-center">
                        @csrf
                        <select name="type" class="form-control form-control-sm mr-1" aria-label="审计类型">
                            <option value="all">全部</option>
                            <option value="wallet">钱包交易</option>
                            <option value="contracts">合同</option>
                        </select>
                        <button type="submit" class="btn btn-sm btn-warning">
                            <i class="fas fa-search"></i> 立即审查
                        </button>
                    </form>
                    <form method="POST" action="{{ route('seat-audit.violations.resolve-unknown') }}" class="form-inline d-inline ml-1">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-info"
                                title="批量调 ESI 公开接口解析「Unknown (ID: X)」外部角色名 + 当前所属军团 + 军团名字">
                            <i class="fas fa-sync"></i> 解析未知来源
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
                        @if($startDate || $endDate || !empty($keyword))
                            当前筛选条件下暂无违规记录。
                        @else
                            暂无违规记录。
                        @endif
                    </div>
                @else
                <table class="table table-striped table-hover mb-0">
                    <thead>
                        <tr>
                            <th>发起方</th>
                            <th>发起方军团</th>
                            <th>接收方</th>
                            <th>接收方军团</th>
                            <th>物品名称</th>
                            <th>交易金额 (ISK)</th>
                            <th>来源</th>
                            <th>合同详情</th>
                            <th>发生时间</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($violations as $v)
                        @php
                            // 预先组装合同详情 modal 的 data payload，避免在 attribute 里跨行写 @json
                            // 仅合同行使用；钱包行不会读到
                            $contractModalPayload = $v->audit_type === $auditTypes['contracts'] ? [
                                'details'               => $v->details,
                                'amount'                => $v->amount,
                                'violation_time'        => $v->violation_time,
                                'item_name'             => $v->item_name,
                                'type_id'               => $v->type_id,
                                'contract_id'           => $v->contract_id,
                                'contract_availability' => $v->contract_availability,
                                'issuer_corp_name'      => $v->issuer_corp_name,
                                'issuer_corp_ticker'    => $v->issuer_corp_ticker,
                                'acceptor_corp_name'    => $v->acceptor_corp_name,
                                'acceptor_corp_ticker'  => $v->acceptor_corp_ticker,
                            ] : null;
                        @endphp
                        <tr class="{{ $v->audit_type === $auditTypes['contracts'] && (float) $v->amount === 0.0 ? 'table-secondary' : '' }}">
                            {{-- 发起方角色名 --}}
                            <td>{{ $v->character_name }}</td>
                            {{-- 发起方军团：合同行才有；显示 ticker（hover 显示 name） --}}
                            <td>
                                @if($v->audit_type === $auditTypes['contracts'] && !empty($v->issuer_corp_ticker))
                                    <span title="{{ $v->issuer_corp_name }}">{{ $v->issuer_corp_ticker }}</span>
                                @elseif($v->audit_type === $auditTypes['contracts'] && !empty($v->issuer_corp_name))
                                    <span>{{ $v->issuer_corp_name }}</span>
                                @else
                                    -
                                @endif
                            </td>
                            {{-- 接收方角色名 --}}
                            <td>
                                @if($v->audit_type === $auditTypes['wallet'])
                                    <span class="badge badge-secondary">市场</span>
                                @else
                                    {{ $v->counterparty_name ?? '-' }}
                                @endif
                            </td>
                            {{-- 接收方军团 --}}
                            <td>
                                @if($v->audit_type === $auditTypes['wallet'])
                                    <span class="text-muted">市场</span>
                                @elseif(!empty($v->acceptor_corp_ticker))
                                    <span title="{{ $v->acceptor_corp_name }}">{{ $v->acceptor_corp_ticker }}</span>
                                @elseif(!empty($v->acceptor_corp_name))
                                    <span>{{ $v->acceptor_corp_name }}</span>
                                @else
                                    -
                                @endif
                            </td>
                            {{-- 物品 --}}
                            <td>{{ $v->item_name }}</td>
                            {{-- 金额 --}}
                            <td>
                                {{ number_format($v->amount, 2) }}
                                @if($v->audit_type === $auditTypes['contracts'] && (float) $v->amount === 0.0)
                                    <small class="text-muted">(零金额)</small>
                                @endif
                            </td>
                            {{-- 来源 badge：钱包 + 4 种合同 availability 细分 --}}
                            <td>
                                @if($v->audit_type === $auditTypes['wallet'])
                                    <span class="badge badge-secondary">钱包</span>
                                @elseif($v->audit_type === $auditTypes['contracts'])
                                    @php
                                        $availKey   = $v->contract_availability;
                                        $availLabel = $availabilityLabels[$availKey] ?? null;
                                        $availClass = $availabilityClasses[$availKey] ?? 'badge-warning';
                                    @endphp
                                    @if($availLabel)
                                        <span class="badge {{ $availClass }}">合同·{{ $availLabel }}</span>
                                    @else
                                        <span class="badge badge-warning">合同</span>
                                    @endif
                                @else
                                    -
                                @endif
                            </td>
                            {{-- 合同详情：合同行作为按钮触发 modal 显示快照详情；样式为可点击按钮，显示 #contract_id --}}
                            <td>
                                @if($v->audit_type === $auditTypes['contracts'])
                                    <button type="button"
                                            class="btn btn-outline-primary btn-sm contract-detail-btn"
                                            data-toggle="modal"
                                            data-target="#contractDetailModal"
                                            data-violation='@json($contractModalPayload)'
                                            title="点击查看合同详情 (ID: {{ $v->contract_id }})">
                                        <i class="fas fa-file-contract"></i> #{{ $v->contract_id }}
                                    </button>
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

{{-- 合同详情 modal：所有行共用一个，JS 接管点击事件动态填充 --}}
<div class="modal fade" id="contractDetailModal" tabindex="-1" role="dialog" aria-labelledby="contractDetailModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="contractDetailModalLabel">合同详情</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                {{-- 三个区块：合同 / 命中物品 / 三方角色，由 JS 填充 --}}
                <h6 class="text-muted"><i class="fas fa-file-contract"></i> 合同信息</h6>
                <table class="table table-sm table-bordered mb-3" id="contractInfoTable">
                    <tbody><!-- JS fill --></tbody>
                </table>

                <h6 class="text-muted"><i class="fas fa-cube"></i> 命中监控物品</h6>
                <table class="table table-sm table-bordered mb-3" id="contractItemTable">
                    <tbody><!-- JS fill --></tbody>
                </table>

                <h6 class="text-muted"><i class="fas fa-users"></i> 三方角色快照</h6>
                <table class="table table-sm table-bordered mb-3" id="contractPartiesTable">
                    <tbody><!-- JS fill --></tbody>
                </table>

                <div class="text-muted small">
                    <i class="fas fa-info-circle"></i> 上述信息来自违规记录的快照（违规发生时）；合同发起方/接收方军团显示的是<strong>当前所属军团</strong>。
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">关闭</button>
            </div>
        </div>
    </div>
</div>

@push('javascript')
<script>
$(function() {
    // 中文/颜色映射，与 Blade 端 $availabilityLabels 保持一致
    var availabilityLabels = {
        'public': '公开',
        'personal': '私人',
        'corporation': '军团',
        'alliance': '联盟'
    };

    // 安全的字符串渲染（escape HTML 防 XSS）
    function esc(v) {
        if (v === null || v === undefined || v === '') return '<span class="text-muted">-</span>';
        return $('<span>').text(String(v)).html();
    }

    function num(v) {
        if (v === null || v === undefined) return '0';
        // 千分位 + 两位小数；接受字符串或数字
        var n = parseFloat(v);
        if (isNaN(n)) return esc(v);
        return n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    // 点击 Contract ID 按钮：从 data-violation 取数据填进 modal
    $('#contractDetailModal').on('show.bs.modal', function (event) {
        var button = $(event.relatedTarget);
        var data = button.data('violation');

        if (!data || !data.details) {
            console.warn('[contract-modal] 缺少 violation 数据');
            return;
        }

        // details 在 PHP 端是 JSON 字符串，data() 自动 parse 后这里可能已经是对象
        var details = (typeof data.details === 'string') ? JSON.parse(data.details) : data.details;
        var contract = details.contract || {};
        var item     = details.item || {};
        var parties  = details.parties || {};

        var availability = data.contract_availability;
        var availabilityLabel = availabilityLabels[availability] || availability || '-';

        // 渲染【合同信息】
        var contractRows = [
            ['Contract ID', esc(data.contract_id)],
            ['标题', esc(contract.title || '(无标题)')],
            ['合同类型', esc(contract.type === 'item_exchange' ? '物品交换' : (contract.type === 'auction' ? '拍卖' : contract.type))],
            ['可见性', '<span class="badge badge-' + ({'public':'success','personal':'warning','corporation':'info','alliance':'primary'}[availability] || 'warning') + '">' + esc(availabilityLabel) + '</span>'],
            ['状态', esc(contract.status)],
            ['Price (issuer 收的钱)', num(contract.price) + ' ISK'],
            ['Reward (issuer 付的钱)', num(contract.reward) + ' ISK'],
            ['违规记录金额', num(data.amount) + ' ISK <small class="text-muted">(=max(price, reward))</small>'],
            ['发起时间', esc(contract.date_issued)],
            ['完成时间', esc(contract.date_completed)],
            ['违规时间快照', esc(data.violation_time)],
        ];
        var $contractTbody = $('#contractInfoTable tbody').empty();
        contractRows.forEach(function (r) {
            $contractTbody.append('<tr><th style="width:35%">' + r[0] + '</th><td>' + r[1] + '</td></tr>');
        });

        // 渲染【命中物品】
        var $itemTbody = $('#contractItemTable tbody').empty();
        $itemTbody.append('<tr><th style="width:35%">物品名称</th><td>' + esc(data.item_name) + '</td></tr>');
        $itemTbody.append('<tr><th>Type ID</th><td><code>' + esc(item.type_id || data.type_id) + '</code></td></tr>');
        $itemTbody.append('<tr><th>数量</th><td>' + num(item.quantity || 0) + '</td></tr>');
        $itemTbody.append('<tr><th>Is Included</th><td>' + esc(item.is_included) + ' <small class="text-muted">(1=包含在合同里，0=要求作为交换)</small></td></tr>');
        if (item.record_ids && item.record_ids.length > 1) {
            $itemTbody.append('<tr><th>原始 record_id 列表</th><td><code>' + esc(item.record_ids.join(', ')) + '</code> <small class="text-muted">(同 type_id 多 record 已聚合)</small></td></tr>');
        }

        // 渲染【三方角色】
        var issuerCorp   = data.issuer_corp_name ? (esc(data.issuer_corp_name) + (data.issuer_corp_ticker ? ' [' + esc(data.issuer_corp_ticker) + ']' : '')) : '<span class="text-muted">-</span>';
        var acceptorCorp = data.acceptor_corp_name ? (esc(data.acceptor_corp_name) + (data.acceptor_corp_ticker ? ' [' + esc(data.acceptor_corp_ticker) + ']' : '')) : '<span class="text-muted">-</span>';
        var $partiesTbody = $('#contractPartiesTable tbody').empty();
        $partiesTbody.append('<tr><th style="width:35%">Issuer (发起方)</th><td>' + esc(parties.issuer_name) + ' <small class="text-muted">ID: ' + esc(contract.issuer_id) + '</small></td></tr>');
        $partiesTbody.append('<tr><th>Issuer 当前军团</th><td>' + issuerCorp + '</td></tr>');
        $partiesTbody.append('<tr><th>Assignee (指定方)</th><td>' + esc(parties.assignee_name) + ' <small class="text-muted">ID: ' + esc(contract.assignee_id) + '</small></td></tr>');
        $partiesTbody.append('<tr><th>Acceptor (接收方)</th><td>' + esc(parties.acceptor_name) + ' <small class="text-muted">ID: ' + esc(contract.acceptor_id) + '</small></td></tr>');
        $partiesTbody.append('<tr><th>Acceptor 当前军团</th><td>' + acceptorCorp + '</td></tr>');

        // 修改标题加上 contract_id
        $('#contractDetailModalLabel').text('合同 #' + data.contract_id + ' 详情');
    });
});
</script>
@endpush
@stop
