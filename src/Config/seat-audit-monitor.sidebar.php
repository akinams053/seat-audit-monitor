<?php

// D:\VS Code\Project test\seat-audit-monitor\src\Config\seat-audit-monitor.sidebar.php
// 侧边栏菜单注册配置，遵循 SeAT 4.x package.sidebar 规范
// 格式：顶级菜单组 → entries 子菜单列表
// permission 字段对应 registerPermissions 注册的 scope.ability 标识

return [
    'seat-audit-monitor' => [
        // 侧边栏菜单组标题
        'name'          => '审计监控',
        // Font Awesome 图标类名（必须包含图标集前缀 fas/far/fab）
        'icon'          => 'fas fa-exclamation-triangle',
        // 路由前缀段，用于 SeAT 判断当前菜单高亮状态
        'route_segment' => 'seat-audit',
        // 整个菜单组的可见权限，无此权限的用户不会看到侧边栏入口
        'permission'    => 'seat-audit-monitor.view',
        // 子菜单条目列表
        'entries'       => [
            [
                'name'       => '违规记录',
                'icon'       => 'fas fa-list',
                'route'      => 'seat-audit.violations.index',
                'permission' => 'seat-audit-monitor.view',
            ],
            [
                'name'       => '监控物品',
                'icon'       => 'fas fa-cog',
                'route'      => 'seat-audit.admin.items',
                'permission' => 'seat-audit-monitor.admin',
            ],
            [
                'name'       => '白名单',
                'icon'       => 'fas fa-user-shield',
                'route'      => 'seat-audit.admin.whitelist',
                'permission' => 'seat-audit-monitor.admin',
            ],
        ],
    ],
];
