# 升级与回退手册

> 本文描述生产变更控制，不替代数据库备份或组织的变更审批流程。发布状态以 [CHANGELOG](../CHANGELOG.md) 为准。

## 升级前准备

1. 确认目标版本。生产默认目标是 `2.2.1`；开发分支不是生产升级路径。
2. 备份 SeAT 数据库，并记录当前 `composer.lock`、已安装插件版本和 migration 状态。
3. 确认 Web、queue worker/Horizon 和共享 Cache 的当前健康状态。
4. 在授权测试环境先执行 Composer 解析、`migrate:status` 与 `migrate --pretend`；不要把预检结果直接假定为生产结果。

```bash
cd <seat-dir>
sudo -u <web-user> php artisan migrate:status --path=vendor/akinams053/seat-audit-monitor/src/database/migrations
sudo -u <web-user> php artisan migrate --pretend --path=vendor/akinams053/seat-audit-monitor/src/database/migrations
```

## 升级到 2.2.1

```bash
cd <seat-dir>
sudo -u <web-user> composer require akinams053/seat-audit-monitor:2.2.1 --update-with-dependencies
sudo -u <web-user> php artisan migrate --path=vendor/akinams053/seat-audit-monitor/src/database/migrations
sudo -u <web-user> php artisan config:clear
sudo -u <web-user> php artisan route:clear
sudo -u <web-user> php artisan view:clear
```

即使判断“本次没有新增 migration”，也应先运行 `migrate:status` 与 `migrate --pretend`，由实际输出确认。禁止根据历史文档中的 migration 数量或固定 `--step` 推断当前实例状态。

升级后按[运行手册](operations.md#部署后验收)进行菜单、权限、队列/Cache 与受审军团配置验收。不要在未经授权的情况下用生产扫描来替代安装验证。

## 回退原则

代码回退与数据恢复是两个独立问题：

- Composer 切回旧版本只能还原 PHP/Blade/路由代码，不能自动恢复已变更的数据；
- migration 的 `down()` 不保证恢复被删除、回填或扫描写入的业务数据；
- 审计记录、水位线、cursor 和受审军团配置应依赖备份与明确恢复计划处理；
- 不要使用固定 `migrate:rollback --step=N`，也不要为了“清理”而执行未经审查的 `DELETE`。

如必须回退，先停止变更、保存错误信息和当前状态，恢复数据库备份或按已核对的插件 migration batch 制定最小回退步骤。确认数据恢复边界后，再执行：

```bash
cd <seat-dir>
sudo -u <web-user> php artisan migrate:status --path=vendor/akinams053/seat-audit-monitor/src/database/migrations
# 仅在确认实际 batch 和 down() 行为后执行受限回滚：
sudo -u <web-user> php artisan migrate:rollback --path=vendor/akinams053/seat-audit-monitor/src/database/migrations
sudo -u <web-user> composer require akinams053/seat-audit-monitor:<approved-version> --update-with-dependencies
```

最后清理缓存并重启/平滑重载 worker 的具体方式应遵循宿主 SeAT 的受控运维流程。

## 卸载

卸载属于破坏性操作：先导出或备份需要保留的审计数据，再核对 migration 状态。仅在确认业务数据处置方案后，按受限插件 path 回滚 migration，移除 Composer 包，清理缓存，并在 SeAT Access Management 中人工处理残留的 `seat-audit-monitor.*` 权限条目。

```bash
cd <seat-dir>
sudo -u <web-user> php artisan migrate:status --path=vendor/akinams053/seat-audit-monitor/src/database/migrations
# 审批后执行 migration 回滚
sudo -u <web-user> composer remove akinams053/seat-audit-monitor
sudo -u <web-user> php artisan config:clear
sudo -u <web-user> php artisan route:clear
sudo -u <web-user> php artisan view:clear
```
