# CLAUDE.md - 项目开发规范：seat-audit-monitor

## 0. 强制规则 (Mandatory Rules)
- **代码注释**：所有业务逻辑、数据过滤、SeAT 钩子及复杂算法，**必须包含详细的中文注释**。
- **变量命名**：类名、方法名和变量名使用英文 (PSR-12)。
- **输出规范**：代码块顶部必须标注**文件的完整物理路径**（使用 Linux 路径格式）。

## 1. 项目环境 (Environment)
- **平台**：Eve SeAT 5.x 插件系统 (Laravel 10.x)。
- **运行环境**：PHP 8.1+（服务器实际为 PHP 8.4，可使用 PHP 8.x 特性：类型声明、match 表达式、命名参数、readonly 属性、枚举等）。
- **操作系统**：Ubuntu 22.04 LTS。
- **数据库**：MariaDB（MySQL 兼容）。
- **命名空间**：`Seat\SeatAuditMonitor`（映射至 `src/`）。
- **依赖**：`eveseat/services: ^5.0`。

## 2. 核心数据源 (Data Sources)
审计和只读展示仅使用以下 SeAT 原生表；后台扫描器必须按策略使用增量 cursor，不能把展示页查询改造成扫描或 ESI 调用。

- **旧 1.0 市场交易**：`character_wallet_transactions`
    - 关键字段：`id`, `character_id`, `type_id`, `is_buy`, `unit_price`, `quantity`, `date`。
    - 仅判定 `is_buy === 0`（卖出）且 `type_id` 命中监控名单。
- **旧 1.0 监控物品合同**：`contract_details` + `contract_items`
    - `contract_details` 取合同业务字段；`contract_items` 取物品明细。仅审 `finished` 的 `item_exchange` / `auction`。
    - `character_contracts` 在旧 1.0 中不含合同业务字段，不能用它读取合同内容。
- **军团审查 2.0 ISK Donation**：`character_wallet_journals`
    - 仅处理 `ref_type='player_donation'`；以 `first_party_id → second_party_id` 固定捐赠方向，镜像 journal 必须归并。
- **军团审查 2.0 成员低价合同**：`character_contracts` + `contract_details` + `contract_items`
    - `character_contracts` 只用于发现成员关联合同及其 `updated_at` cursor；合同业务字段仍必须来自 `contract_details`，完整物品快照来自 `contract_items`。
- **当前成员范围**：`corporation_members`
    - 固定受审军团 `98588384` 的扫描时当前成员名册是成员 XOR 的唯一依据；不推断历史入团/退团。
- **名称、军团和物品补全**：`character_infos`、`character_affiliations`、`corporation_infos`、`universe_names` 与 SDE `invTypes`。
- **令牌审查只读投影**：`corporation_members`、`refresh_tokens`、`users`、`character_infos`、`universe_names`、`corporation_member_trackings`、`character_onlines`。
    - `refresh_tokens` 仅允许选择 `character_id`、`user_id`、`deleted_at`；禁止读取 token、refresh token、scope、JWT 或 `expires_on`。

## 3. 数据库结构 (Schema)
表前缀：`seat_audit_`
- `seat_audit_monitor_items`：旧 1.0 共用监控物品列表 (`id`, `type_id`, `item_name`)。
- `seat_audit_whitelist`：角色豁免名单；旧钱包/合同采用既有语义，军团审查 2.0 仅对**外部方**应用。
- `seat_audit_corporation_whitelist`：军团豁免名单；旧合同的双方军团 AND 豁免语义不变，军团审查 2.0 同样只对外部方应用。
- `seat_audit_status`：旧 1.0 水位线。钱包按 `last_id`，监控物品合同按 `last_completed_at` 推进。
- `seat_audit_corporations`：受审军团配置；`corporation_id` 唯一，`enabled` 控制扫描，`audit_donations` / `audit_contracts` 可独立开关，`audit_from` 是首次扫描允许读取的最早业务时间。当前固定配置为 `98588384`。
- `seat_audit_scan_cursors`：军团审查 2.0 的独立 cursor；以 `(scanner, audit_corporation_id)` 唯一，保存时间、主/次 ID cursor 与开始/成功时间。正常扫描允许十分钟 overlap，但不得倒退正式 cursor。
- **`seat_audit_violations`（违规快照表）**：
    - 共用快照包含 `character_id`、`character_name`、`amount`、`violation_time`、`details`、`created_at`；`type_id` 与 `item_name` 对 ISK Donation、整份成员合同允许为 NULL，不得伪造物品数据。
    - `audit_type` 的持久化值为 `wallet_transactions`、`contracts`、`isk_donations`、`member_contracts`。
    - 旧合同继续使用 `contract_id`、`counterparty_id`、`counterparty_name`、`contract_availability` 等字段，且白名单查询层软过滤保持既有语义。
    - 军团审查 2.0 使用 `source_event_key`（64 位 SHA-256，唯一索引幂等）、`source_reference`、`audit_corporation_id`、`member_character_id`、`external_party_id`、`external_party_type`、`direction` 以及双方军团 ID 快照字段。
    - 写入和 cursor 推进必须在同一事务内完成；重试、镜像事件或 overlap 命中重复 `source_event_key` 时必须被安全忽略。

