# 令牌审查安全契约

> 适用于已发布 `2.2.0` 的令牌审查能力，发布历史见 [CHANGELOG](../CHANGELOG.md)。

## 目的与非目标

令牌审查是对 SeAT 本地记录的**只读脱敏投影**，用于辅助管理当前军团成员。它不是 token 有效性验证，也不是实时 SSO、ESI、在线状态或角色权限检查。

页面与 CSV 必须保持 GET 只读链路：

- 不写入任何数据库；
- 不派发 Job；
- 不调用 ESI 或 SSO；
- 不刷新、验证或探测 token。

## 允许的数据范围

成员范围仅来自当前 `corporation_members`。令牌状态仅允许从 `refresh_tokens` 投影以下字段：

```text
character_id
user_id
deleted_at
```

按当前角色聚合后，状态含义为：

| 状态 | 含义 |
| --- | --- |
| 状态正常 | 存在至少一条未软删除的本地记录。 |
| 账号过期 | 仅存在已软删除的本地记录。 |
| 无 SeAT 用户 | 没有可用于该成员的本地记录。 |

这些状态均不代表 token 当前可用、scope 是否充足或 SSO 是否有效。

## 绝对禁止的数据

不得读取、传递、渲染、导出或记录：

- access token、refresh token、JWT；
- scope、`expires_on`；
- SeAT 内部 user ID、group key；
- 任何用于推断或重新验证授权秘密的字段。

异常日志、调试输出、CSV、HTML 属性和测试夹具同样受此限制。

## 时间字段

以下字段语义独立，不能互相替代：

| 页面语义 | 唯一来源 | 解释 |
| --- | --- | --- |
| 已加入 | `corporation_member_trackings.start_date` | SeAT 记录的成员追踪起始时间。 |
| 最后上线 | `character_onlines.last_login` | ESI Online 数据最后报告的登录时间。 |
| 最后离线 | `corporation_member_trackings.logoff_date` | 军团成员追踪记录的登出时间。 |

它们不是实时在线状态。日期筛选在角色级组合，按 UTC 自然日解释；导出使用精确 UTC 值。

## 导出与显示

- CSV 导出的是当前角色级筛选命中的全部记录，不受页面分页限制；
- 使用流式输出、UTF-8 BOM 与 Excel/WPS 公式注入防护；
- 不输出内部用户标识、group key 或任何授权秘密；
- 主/子角色分组仅用于展示，不得基于筛选后的显示行重新推断成员范围。

## SeAT 升级后的复核

升级 SeAT 或调整相关依赖后，先在授权测试环境确认成员、令牌、在线和成员追踪表的字段/唯一键，并对最终查询执行受控 `EXPLAIN`。如该契约无法保持，应停止发布相关页面变更，而不是放宽数据最小化边界。
