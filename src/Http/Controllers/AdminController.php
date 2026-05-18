<?php

// src/Http/Controllers/AdminController.php
// 管理控制器，处理监控物品和白名单的增删查操作，以及前端自动补全 API

namespace Seat\SeatAuditMonitor\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AdminController extends Controller
{
    /**
     * 显示监控物品管理页面
     */
    public function items()
    {
        $items = DB::table('seat_audit_monitor_items')
            ->orderBy('item_name')
            ->get();

        return view('seat-audit-monitor::admin.items', compact('items'));
    }

    /**
     * 添加监控物品
     * item_name 由服务端从 SDE invTypes 表自动查询填充
     */
    public function storeItem(Request $request)
    {
        $request->validate([
            'type_id' => 'required|integer|min:1|unique:seat_audit_monitor_items,type_id',
        ]);

        // 从 SDE 静态数据表查询物品名称
        $type = DB::table('invTypes')
            ->where('typeID', $request->type_id)
            ->first();

        // 如果 SDE 中不存在该 type_id，返回验证错误
        if (!$type) {
            return redirect()->route('seat-audit.admin.items')
                ->withErrors(['type_id' => '未找到该物品 ID，请确认 type_id 是否正确。'])
                ->withInput();
        }

        DB::table('seat_audit_monitor_items')->insert([
            'type_id'    => $request->type_id,
            'item_name'  => $type->typeName,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return redirect()->route('seat-audit.admin.items')
            ->with('success', '监控物品已添加：' . $type->typeName);
    }

    /**
     * 删除监控物品
     */
    public function destroyItem(int $id)
    {
        DB::table('seat_audit_monitor_items')->where('id', $id)->delete();

        return redirect()->route('seat-audit.admin.items')
            ->with('success', '监控物品已删除。');
    }

    /**
     * 显示白名单管理页面（角色 + 军团两个 tab 共用一个视图）
     */
    public function whitelist()
    {
        $whitelist = DB::table('seat_audit_whitelist')
            ->orderBy('character_name')
            ->get();

        // 军团白名单同步加载，供视图的「军团」tab 渲染
        $corporationWhitelist = DB::table('seat_audit_corporation_whitelist')
            ->orderBy('corporation_name')
            ->get();

        // 通过查询参数控制默认激活的 tab（character / corporation），UI 刷新后保留上下文
        $activeTab = in_array(request('tab'), ['character', 'corporation'], true)
            ? request('tab')
            : 'character';

        return view(
            'seat-audit-monitor::admin.whitelist',
            compact('whitelist', 'corporationWhitelist', 'activeTab')
        );
    }

    /**
     * 添加白名单角色
     */
    public function storeWhitelist(Request $request)
    {
        $request->validate([
            'character_id'   => 'required|integer|min:1|unique:seat_audit_whitelist,character_id',
            'character_name' => 'required|string|max:255',
        ]);

        DB::table('seat_audit_whitelist')->insert([
            'character_id'   => $request->character_id,
            'character_name' => $request->character_name,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        return redirect()->route('seat-audit.admin.whitelist', ['tab' => 'character'])
            ->with('success', '角色已加入白名单。');
    }

    /**
     * 删除白名单角色
     */
    public function destroyWhitelist(int $id)
    {
        DB::table('seat_audit_whitelist')->where('id', $id)->delete();

        return redirect()->route('seat-audit.admin.whitelist', ['tab' => 'character'])
            ->with('success', '角色已从白名单移除。');
    }

    /**
     * 添加军团白名单
     * corporation_name 由服务端从 corporation_infos 自动填充，避免前端伪造
     */
    public function storeCorporationWhitelist(Request $request)
    {
        $request->validate([
            'corporation_id' => 'required|integer|min:1|unique:seat_audit_corporation_whitelist,corporation_id',
        ]);

        // 从 SeAT 已收录的 corporation_infos 查官方军团名
        $corp = DB::table('corporation_infos')
            ->where('corporation_id', $request->corporation_id)
            ->first();

        if (!$corp) {
            return redirect()->route('seat-audit.admin.whitelist', ['tab' => 'corporation'])
                ->withErrors(['corporation_id' => '未在 SeAT 找到该军团信息，请确认 corporation_id 或先让 SeAT 拉取相关角色数据。'])
                ->withInput();
        }

        DB::table('seat_audit_corporation_whitelist')->insert([
            'corporation_id'   => $request->corporation_id,
            'corporation_name' => $corp->name,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        return redirect()->route('seat-audit.admin.whitelist', ['tab' => 'corporation'])
            ->with('success', '军团已加入白名单：' . $corp->name);
    }

    /**
     * 删除军团白名单
     */
    public function destroyCorporationWhitelist(int $id)
    {
        DB::table('seat_audit_corporation_whitelist')->where('id', $id)->delete();

        return redirect()->route('seat-audit.admin.whitelist', ['tab' => 'corporation'])
            ->with('success', '军团已从白名单移除。');
    }

    // ========== AJAX API 方法 ==========

    /**
     * 按角色名模糊搜索，返回 JSON 结果列表
     * 数据来源：SeAT 已拉取的 character_infos 表
     * 请求参数：q=搜索关键词（至少 2 个字符）
     * 返回格式：[{character_id: 12345, name: "Pilot Name"}, ...]
     */
    public function searchCharacters(Request $request)
    {
        $keyword = $request->input('q', '');

        // 关键词过短直接返回空数组，避免全表扫描
        if (mb_strlen($keyword) < 2) {
            return response()->json([]);
        }

        $results = DB::table('character_infos')
            ->where('name', 'LIKE', '%' . $keyword . '%')
            ->orderBy('name')
            ->limit(10)
            ->get(['character_id', 'name']);

        return response()->json($results);
    }

    /**
     * 在 ESI 公开接口按角色名精确查找 character_id（无需 token）
     * 用于白名单加入「不在 SeAT 内」的外部角色
     * 请求参数：name=完整角色名（ESI 大小写不敏感，但必须完整匹配）
     * 返回格式：{character_id: 12345, name: "Pilot Name"} 或 {error: "..."}
     */
    public function searchCharactersEsi(Request $request)
    {
        $name = trim((string) $request->input('name', ''));

        // 至少 3 字符避免误触；最长 37 字符（EVE 角色名上限）
        if (mb_strlen($name) < 3 || mb_strlen($name) > 37) {
            return response()->json(['error' => '请输入完整角色名（3-37 字符）'], 400);
        }

        try {
            $response = Http::timeout(15)
                ->acceptJson()
                ->asJson()
                ->post('https://esi.evetech.net/latest/universe/ids/', [$name]);
        } catch (\Throwable $e) {
            Log::warning('[seat-audit:esi-id-search] HTTP 异常：' . $e->getMessage());
            return response()->json(['error' => 'ESI 调用失败：' . $e->getMessage()], 502);
        }

        if (!$response->successful()) {
            return response()->json([
                'error' => 'ESI 返回非成功状态 ' . $response->status(),
            ], 502);
        }

        $data = $response->json();
        $characters = $data['characters'] ?? [];

        if (empty($characters)) {
            return response()->json(['error' => '未在 ESI 找到该角色（名字必须完整且精确匹配）'], 404);
        }

        // ESI 可能返回多个完全同名角色（罕见但可能）；都返回让前端列出
        return response()->json([
            'characters' => array_map(fn ($c) => [
                'character_id' => $c['id'],
                'name'         => $c['name'],
            ], $characters),
        ]);
    }

    /**
     * 按军团名/ticker 模糊搜索，返回 JSON 结果列表
     * 数据来源：SeAT 收录的 corporation_infos 表
     * 请求参数：q=搜索关键词（至少 2 个字符）
     * 返回格式：[{corporation_id: 98123456, name: "Some Corp", ticker: "ABC"}, ...]
     */
    public function searchCorporations(Request $request)
    {
        $keyword = $request->input('q', '');

        // 关键词过短直接返回空数组，避免对 corporation_infos 全表 LIKE 扫描
        if (mb_strlen($keyword) < 2) {
            return response()->json([]);
        }

        $results = DB::table('corporation_infos')
            ->where(function ($q) use ($keyword) {
                $q->where('name', 'LIKE', '%' . $keyword . '%')
                  ->orWhere('ticker', 'LIKE', '%' . $keyword . '%');
            })
            ->orderBy('name')
            ->limit(10)
            ->get(['corporation_id', 'name', 'ticker']);

        return response()->json($results);
    }

    /**
     * 根据 type_id 查询物品名称，返回 JSON
     * 数据来源：SDE invTypes 表
     * 请求参数：type_id=物品ID
     * 返回格式：{typeName: "Tritanium"} 或 {error: "未找到"}
     */
    public function getItemName(Request $request)
    {
        $typeId = $request->input('type_id');

        if (!$typeId) {
            return response()->json(['error' => '缺少 type_id 参数'], 400);
        }

        $type = DB::table('invTypes')
            ->where('typeID', $typeId)
            ->first(['typeName']);

        if (!$type) {
            return response()->json(['error' => '未找到该物品 ID'], 404);
        }

        return response()->json(['typeName' => $type->typeName]);
    }
}