## 4. 核心审计逻辑 (Audit Logic)

### 4.1 市场交易审计 (wallet_transactions)
- **水位线**：通过 `seat_audit_status` 获取 `last_id`，仅查询 `id > last_id` 的记录。
- **批处理**：使用 `chunk(500)` 处理，完成后更新 `last_id`。
- **接收方快照**：`counterparty_id=NULL`、`counterparty_name='市场'`（钱包交易的对手方是市场撮合系统，无 character ID）。

过滤流程：
1. **白名单拦截**：**首要步骤**。如果该记录的 `character_id` 存在于 `seat_audit_whitelist` 中，则直接跳过该角色的**所有**审计逻辑。
2. **行为判定**：仅审计 `is_buy === 0`（角色卖出物品）的记录。
3. **物品匹配**：检查 `type_id` 是否在 `seat_audit_monitor_items` 名单内。只要匹配，即判定为违规。

### 4.2 合同审计 (contracts)
- **水位线**：通过 `seat_audit_status` 获取 `last_completed_at`，仅查询 `date_completed > last_completed_at` 的合同。
- **初始基线**：migration 预置为 `2026-01-01 00:00:00`（2026 年前合同体量大但业务价值低，作为历史数据完全跳过）。代码兜底为 `1970-01-01`，但常规部署不会落入兜底分支。
- **临时回扫**：`AuditContractsJob` 构造函数接受可选 `?Carbon $sinceOverride`；通过 `AuditScanCommand --since=YYYY-MM-DD` 注入。使用 `sinceOverride` 时**不推进水位线**，避免破坏正常增量轨迹。
- **批处理**：`contract_details` 用 `chunk(500)`，在 chunk 内一次性 JOIN `contract_items` 拉本批所有命中监控 type_id 的物品行。
- **索引**：插件 migration 在 `contract_details(status, type, date_completed)` 上加复合索引 `idx_audit_contract_scan`，让 WHERE 从全表 scan 变为 range scan。

过滤流程：
1. **状态/类型筛选**：仅审 `status='finished'` 且 `type IN ('item_exchange','auction')`（跳过 courier/loan/unknown，避免物权未转移的误报）。
2. **白名单组合豁免**（两套白名单语义不同）：
    - **角色白名单（OR 单方拦截）**：issuer 或 acceptor **任一**在 `seat_audit_whitelist.character_id` → 跳过。
    - **军团白名单（AND 双方豁免）**：issuer 军团 AND acceptor 军团 **都**在 `seat_audit_corporation_whitelist` → 跳过（豁免内部成员之间的合同）。
    - **综合条件**：上述任一条件成立即跳过。
    - 发起方军团直接取 `contract.issuer_corporation_id`；接收方军团从 `character_affiliations` 按本批 `acceptor_id` 预加载（避免 N+1）。
    - assignee 不参与判定：私下合同 assignee==acceptor 已被覆盖；公开/corp/alliance 合同 assignee 不是 character。
