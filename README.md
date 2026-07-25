# seat-audit-monitor

Eve SeAT 5.x 的经济审计与只读令牌审查插件。它提供旧版市场/合同审计、固定军团审查，以及不接触授权秘密的令牌状态投影。

> **当前正式版本：[`2.2.1`](CHANGELOG.md#221)**。它包含最后离线、军团审计九列交易视图与扩展 Unknown 实体解析等近期功能，并修正 SeAT 模块版本页的仓库链接；完整变更见 [CHANGELOG](CHANGELOG.md)。

## 支持范围

- 角色钱包市场卖出与监控物品合同审计；
- 固定军团的 ISK Donation 与成员低价合同审计；
- 违规、军团审计及 CSV 导出；
- 只读令牌状态审查。

具体业务规则、数据边界和安全约束见下方专题文档。

## 环境要求

- Eve SeAT 5.x / Laravel 10.x
- PHP 8.1+
- MySQL 或 MariaDB
- Composer
- 可工作的队列 worker（生产建议 Horizon）与共享 Cache

## 安装

以下命令在 SeAT 根目录执行。`<web-user>` 请替换为运行 SeAT 的系统用户。

```bash
cd <seat-dir>
sudo -u <web-user> composer require akinams053/seat-audit-monitor:2.2.1 --update-with-dependencies
sudo -u <web-user> php artisan migrate --path=vendor/akinams053/seat-audit-monitor/src/database/migrations
sudo -u <web-user> php artisan config:clear
sudo -u <web-user> php artisan route:clear
sudo -u <web-user> php artisan view:clear
```

安装完成后，确认 SeAT 侧边栏出现“审计监控”，并在 **Settings > SeAT Module Versions** 中确认插件版本。完整的环境前提、迁移预览和生产检查请阅读[安装指南](docs/installation.md)。

## 使用

1. 在 **Settings > Access Management** 分配权限：
   - `seat-audit-monitor.view`：查看与导出；
   - `seat-audit-monitor.admin`：管理监控物品/白名单、提交扫描及解析 Unknown 名称。
2. 管理员配置监控物品与白名单。
3. 通过“违规记录”“军团审计”“令牌审查”菜单查看结果；按需要执行扫描。
4. 启用军团审查前，必须由授权管理员完成受审军团配置和 `audit_from` 设置；队列与共享 Cache 必须正常运行。

详细页面操作、扫描命令和 CSV 规则见[用户指南](docs/user-guide.md)与[运行手册](docs/operations.md)。

## 卸载

卸载会影响插件表、配置和历史审计数据。先备份数据库，并先核对插件 migration 状态：

```bash
cd <seat-dir>
sudo -u <web-user> php artisan migrate:status --path=vendor/akinams053/seat-audit-monitor/src/database/migrations
```

随后按[升级与回退手册](docs/upgrade-and-rollback.md)评估数据库恢复方案，再执行受限 migration 回滚、Composer 移除和缓存清理。不要在共享 SeAT 数据库中使用未经核对的固定 `--step`。

## 文档导航

```text
README.md                              本页：安装与使用入口
CHANGELOG.md                           发布历史与未发布边界
docs/installation.md                   首次安装与生产前检查
docs/upgrade-and-rollback.md           升级、迁移与恢复
docs/operations.md                     运行、队列、扫描与验收
docs/user-guide.md                     权限与页面使用
docs/token-audit-security.md           令牌审查安全契约
docs/development/architecture.md       审计架构与开发约束
CLAUDE.md                              面向开发代理的简明规则
todo.md                                内部工作清单，不作为发布说明
```

## License

GPL-2.0-only
