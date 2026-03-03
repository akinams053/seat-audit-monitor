<?php

// D:\VS Code\Project test\seat-audit-monitor\src\Http\Controllers\AdminController.php
// 管理控制器，处理监控物品和白名单的增删查操作

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
     */
    public function storeItem(Request $request)
    {
        $request->validate([
            'type_id'   => 'required|integer|min:1|unique:seat_audit_monitor_items,type_id',
            'item_name' => 'required|string|max:255',
        ]);

        DB::table('seat_audit_monitor_items')->insert([
            'type_id'    => $request->type_id,
            'item_name'  => $request->item_name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return redirect()->route('seat-audit.admin.items')
            ->with('success', '监控物品已添加。');
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
}
