<?php

// D:\VS Code\Project test\seat-audit-monitor\src\Http\routes.php
// 插件路由定义
// SeAT 4.x 的权限检查由侧边栏 permission 配置 + Gate 自动处理
// 路由层只需 web + auth 中间件保证登录状态即可

use Illuminate\Support\Facades\Route;

// 外层路由组：需要登录认证
Route::group([
    'middleware' => ['web', 'auth'],
    'prefix'     => 'seat-audit',
    'namespace'  => 'Seat\SeatAuditMonitor\Http\Controllers',
], function () {

    // 违规记录查看
    Route::get('/violations', 'ViolationController@index')
        ->name('seat-audit.violations.index');

    // 手动触发审计扫描（POST 防止意外刷新重复触发）
    Route::post('/violations/scan', 'ViolationController@scan')
        ->name('seat-audit.violations.scan');

    // 导出违规记录为 CSV（支持时间区间筛选）
    Route::get('/violations/export', 'ViolationController@export')
        ->name('seat-audit.violations.export');

    // 管理操作路由组
    Route::group([], function () {

        // 监控物品管理
        Route::get('/admin/items', 'AdminController@items')
            ->name('seat-audit.admin.items');
        Route::post('/admin/items', 'AdminController@storeItem')
            ->name('seat-audit.admin.items.store');
        Route::delete('/admin/items/{id}', 'AdminController@destroyItem')
            ->name('seat-audit.admin.items.destroy');

        // 白名单管理
        Route::get('/admin/whitelist', 'AdminController@whitelist')
            ->name('seat-audit.admin.whitelist');
        Route::post('/admin/whitelist', 'AdminController@storeWhitelist')
            ->name('seat-audit.admin.whitelist.store');
        Route::delete('/admin/whitelist/{id}', 'AdminController@destroyWhitelist')
            ->name('seat-audit.admin.whitelist.destroy');
    });

    // AJAX API 路由（供前端自动补全和名称查询使用）
    Route::get('/api/characters', 'AdminController@searchCharacters')
        ->name('seat-audit.api.characters');
    Route::get('/api/item-name', 'AdminController@getItemName')
        ->name('seat-audit.api.item-name');
});
