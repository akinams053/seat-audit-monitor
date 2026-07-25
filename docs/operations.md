# 运行手册

> 本文面向已完成安装、具备管理员权限的 SeAT 运维人员。命令中的 `<seat-dir>`、`<web-user>` 是占位符；执行写入、扫描或外部请求前必须取得对应环境的授权。

## 受审军团启用

插件 migration 会创建固定受审军团的配置，但默认 `enabled=false`。安装完成后，授权管理员必须结合实际业务范围配置：

- `enabled`：是否允许军团审计扫描；
- `audit_donations`：是否审计 ISK Donation；
- `audit_contracts`：是否审计成员低价合同；
- `audit_from`：首次扫描允许读取的最早业务 UTC 时间。

`audit_from` 只控制首次扫描范围。cursor 创建后，常规扫描读取新增来源及有限 overlap，不能自动回填已越过 cursor 的历史漏报。改变历史范围、cursor 或审计结果须走受控补扫，不要临时修改生产表。

## 队列与共享 Cache

Web 端军团扫描和 Unknown 名称解析会投递队列任务；Web 与 queue worker/Horizon 必须使用同一共享 Cache。生产推荐 Redis，不应依赖 `array` 等进程内 Cache。

Cache 只用于显示任务进度：失效、过期或浏览器关闭不会回滚 Job、cursor 或审计写入；也不能作为扫描任务去重或数据正确性的依据。请通过宿主 SeAT 的标准方式监控 worker/Horizon、失败任务和日志。

## 扫描命令

以下命令会读取审计来源并写入审计快照/cursor，必须在明确授权后执行：

```bash
cd <seat-dir>

# 全部已启用的审计类型
sudo -u <web-user> php artisan seat:audit:scan --type=all

# 旧 1.0 审计
sudo -u <web-user> php artisan seat:audit:scan --type=wallet
sudo -u <web-user> php artisan seat:audit:scan --type=contracts

# 固定军团审计
sudo -u <web-user> php artisan seat:audit:scan --type=donations
sudo -u <web-user> php artisan seat:audit:scan --type=member-contracts
```

`--since` 仅适用于旧监控物品合同（`--type=contracts`），用于临时回扫且不推进该旧水位线：

```bash
sudo -u <web-user> php artisan seat:audit:scan --type=contracts --since="YYYY-MM-DD"
```

不要将 `--since` 误认为 Donation 或成员低价合同的历史回填工具。

## Unknown 名称解析

管理员可从页面异步提交 Unknown 名称解析，也可运行：

```bash
sudo -u <web-user> php artisan seat:audit:resolve-unknown-names
```

该命令会调用公开 ESI 接口并写入本地名称/affiliation 缓存，不读取 token。它不是只读操作，应在可观察的维护窗口中执行。解析仅增强显示名称，不应修改审计记录的历史军团快照。

## 可选定时调度

插件不自行注册宿主的定时任务。若组织需要定时扫描，应在 SeAT 宿主的计划调度中按容量、queue 吞吐和业务窗口配置，并先在测试环境验证。旧钱包和旧合同任务与军团扫描的频率应独立评估；不得把展示页筛选或 Cache 进度误当作调度机制。

## 部署后验收

在不产生未授权业务写入的前提下，按顺序验证：

1. `migrate:status --path=...` 显示预期 migration 状态；
2. 菜单、权限与三个只读页面可访问；
3. Web 和 worker 的 Cache 配置一致，队列/Horizon 健康；
4. 受审军团配置、审计开关与 `audit_from` 已由业务负责人确认；
5. 使用受控的小范围扫描或既有审计结果验证页面、CSV 与日志；
6. 记录插件版本、变更时间、验证人和回退点。

令牌审查的只读边界与数据解释见[令牌审查安全契约](token-audit-security.md)。
