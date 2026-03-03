<?php

// D:\VS Code\Project test\seat-audit-monitor\src\Http\routes.php
// 插件路由定义，按权限分层隔离

use Illuminate\Support\Facades\Route;

// 外层路由组：需要登录且具有 view 权限
Route::group([
    'middleware' => ['web', 'auth', 'can:seat-audit-monitor.view'],
    'prefix'     => 'seat-audit',
    'namespace'  => 'Seat\SeatAuditMonitor\Http\Controllers',
], function () {

    // 违规记录查看（view 权限即可）
    Route::get('/violations', 'ViolationController@index')
        ->name('seat-audit.violations.index');

    // 管理操作路由组：需要额外的 admin 权限
    Route::group(['middleware' => 'can:seat-audit-monitor.admin'], function () {

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
});
