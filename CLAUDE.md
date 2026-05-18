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
审计逻辑仅针对以下 SeAT 原生表进行增量扫描：
- **市场交易表**：`character_wallet_transactions`
    - 关键字段：`id`, `character_id`, `type_id`, `is_buy`, `unit_price`, `quantity`, `date`。
    - 其他字段：`transaction_id`, `location_id`, `client_id`, `is_personal`, `journal_ref_id`。
    - 判定条件：`is_buy === 0`（卖出）且 `type_id` 匹配监控名单。
- **合同主表**：`contract_details`
    - 关键字段：`contract_id` (PK), `issuer_id`, `assignee_id`, `acceptor_id`, `type`, `status`, `price`, `reward`, `date_completed`, `title`。
    - 判定条件：`status='finished'` 且 `type IN ('item_exchange','auction')` 且 issuer/assignee/acceptor 均不在白名单。
- **合同物品表**：`contract_items`
    - 关键字段：`record_id` (PK), `contract_id` (FK), `type_id`, `quantity`, `is_included`。
    - 注意：`character_contracts` 仅是 character↔contract 的关联映射，**不含合同业务字段**，不要查它取合同内容。
- **角色信息表**：`character_infos`
    - 用于获取角色名：通过 `character_id` 查询 `name` 字段。
- **SDE 物品表**：`invTypes`
    - 用于根据 `typeID` 查询 `typeName`（物品名称自动补全）。

## 3. 数据库结构 (Schema)
表前缀：`seat_audit_`
- `seat_audit_monitor_items`：监控物品列表 (id, type_id, item_name)。
- `seat_audit_whitelist`：**角色**豁免名单 (id, character_id, character_name)。钱包+合同审计均生效。
- `seat_audit_corporation_whitelist`：**军团**豁免名单 (id, corporation_id, corporation_name)。**仅合同审计生效**（钱包审计的对方是市场撮合系统，无军团概念）。
- `seat_audit_status`：记录增量水位线 (id, audit_type, last_id, **last_completed_at**)。
    - `last_id`：钱包交易审计用（按记录 id 推进）。
    - `last_completed_at`：合同审计用（按 `date_completed` 推进，DATETIME NULL）。
- **seat_audit_violations (违规记录表)**：
    - 必须存储快照信息：`id`, `character_id`, `character_name`（**发起方**角色名快照）, `type_id`, `item_name`（物品名）, `amount`（金额）, `violation_time`（违规发生时间）, `details`（JSON 原始数据），`created_at`。
    - **审计类型字段**：`audit_type` VARCHAR(50) NOT NULL DEFAULT `'wallet_transactions'`，可能值 `wallet_transactions` / `contracts`。
    - **合同 ID 字段**：`contract_id` BIGINT UNSIGNED NULL（仅 `audit_type='contracts'` 时非空，便于按合同聚合追溯）。
    - **接收方快照字段**：`counterparty_id` BIGINT UNSIGNED NULL + `counterparty_name` VARCHAR NULL。合同：`counterparty_id=acceptor_id`、`counterparty_name=acceptor 名`；钱包：`counterparty_id=NULL`、`counterparty_name='市场'`。`counterparty_id` 加独立索引，用于和 `character_id` 一起 LEFT JOIN `seat_audit_whitelist` 做查询层软过滤。
    - **合同可见性字段**：`contract_availability` VARCHAR(20) NULL。合同行写入 `contract_details.availability`（public/personal/corporation/alliance）；钱包行 NULL。UI「来源」列据此细分 badge。

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

### 4.3 排除项 (共用)
- **当前阶段不记录任何来自钱包日志 (`character_wallet_journals`) 的捐赠/直接 trade 记录**。
- 直接 trade（站内 trade 窗口）在 journal 有 entry 但无物品级明细（无 type_id），物理上无法审计——这是已知盲区。
- 合同的 courier/loan 类型不审（物品所有权未转移）。

### 4.4 白名单查询层软过滤
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
- `seat-audit-monitor.view`：查看违规记录。
- `seat-audit-monitor.admin`：管理监控物品与白名单。

### 5.4 侧边栏
- 通过 `mergeConfigFrom()` 合并到 `package.sidebar` 配置键。

