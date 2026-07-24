<?php

// src/Http/routes.php
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

    // 固定军团 98588384 的 2.0 审计列表；页面扫描会异步入队，避免在 Web 请求内同步处理来源数据。
    Route::get('/corporation-audit', 'CorporationAuditController@index')
        ->name('seat-audit.corporation-audit.index');
    Route::post('/corporation-audit/scan', 'CorporationAuditController@scan')
        ->name('seat-audit.corporation-audit.scan');

    // 手动触发审计扫描（POST 防止意外刷新重复触发）
    Route::post('/violations/scan', 'ViolationController@scan')
        ->name('seat-audit.violations.scan');

    // 调 ESI 批量解析未知角色名（异步入队列）
    Route::post('/violations/resolve-unknown', 'ViolationController@resolveUnknown')
        ->name('seat-audit.violations.resolve-unknown');

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

        // 白名单管理（角色 + 军团共用一个页面，通过 ?tab=character|corporation 切换）
        Route::get('/admin/whitelist', 'AdminController@whitelist')
            ->name('seat-audit.admin.whitelist');
        Route::post('/admin/whitelist', 'AdminController@storeWhitelist')
            ->name('seat-audit.admin.whitelist.store');
        Route::delete('/admin/whitelist/{id}', 'AdminController@destroyWhitelist')
            ->name('seat-audit.admin.whitelist.destroy');

        // 军团白名单管理（独立的 store/destroy 路由，避免和角色白名单的资源冲突）
        Route::post('/admin/corporation-whitelist', 'AdminController@storeCorporationWhitelist')
            ->name('seat-audit.admin.corporation-whitelist.store');
        Route::delete('/admin/corporation-whitelist/{id}', 'AdminController@destroyCorporationWhitelist')
            ->name('seat-audit.admin.corporation-whitelist.destroy');
    });

    // AJAX API 路由（供前端自动补全和名称查询使用）
    Route::get('/api/characters', 'AdminController@searchCharacters')
        ->name('seat-audit.api.characters');
    // ESI 精确名字 → 角色 ID 查找（用于白名单加入非 SeAT 内角色）
    Route::get('/api/characters/esi', 'AdminController@searchCharactersEsi')
        ->name('seat-audit.api.characters.esi');
    Route::get('/api/corporations', 'AdminController@searchCorporations')
        ->name('seat-audit.api.corporations');
    Route::get('/api/item-name', 'AdminController@getItemName')
        ->name('seat-audit.api.item-name');
});
