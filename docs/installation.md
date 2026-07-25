# 安装指南

> **适用版本：2.2.1（生产推荐）**。开发分支的安装仅用于测试；版本边界见 [CHANGELOG](../CHANGELOG.md)。

## 1. 安装前检查

确认目标 SeAT 实例满足以下条件：

- Eve SeAT 5.x、Laravel 10.x、PHP 8.1+、MySQL/MariaDB 与 Composer；
- Web 与队列 worker 可正常运行；生产环境使用共享 Cache（建议 Redis）；
- 已备份数据库，且已记录当前 Composer 依赖状态；
- 操作者拥有维护窗口和执行 migration 的授权。

以下命令使用 `<seat-dir>` 和 `<web-user>` 占位符，不包含任何具体环境身份。

## 2. 安装正式版

```bash
cd <seat-dir>
sudo -u <web-user> composer require akinams053/seat-audit-monitor:2.2.1 --update-with-dependencies
sudo -u <web-user> php artisan migrate:status --path=vendor/akinams053/seat-audit-monitor/src/database/migrations
sudo -u <web-user> php artisan migrate --pretend --path=vendor/akinams053/seat-audit-monitor/src/database/migrations
sudo -u <web-user> php artisan migrate --path=vendor/akinams053/seat-audit-monitor/src/database/migrations
sudo -u <web-user> php artisan config:clear
sudo -u <web-user> php artisan route:clear
sudo -u <web-user> php artisan view:clear
```

生产文档使用 `--path` 限定插件 migration，避免在同一次操作中执行宿主 SeAT 或其他插件的待执行 migration。`--pretend` 仅预览 SQL；真正 migration 仍会写数据库，必须在明确授权后执行。

## 3. 安装后验证

完成安装后：

1. 登录 SeAT，确认侧边栏出现“审计监控”；
2. 在 **Settings > SeAT Module Versions** 确认插件被识别为 `2.2.1`；
3. 在 **Settings > Access Management** 确认可分配 `seat-audit-monitor.view` 和 `seat-audit-monitor.admin`；
4. 确认 `php artisan seat:audit:scan --help` 可用；
5. 确认 Web 和 queue worker 使用同一共享 Cache，再进行任何 Web 军团扫描。

不要把“菜单可见”视为全部功能已投入运行。军团审查配置由 migration 创建时默认停用，必须按[运行手册](operations.md)完成授权启用与验收。

## 4. 后续开发版本

后续未发布功能必须使用明确指定的开发分支或预发布 tag，并在 [CHANGELOG](../CHANGELOG.md) 的 `Unreleased` 区域说明。不要猜测分支名称，也不要将未经发布的 Composer 约束作为生产安装命令。执行测试安装前，必须完成测试授权、migration 预览和回退准备。

## 5. 常见缓存恢复

当升级后菜单、路由或 Blade 未刷新时，可在确认维护影响后清理缓存：

```bash
cd <seat-dir>
sudo -u <web-user> php artisan config:clear
sudo -u <web-user> php artisan route:clear
sudo -u <web-user> php artisan view:clear
sudo -u <web-user> php artisan cache:clear
```

缓存清理会改变运行状态；它不是只读检查，也不替代 queue worker 或 Horizon 的健康检查。

下一步请阅读：[运行手册](operations.md)、[用户指南](user-guide.md)、[升级与回退](upgrade-and-rollback.md)。
