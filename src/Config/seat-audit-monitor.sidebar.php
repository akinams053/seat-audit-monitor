<?php

// D:\VS Code\Project test\seat-audit-monitor\src\Config\seat-audit-monitor.sidebar.php
// 侧边栏菜单注册配置，SeAT web 包从此配置读取菜单项
// permission 字段确保只有具备对应权限的用户才能看到此菜单入口

return [
    'seat-audit-monitor' => [
        // 侧边栏菜单组标题
        'name'          => '审计监控',
        // Font Awesome 图标类名
        'icon'          => 'fas fa-exclamation-triangle',
        // 路由名称，点击后跳转至违规记录列表
        'route'         => 'seat-audit.violations.index',
        // 必须具备此权限才能看到并访问此菜单项
        // 无 seat-audit-monitor.view 权限的用户不会在侧边栏看到任何审计监控入口
        'permission'    => 'seat-audit-monitor.view',
    ],
];
