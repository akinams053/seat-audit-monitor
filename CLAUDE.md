# CLAUDE.md - seat-audit-monitor 开发约束

详细架构见 [docs/development/architecture.md](docs/development/architecture.md)，安装与运行见 [docs/installation.md](docs/installation.md) 和 [docs/operations.md](docs/operations.md)。

## 代码与兼容性

- 所有业务逻辑、数据过滤、SeAT 钩子和复杂算法必须有详细中文注释；类、方法、变量遵循英文 PSR-12 命名。
- 代码块顶部必须标注完整物理路径，使用 Linux 路径格式。
- 平台为 Eve SeAT 5.x / Laravel 10.x；PHP `^8.1`；命名空间为 `Seat\SeatAuditMonitor`，映射 `src/`；依赖 `eveseat/services:^5.0`。
- ServiceProvider 继承 `\Seat\Services\AbstractSeatPlugin`，实现 SeAT 所需元数据方法；不要实现 `getPackageVersion()`。
- 自定义模型继承 `\Seat\Services\Models\ExtensibleModel`，并提供对应 migration。
- 使用 `registerPermissions()` 注册 `seat-audit-monitor` scope；通过 `mergeConfigFrom()` 合并 `package.sidebar`。
- Laravel 10 中禁止 `dispatch_now()`；使用 `Bus::dispatchSync()` 或显式同步连接。

## 数据、安全与性能

- 展示页保持只读；后台扫描按独立 cursor 增量处理。扫描写入与 cursor 推进必须处于同一事务，并避免 N+1。
- 令牌审查页面与 CSV 只能是 GET 只读链路：不得写库、派发 Job、调用 ESI/SSO、刷新或验证 token。
- 禁止读取、渲染、导出或记录 token、refresh token、scope、JWT、`expires_on`、SeAT 内部 user ID 或 group key。`refresh_tokens` 仅可投影 `character_id`、`user_id`、`deleted_at`。
- 令牌审查的入团、最后上线、最后离线是独立时间语义，不能互相替代；CSV 必须流式输出、使用 UTF-8 BOM 并防护公式注入。
- 旧 1.0 与军团审计 2.0 的白名单、快照与筛选语义不同；修改前必须以架构文档和现有实现为准，不得混用。

## 远程环境与发布

- 连接任何测试或生产环境前，必须先取得用户对**本次目标环境**的明确确认；不得猜测目标、凭据或连接方式。
- 外部环境操作只读优先。`migrate`、扫描、配置/缓存写入、服务重启和数据库写入均须另行确认；每次最多执行 3 个步骤并等待结果。
- 不在版本控制文档、代码、日志或提交中复制真实主机、域名、用户名、凭据、私钥路径、数据库连接串或业务测试数据；连接详情仅保存于受控私有 Runbook。
- 未验证的方案不能直接上线。先在授权测试环境执行 `--pretend`、`--help`、只读查询或受控验证，再准备生产变更。
- 正式生产基线为 `2.2.0`；发布状态与后续未发布能力以 [CHANGELOG.md](CHANGELOG.md) 为准。