3. **物品匹配**：合同 items 中任一 `type_id` 命中监控名单即记违规。
4. **违规粒度**：一个 `(contract_id, type_id)` 一条 violation。若同一合同含多种监控物品，落多条；同 type_id 多 record（如 is_singleton 装配舰船）则聚合 quantity 到 `details.item.quantity`。
5. **快照字段映射**：
    - `character_id` = `contract.issuer_id`（issuer = **发起方**主要相关方；assignee/acceptor 完整保存在 `details.parties`）
    - `counterparty_id` = `contract.acceptor_id`、`counterparty_name` = acceptor 角色名（finished 合同 acceptor 必为 character）
    - `contract_availability` = `contract.availability`（public/personal/corporation/alliance；UI 来源 badge 据此细分）
    - `amount` = `max(price, reward)`（item_exchange 卖出取 price，求购取 reward；零金额合同保留 amount=0，UI 标灰区分）
    - `violation_time` = `contract.date_completed`
    - `contract_id` = `contract.contract_id`
    - `details.contract` 含 contract_id/type/status/issuer/assignee/acceptor/price/reward/date_*/title
    - `details.item` 含 type_id/quantity/is_included/record_id/record_ids
    - `details.parties` 含 issuer_name/assignee_name/acceptor_name

### 4.3 军团审查 2.0（固定军团）
- **范围**：仅审 `corporation_members(corporation_id=98588384)` 在扫描执行时的当前成员与外部方之间的事件。成员 XOR 成立才记录；成员→外部为 `outbound`，外部→成员为 `inbound`，内部互转和纯外部事件均跳过。
- **ISK Donation**：仅审 `character_wallet_journals.ref_type='player_donation'`；以 `first_party_id → second_party_id` 固定方向，正负镜像归并为单一事件。角色/军团白名单只检查外部方，Unknown 外部实体不能自动豁免。
- **成员低价合同**：仅审 `finished` 的 `item_exchange` / `auction`，且 `price < 5,000,000.00`；`reward` 不参与阈值，金额固定写入 `price`，每份合同最多一条 violation，并保留完整 `contract_items` 快照。低价判定必须使用 MariaDB 的 `DECIMAL(20, 2)` 投影，避免 PDO float 精度误判。
- **进度与补扫**：Donation / 成员合同分别使用 `seat_audit_scan_cursors`，`audit_from` 仅约束首次扫描的最早业务时间；cursor 建立后只读取新来源与十分钟 overlap。若修复发现历史 cursor 已越过的漏报，必须采用受控补扫，不能期待常规增量扫描自动回填。

### 4.4 排除项 (共用)
- 直接 trade（站内 trade 窗口）即使在 journal 有 entry，也没有物品级 `type_id` 明细；旧物品审计无法物理判定，仍是已知盲区。
- 旧 1.0 钱包/监控物品合同审计不记录 Donation；但固定军团 2.0 已审计 `player_donation`，不得把两种策略混为一谈。
- 合同的 `courier` / `loan` 类型不审，因为物品所有权未转移。


### 4.5 旧违规记录白名单查询层软过滤
- **生效位置**：仅在 `ViolationController::index` / `::export` 查询时（即 UI 列表和 CSV 导出），不影响 Job 的扫描入库逻辑。
- **豁免语义**（两套白名单不同）：
    - 钱包行：发起方 `character_id` 在角色白名单 → 豁免（军团白名单不参与）。
    - 合同行：(issuer OR acceptor) 任一在角色白名单 → 豁免；或 (issuer 军团 AND acceptor 军团) 都在军团白名单 → 豁免。综合任一成立即豁免。
- **JOIN 结构**：违规表分别 LEFT JOIN 角色白名单两次（发起方/接收方 character_id）+ `character_affiliations` 两次（ON 子句 `AND audit_type='contracts'` 让钱包行不命中）+ `seat_audit_corporation_whitelist` 两次。WHERE 子句按 audit_type 分支：
    - 钱包行：`wl_chr.id IS NULL`
    - 合同行：`wl_chr.id IS NULL AND wl_ctp.id IS NULL AND (corp_wl_chr.id IS NULL OR corp_wl_ctp.id IS NULL)`
- **设计取舍**：白名单更新即时生效、可逆、DB 数据不动。军团使用「当前 affiliation」语义（角色当前所属军团），不是合同发生时的历史 affiliation。
- **业务直觉**：
    - 角色白名单 = 个人豁免（高度信任的角色，无论交易对方是谁都不审）
    - 军团白名单 = 内部互转豁免（把内部主公司加进 → 仅内部成员之间的合同豁免，与外部的交易仍审）