### 5.5 Laravel 10.x 兼容性
- **禁止使用 `dispatch_now()`**（已在 Laravel 9 移除），改用 `Bus::dispatchSync()` 或 `dispatch(new Job())->onConnection('sync')`。
- 路由 `namespace` 参数在 Laravel 10 中仍可用但非必需，控制器可使用完整类名。

## 6. 开发约束
- **性能优先**：后台扫描器使用 `DB::table()` 直接查询。
- **UI 规范**：违规记录列表需直观显示发起方/发起方军团/接收方/接收方军团/物品/金额/来源/Contract ID/时间。
    - 双方军团通过 `character_affiliations` JOIN 实时拿（当前 affiliation 语义），UI 显示 ticker、hover 显示全名。
    - 「来源」列按 `audit_type` + `contract_availability` 细分：钱包 / 合同·公开 / 合同·私人 / 合同·军团 / 合同·联盟。
    - 通用关键词搜索框模糊匹配 character_name / counterparty_name / 双方 corp.name / 双方 corp.ticker 任一命中。
    - 合同行的 Contract ID 是可点击按钮，触发 modal 显示完整合同/物品/三方角色快照（数据源自 `details` JSON）。
- **权限**：
    - `seat-audit-monitor.view`：查看违规记录、导出 CSV。
    - `seat-audit-monitor.admin`：管理监控物品、管理白名单、手动触发扫描、ESI 解析未知名字。

## 7. SSH 调试 (Production Debug)

本仓库内置 SSH 调试通道，便于在生产服务器上验证插件行为（迁移、扫描结果、日志）。

### 7.1 工具

- 入口：`scripts/ssh-seat 'remote shell command'`（bash wrapper，跨平台）
- 实现：`scripts/ssh_seat.py`（paramiko 密码登录）
- 凭据：项目根 `.creds`（4 行：HOST / PORT / USER / PASSWORD），格式见 `.creds.example`
- `.creds` 已加入 `.gitignore`，不会进入版本控制

### 7.2 协作约定

- **目标环境是生产服务器**（`/var/www/seat`，Ubuntu 22.04 + PHP 8.4 + MariaDB + Redis）。详见 `../eve-seat-debug/server-environment.md`
- **只读优先**：`SELECT` / `tail` / `ls` / `php artisan ... --pretend` 等只读命令可直接执行；任何写操作（`INSERT`/`UPDATE`/`DELETE`/`migrate`/`config:cache`/重启服务）**必须先与用户确认**
- **客观推理，不猜测**：信息不足时直接说明还需要什么数据，不要凭印象给方案
- **每次最多 3 步操作**，逐步推进，等用户反馈后再继续
- **没有验证过的方案不直接上线**：先在 SSH 会话内 dry-run / `--pretend` / 单条 SQL 验证，再合入分支

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
当前已支持：市场交易审计 (wallet_transactions)、合同审计 (contracts)、角色白名单 OR 单方拦截 + 军团白名单 AND 双方豁免、外部角色名 ESI 批量解析（`ResolveUnknownNamesJob` / `seat:audit:resolve-unknown-names` / UI 按钮）。后续可考虑：
- **联盟白名单**：当前已支持角色 + 军团两级白名单。合同的 `assignee_id` 可能是 alliance ID，跨联盟合同仍可能绕过。可扩展为三级白名单或统一 entity_type 模型。
- **assignee 字段成列**：当前 `assignee_id` 仅在 `details` JSON 内，软过滤无法覆盖"白名单事后新增 assignee-only 角色"的边角场景。可考虑在 violations 表加 `assignee_id` 快照列。
- **钱包日志 (Donation) 审计**：直接 ISK 转账（`ref_type='player_donation'`）目前不审，可作为新审计类型加入；技术上需要新的 `AuditDonationsJob` + `audit_type='donations'` 水位线。
- **合同金额按 LP 价值核算**：当前 `amount = max(price, reward)`，零金额合同 amount=0。可引入 LP 价格表或 evepraisal 估值，把零金额合同的物品市场价合算进 amount。
- **ESI 解析定时化**：当前需手动触发。可加到 SeAT schedule 中每日自动跑。
- **监控名单复用**：`seat_audit_monitor_items` 已跨审计类型共用，无需扩展。
