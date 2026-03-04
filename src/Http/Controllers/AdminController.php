<?php

// D:\VS Code\Project test\seat-audit-monitor\src\Http\Controllers\AdminController.php
// 管理控制器，处理监控物品和白名单的增删查操作，以及前端自动补全 API

namespace Seat\SeatAuditMonitor\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

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
     * 显示白名单管理页面
     */
    public function whitelist()
    {
        $whitelist = DB::table('seat_audit_whitelist')
            ->orderBy('character_name')
            ->get();

        return view('seat-audit-monitor::admin.whitelist', compact('whitelist'));
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

        return redirect()->route('seat-audit.admin.whitelist')
            ->with('success', '角色已加入白名单。');
    }

    /**
     * 删除白名单角色
     */
    public function destroyWhitelist(int $id)
    {
        DB::table('seat_audit_whitelist')->where('id', $id)->delete();

        return redirect()->route('seat-audit.admin.whitelist')
            ->with('success', '角色已从白名单移除。');
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