- **已知盲区**：`assignee_id` 不单独成列。新语义下 Job 和软过滤均不查 assignee。属设计取舍非盲区。

## 5. 插件开发规范 (SeAT 5.x Plugin)

### 5.1 ServiceProvider
- 继承 `\Seat\Services\AbstractSeatPlugin`。
- 必须实现的抽象方法：`getName()`, `getPackageRepositoryUrl()`, `getPackagistPackageName()`, `getPackagistVendorName()`。
- **不要**实现 `getPackageVersion()` — SeAT 5.x 通过 `Composer\InstalledVersions` 自动检测版本。

### 5.2 模型
- 所有自定义模型继承 `\Seat\Services\Models\ExtensibleModel`（SeAT 官方推荐，支持可注入关系）。
- 必须提供 Migration 文件及对应的 Model 代码。

### 5.3 权限
- 通过 `registerPermissions()` 注册，scope 为 `seat-audit-monitor`。
- `seat-audit-monitor.view`：查看旧违规记录、军团审查、令牌审查，并导出三类页面的 CSV。
- `seat-audit-monitor.admin`：管理监控物品与白名单、提交旧 1.0 或军团审查扫描、解析未知来源；令牌审查仍不因 admin 权限而读取或探测授权秘密。

### 5.4 侧边栏
- 通过 `mergeConfigFrom()` 合并到 `package.sidebar` 配置键。

### 5.5 Laravel 10.x 兼容性
- **禁止使用 `dispatch_now()`**（已在 Laravel 9 移除），改用 `Bus::dispatchSync()` 或 `dispatch(new Job())->onConnection('sync')`。
- 路由 `namespace` 参数在 Laravel 10 中仍可用但非必需，控制器可使用完整类名。

## 6. 开发约束
- **性能优先**：后台扫描器使用 `DB::table()` 直接查询，批量预加载并避免 N+1；军团审查的写入与 cursor 推进必须处于同一事务。
- **旧违规记录 UI**：需显示发起方/双方当前军团/接收方/物品/金额/来源/合同详情/时间；双方军团以 `character_affiliations` JOIN `corporation_infos` 为主、`universe_names` 为兜底，保持当前 affiliation 语义。来源按 `audit_type` 与 `contract_availability` 细分，合同 ID 可打开 `details` JSON 快照 modal。
- **军团审查 UI**：Donation 与成员低价合同必须分 tab 展示和导出；admin 可异步提交当前标签扫描，页面仅以短 TTL 共享 Cache 显示本次任务状态，不能依赖 Cache 保证 Job 去重。日期筛选仅过滤已入库结果，不能修改 `audit_from` 或 cursor。
- **令牌审查 UI 与安全边界**：
    - 页面和 CSV 只能是 GET 只读链路：不写库、不派发 Job、不调用 ESI/SSO、不刷新或验证 token。
    - 绝不读取、渲染、导出或记录 token、refresh token、scope、JWT、`expires_on`、SeAT 内部 user ID 或 group key；`refresh_tokens` 仅可作三态存在性投影。
    - 三种时间字段不可互换：已加入=`corporation_member_trackings.start_date`，最后上线=`character_onlines.last_login`，最后离线=`corporation_member_trackings.logoff_date`。三者不是实时在线状态或 token 有效性结论。
    - 状态、关键词和三种时间均在角色级 AND 筛选；30/60 天按 UTC 自然日，60 天内必须包含 30 天内。随后按 SeAT 用户主角色分组和分页；主角色是否属于军团范围必须按完整成员名册判断，不能使用筛选后的显示行。
    - CSV 导出当前角色级筛选命中的全部记录而非当前分页；必须使用流式输出、UTF-8 BOM 和 Excel/WPS 公式注入防护。

## 7. SSH 调试（测试 / 生产环境）

本仓库可以连接测试服务器和生产服务器，用于验证插件行为（迁移、扫描结果、日志）。两个环境必须严格区分，禁止根据默认凭据或历史上下文自行推断连接目标。

### 7.1 环境与连接方式

