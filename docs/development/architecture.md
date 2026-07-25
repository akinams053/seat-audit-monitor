# 审计架构与开发约束

> 本文是实现与评审的技术参考。正式生产行为以 [`2.2.0`](../../CHANGELOG.md#220) 为基线；后续新增能力必须先在 [CHANGELOG](../../CHANGELOG.md) 的 Unreleased 区域声明。

## 架构原则

- 展示查询只读本地 SeAT/插件表，不能在 HTTP 页面中变成扫描、ESI 调用或 token 验证；
- 后台扫描通过 `DB::table()`、批量预加载与 chunk 避免 N+1；
- 审计写入与 cursor 推进必须处于同一事务；
- 重试、overlap 与镜像来源依赖唯一来源键安全幂等；
- 快照记录表达审计发生时的事实；当前 affiliation 只能补充当前展示，不能篡改历史快照。

## 审计类型与来源

| 审计类型 | 主要来源 | 范围 |
| --- | --- | --- |
| `wallet_transactions` | `character_wallet_transactions` | 旧 1.0：角色卖出监控物品。 |
| `contracts` | `contract_details`、`contract_items` | 旧 1.0：完成的 item exchange / auction 监控物品合同。 |
| `isk_donations` | `character_wallet_journals` | 固定军团成员与外部方的 `player_donation`。 |
| `member_contracts` | `character_contracts`、`contract_details`、`contract_items` | 固定军团成员与外部方的低价完成合同。 |

名称补全可使用 `character_infos`、`character_affiliations`、`corporation_infos`、`universe_names` 与 SDE `invTypes`。`character_contracts` 仅用于发现成员相关合同和 cursor，不能替代 `contract_details` 的合同业务字段。

## 旧 1.0 审计

### 钱包交易

- 以 `seat_audit_status.last_id` 增量读取，按 500 条 chunk 处理；
- 先检查角色白名单，再判定 `is_buy === 0` 与监控 `type_id`；
- 对手方固定为市场，无角色 ID；
- 写入违规快照并在完成后推进水位线。

### 监控物品合同

- 使用 `seat_audit_status.last_completed_at`；初始基线和临时回扫由迁移/命令管理；
- 仅处理 `finished` 的 `item_exchange` / `auction`；
- 在一个合同内按 `(contract_id, type_id)` 聚合监控物品；
- 角色白名单为任一方命中即豁免；军团白名单为双方都命中才豁免；
- `--since` 是旧合同临时回扫，不能推进正式水位线。

旧违规页面的查询层软过滤是旧 1.0 专用逻辑：钱包只判断发起方角色白名单；合同判断双方角色白名单或双方军团白名单。不得复用于军团审计 2.0。

## 军团审计 2.0

军团审计仅审扫描时当前成员名册与外部方之间的事件。成员 XOR 成立才记录：成员到外部为 `outbound`，外部到成员为 `inbound`；内部和纯外部事件跳过。

- **Donation**：仅处理 `player_donation`；方向由 `first_party_id → second_party_id` 确定，正负镜像 journal 必须归并；
- **成员低价合同**：只处理完成的 `item_exchange` / `auction`；低价阈值使用数据库 decimal 投影，避免 PHP float 精度误判；每份合同最多一条审计记录，完整物品列表放入 `details.items`；
- 两类扫描各自使用 `seat_audit_scan_cursors`。`audit_from` 仅约束首次读取范围，正常扫描允许有限 overlap，但正式 cursor 不得倒退；
- 白名单只对外部方应用。Unknown 外部实体不能自动被豁免。

## 审计快照、cursor 与幂等

插件表以 `seat_audit_` 为前缀。核心表包括监控物品、角色/军团白名单、旧扫描状态、受审军团配置、军团扫描 cursor 与共用违规快照。

`seat_audit_violations` 记录角色、金额、发生时间、详情和来源。军团审计可没有顶层物品；不得伪造物品数据。军团记录使用 `source_event_key`、`source_reference`、成员/外部方、方向和双方军团快照字段。来源键是唯一索引的幂等边界：重复事件必须安全忽略，不能靠页面 Cache 或先查后写保证正确性。

## 令牌审查

令牌审查是独立的只读投影，完整安全契约见 [token-audit-security.md](../token-audit-security.md)。开发时必须遵守字段最小化：`refresh_tokens` 只能读取 `character_id`、`user_id`、`deleted_at`，禁止 token、scope、JWT、`expires_on` 与内部用户标识泄漏。

## Unknown 实体解析

解析任务先通过 `/universe/names/` 分类 Unknown ID，再仅对确认角色调用 affiliation 接口。它写本地名称/affiliation 缓存，不应改写历史军团快照。页面触发必须异步；HTTP 请求中不得直接调用 ESI。

`2.2.0` 的军团审计包含九列方向映射、成员合同物品摘要及扩展 Unknown 解析范围。页面与 CSV 必须共用相同的方向、快照和筛选语义。

## 插件实现约定

- ServiceProvider 继承 `AbstractSeatPlugin`；模型继承 `ExtensibleModel`；
- 权限 scope 为 `seat-audit-monitor`，包含 `view` 与 `admin`；
- 使用 `mergeConfigFrom()` 合并侧边栏配置；
- Laravel 10 中不可使用 `dispatch_now()`；
- 对新数据源、表字段或 SeAT 依赖升级，应先执行 schema/索引 preflight，再修改查询或扫描逻辑。
