<?php

// D:\VS Code\Project test\seat-audit-monitor\src\SeatAuditMonitorServiceProvider.php
// 插件服务提供者，负责向 Laravel/SeAT 注册所有插件资源

namespace Seat\SeatAuditMonitor;

use Seat\Services\AbstractSeatPlugin;
use Seat\SeatAuditMonitor\Console\Commands\AuditScanCommand;

class SeatAuditMonitorServiceProvider extends AbstractSeatPlugin
{
    /**
     * 插件启动时注册所有资源
     */
    public function boot()
    {
        // 注册数据库迁移目录，SeAT 将自动识别并执行迁移
        $this->loadMigrationsFrom(__DIR__ . '/database/migrations');

        // 注册路由文件
        $this->loadRoutesFrom(__DIR__ . '/Http/routes.php');

        // 注册视图命名空间，模板中通过 seat-audit-monitor::violations.index 调用
        $this->loadViewsFrom(__DIR__ . '/resources/views', 'seat-audit-monitor');

        // 注册权限配置，SeAT ACL 系统从此处读取权限列表
        $this->mergeConfigFrom(
            __DIR__ . '/Config/seat-audit-monitor.permission.php',
            'seat-audit-monitor.permission'
        );

        // 注册侧边栏菜单项，仅对具备 seat-audit-monitor.view 权限的用户可见
        // SeAT web 包通过合并 'package.sidebar' 配置键来渲染侧边栏
        $this->mergeConfigFrom(
            __DIR__ . '/Config/seat-audit-monitor.sidebar.php',
            'package.sidebar'
        );

        // 注册 Artisan 命令，用于手动触发审计扫描
        $this->commands([
            AuditScanCommand::class,
        ]);
    }

    /**
     * 服务绑定（当前无需额外绑定，使用 DB::table() 直接查询）
     */
    public function register()
    {
        //
    }

    /**
     * 返回插件包名，供 SeAT 插件管理器识别
     */
    public function getName(): string
    {
        return 'seat-audit-monitor';
    }

    /**
     * 返回插件包所在 Git 仓库地址（可留空）
     */
    public function getPackageRepositoryUrl(): string
    {
        return '';
    }

    /**
     * 返回插件版本号
     */
    public function getPackageVersion(): string
    {
        return '1.0.0';
    }
}
