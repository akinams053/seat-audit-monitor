<?php

// D:\VS Code\Project test\seat-audit-monitor\src\Config\Permissions\permissions.php
// SeAT 4.x 权限定义文件，通过 registerPermissions() 注册到 Gate 系统
// 数组键名（view / admin）即为权限 ability 标识符
// 注册时的 scope 参数为 'seat-audit-monitor'
// 最终 Gate 权限标识：seat-audit-monitor.view / seat-audit-monitor.admin

return [
    // 查看违规记录的权限
    'view'  => [
        'label'       => '查看违规记录',
        'description' => '允许查看审计监控违规交易记录列表',
    ],
    // 管理监控名单和白名单的权限
    'admin' => [
        'label'       => '管理审计配置',
        'description' => '允许管理监控物品名单和豁免白名单',
    ],
];