- **测试服务器**：
    - 地址：`192.168.71.35`
    - SSH 用户：`ubuntu`
    - SSH 私钥：项目内 `scripts/all.key`
    - 远端主机名：`UbuntuCloud2204`
    - SeAT 目录：`/var/www/seat`
    - 连接方式：`ssh -i ./scripts/all.key ubuntu@192.168.71.35`
- **生产服务器**：
    - 地址：`129.226.211.33`
    - 站点域名：`https://yeluo-xinghai.icu`
    - SeAT 目录：`/var/www/seat`
    - 入口：`scripts/ssh-seat 'remote shell command'`（bash wrapper，跨平台）
    - 实现：`scripts/ssh_seat.py`（paramiko 密码登录）
    - 凭据：项目根 `.creds`（4 行：HOST / PORT / USER / PASSWORD），格式见 `.creds.example`
    - `.creds` 已加入 `.gitignore`，不会进入版本控制

### 7.2 协作约定

- **每次连接 SSH 前必须先询问用户，并等待用户明确确认本次连接的是测试服务器还是生产服务器；即使只执行只读命令也不能跳过确认。**
- `.creds` 只用于生产服务器；`scripts/all.key` 只用于测试服务器，禁止混用。
- 生产环境为 Ubuntu 22.04 + PHP 8.4 + MariaDB + Redis，详细资料见 `../eve-seat-debug/server-environment.md`。
- **只读优先**：`SELECT` / `tail` / `ls` / `php artisan ... --pretend` 等只读命令优先；任何写操作（`INSERT`/`UPDATE`/`DELETE`/`migrate`/`config:cache`/重启服务）**必须另行与用户确认**。
- **客观推理，不猜测**：信息不足时直接说明还需要什么数据，不要凭印象给方案。
- **每次最多 3 步操作**，逐步推进，等用户反馈后再继续。
- **没有验证过的方案不直接上线**：先在测试服务器或 SSH 会话内 dry-run / `--pretend` / 单条 SQL 验证，再合入分支。

### 7.3 常用诊断片段

```bash
# 插件版本与水位线
scripts/ssh-seat 'cd /var/www/seat && sudo -u www-data php artisan --version'
scripts/ssh-seat 'sudo mysql seat -e "SELECT * FROM seat_audit_status;"'

# 违规记录概况
scripts/ssh-seat 'sudo mysql seat -e "SELECT COUNT(*) AS total, MAX(violation_time) AS latest FROM seat_audit_violations;"'

# 手动触发扫描（写操作，先与用户确认）
scripts/ssh-seat 'cd /var/www/seat && sudo -u www-data php artisan seat:audit:scan'

# Horizon / 日志
scripts/ssh-seat 'sudo tail -n 200 /var/www/seat/storage/logs/laravel.log'
```

## 8. 扩展规划 (Roadmap)
当前已支持：旧 1.0 市场交易 (`wallet_transactions`) 与监控物品合同 (`contracts`) 审计；固定军团 2.0 的 ISK Donation (`isk_donations`) 与成员低价合同 (`member_contracts`) 审计；角色/军团白名单；外部角色和当前军团 ESI 批量解析；令牌审查的只读三态、分组、入团/最后上线/最后离线时间与 CSV。

后续可考虑：
- **联盟白名单**：合同的 `assignee_id` 可能是 alliance ID；可扩展为三级白名单或统一 entity_type 模型。
- **assignee 字段成列**：`assignee_id` 目前只在 `details` JSON 内，查询层软过滤不能覆盖事后新增的 assignee-only 角色。
- **令牌审查自动化验证**：补齐三态、主/子角色、三种 UTC 时间边界、组合筛选、分页与 CSV 公式注入回归，并在 SeAT 升级后复核最终 JOIN 的 `EXPLAIN`。
- **令牌审查扩展能力**：技能列表、显式 scope 检查、手动/定时 token 有效性验证、最后地点、详情 modal、多军团配置与历史成员资格；每项必须先单独评审授权安全边界。
- **合同金额按 LP 价值核算**：为零金额或低金额合同引入可审计的物品估值来源。
- **ESI 解析定时化与军团 ticker 缓存**：外部军团目前仅有 `universe_names` 名称，若新增 ticker 缓存须控制 ESI 请求速率。
