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
    // 当前军团审计标签和日期范围的流式 CSV 导出；Controller 内继续校验 view 权限。
    Route::get('/corporation-audit/export', 'CorporationAuditController@export')
        ->name('seat-audit.corporation-audit.export');
    // 浏览器轮询一次性 Cache 进度，不读取 Job payload、日志或敏感来源数据。
    Route::get('/corporation-audit/scan-status/{token}', 'CorporationAuditController@scanStatus')
        ->name('seat-audit.corporation-audit.scan-status');
    Route::post('/corporation-audit/scan', 'CorporationAuditController@scan')
        ->name('seat-audit.corporation-audit.scan');
    // 仅管理员可异步提交 Unknown 实体/军团名称解析；Controller 内继续校验 admin 权限。
    Route::post('/corporation-audit/resolve-unknown', 'CorporationAuditController@resolveUnknown')
        ->name('seat-audit.corporation-audit.resolve-unknown');

    // 固定军团令牌状态审查：纯 GET 只读页面，没有扫描、刷新 token 或 ESI 调用入口。
    Route::get('/token-audit', 'SeatTokenAuditController@index')
        ->name('seat-audit.token-audit.index');
    // 导出沿用令牌审查的角色级筛选，但不继承页面账号组分页。
    Route::get('/token-audit/export', 'SeatTokenAuditController@export')
        ->name('seat-audit.token-audit.export');

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
