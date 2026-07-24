# 军团审计插件：开发状态与 2.1 计划

> 当前范围：在保持 1.0 市场交易/监控物品合同审计，以及 2.0 Donation/成员低价合同审计语义不变的前提下，为固定 EVE corporation `98588384` 增加 SeAT 令牌状态审查。
>
> 2.1 明确不做：令牌刷新或有效性探测、ESI scope 检查、ESI/SSO 调用、技能数据、最后地点、详情 modal、多军团支持、历史成员资格推断。

## 已完成：军团审查 2.0

- [x] 固定军团 `98588384` 的成员范围、独立 cursor 与幂等来源键基础设施。
- [x] ISK Donation 审计：镜像 journal 归并、成员 XOR、外部方白名单与独立 cursor。
- [x] 成员低价合同审计：完成合同、成员 XOR、外部方白名单、十分钟 overlap 与每合同一条记录。
- [x] 修复 `contract_details.price` 被 PDO 映射为 PHP float 导致成员低价合同跳过的问题：SQL `DECIMAL(20, 2)` 投影仅用于规则/金额，原始合同快照不混入内部投影。
- [x] 军团审计独立页面、Donation/成员低价合同标签、受权限与 CSRF 保护的异步扫描入口。
- [x] 测试服务器真实合同 `#234305678` 已成功写入 `member_contracts` violation；Horizon 已重启加载修复。
- [x] 2.0 代码与文档已提交并推送；用户已决定不将 Donation 真实对账、旧 1.0 回归、历史漏报统计/补扫作为本阶段阻塞项。

## 已完成：令牌审查 2.1

### 已确定的业务口径

- [x] **成员范围**：始终读取 `corporation_members.corporation_id=98588384` 的当前游戏内成员，不以 SeAT 绑定、token 或 affiliation 推断成员资格。
- [x] **令牌状态**：只读 `refresh_tokens` 聚合；任意 `deleted_at IS NULL` 为「状态正常」、仅存在已删除 token 为「账号过期」、无 token 行为「无 SeAT 用户」。不得读取 token 内容、scope 或其他授权秘密。
- [x] **页面入口**：新增独立「令牌审查」页面，使用已有 `seat-audit-monitor.view` 权限；没有扫描、刷新、POST、队列或 ESI 请求。
- [x] **主/子角色**：按 SeAT 用户绑定分组并使用其明确的主角色关系；未绑定 SeAT 的成员各自独立显示。状态标签只显示命中状态的角色行，但保留主角色分组标题。
- [x] **展示字段**：令牌状态、角色名与头像、军团游戏内头衔、技能列表占位、入团时间、最后上线；不显示最后地点。
- [x] **角色头衔展示口径**：SeAT 源码已确认角色概览的「头衔」直接读取 `CharacterInfo::$title`，对应 `character_infos.title`；缺失时显示「无头衔」。不得以角色名人工映射，也不得把 `corporation_member_titles` / `corporation_titles` 的权限角色或其他职位字段冒充此列。
- [x] **技能列表**：保留列，每行显示「暂未接入」，本阶段不读取技能。
- [x] **时间规则**：UTC 相对时间 + 精确 UTC 提示；入团时间来自 `corporation_member_trackings.start_date`，最后上线唯一来自 `character_onlines.last_login`。两者均可筛选全部 / 30 天内 / 60 天内，边界按 UTC 自然日闭区间处理；不展示瞬时 `online` 状态。
- [x] **CSV 导出**：导出当前角色级筛选命中的全部成员行，不受账号组分页影响；包含主角色归属、角色、头衔、令牌状态与精确 UTC 时间。禁止输出内部 SeAT user ID、group key、token/refresh token/scope/JWT/`expires_on`，并对全部 CSV 单元格实施 Excel/WPS 公式注入防护。

### 阶段 1：只读 schema preflight（阻塞实现）

- [x] 已在测试服务器只读确认 `corporation_members`、`refresh_tokens`、`character_infos`、`universe_names`、`corporation_member_trackings`、`character_onlines` 的实际字段及成员/token/tracking/onlines 索引。
- [x] 已通过 `refresh_tokens.user_id → users.id → users.main_character_id` 确认「成员角色 → SeAT 用户 → 主角色」的数据关系；当前 token 关联成员未发现缺失用户或主角色。
- [x] 已从 `/characters/{id}/sheet` 的 Controller、概览 View 与 `CharacterInfo` Model 源码确认：概览「头衔」为 `$character->title`，对应 `character_infos.title`；`$character->titles` 才是权限头衔列表，不能用于本列。
- [x] 已确认 `character_onlines.character_id` 为主键，测试角色 `2118151113` 的 `last_login` 为当日上线时间；旧 `corporation_member_trackings.logoff_date` 只是追踪登出时间，不能作为页面最后上线来源。
- [ ] 非阻塞后续项：仅针对含 `character_onlines` JOIN 的固定查询在测试服务器执行一次受控 `EXPLAIN`，确认所有连接继续使用既有索引。

### 阶段 2：只读查询、分组与页面

- [x] 已新增脱敏读取服务：仅 `SELECT` 显式字段，限制 `refresh_tokens` 仅读取 `character_id`、`user_id` 与 `deleted_at`；未创建 SeAT token Eloquent Model，也不输出原始行。
- [x] 已新增 UTC 时间值对象和分组服务：处理三态、主/子角色、独立未绑定成员、状态/关键词/最后上线/入团时间 30-60 天筛选、确定性排序与按账号组分页。
- [x] 已新增只读 `SeatTokenAuditController`、GET 路由和侧边栏「令牌审查」入口；未修改既有 1.0/2.0 页面和扫描逻辑。
- [x] 已将最后上线投影由错误的 `corporation_member_trackings.logoff_date` 更正为 `character_onlines.last_login`；不读取或展示 `online` / `logins`。
- [x] 已实现紧凑独立表格：四个状态标签、搜索、最后上线/入团时间筛选、每页组数、分页、低高度主角色分组、28 px 头像、纯图标状态、角色概览头衔、技能占位、入团/最后上线时间。

### 已完成：部署与文档

- [x] 已在 README 固化 schema contract、三态规则、只读边界、UTC 规则、入团/最后上线来源、CSV 导出安全边界、技能占位、实时查询性能决策和 SeAT 升级后的 preflight 要求。
- [x] 已在测试服务器以 PHP 8.4 对 2.1 的 Controller、路由及 CSV 导出改动执行临时 `php -l`，临时文件均已删除。
- [x] 已通过 Composer 将测试服务器从 `7b1c628` 更新至包含 CSV 导出的分支版本，并执行 `config:clear`、`route:clear`、`view:clear`；2.1 不执行 migration。

### 非阻塞后续验证

- [ ] 如需自动化回归，覆盖三态、主/子角色分组、主角色不在当前军团、未绑定独立组、30/60 天临界、空/无效/未来时间、筛选、组分页与 CSV 公式注入。
- [ ] 如需请求级证据，验证页面/导出不产生数据库写入、不派发 Job、不调用 ESI/SSO，且不会在 HTML、日志或异常中泄露 token/scope。

## 后续阶段（不属于 2.1）

- [ ] 技能列表数据与展示。
- [ ] 令牌 scope 检查、手动/定时令牌有效性验证。
- [ ] 最后地点、详情 modal。
- [ ] 多军团配置与历史成员资格。
