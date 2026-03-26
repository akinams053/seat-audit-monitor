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
- **角色信息表**：`character_infos`
    - 用于获取角色名：通过 `character_id` 查询 `name` 字段。
- **SDE 物品表**：`invTypes`
    - 用于根据 `typeID` 查询 `typeName`（物品名称自动补全）。

## 3. 数据库结构 (Schema)
表前缀：`seat_audit_`
- `seat_audit_monitor_items`：监控物品列表 (id, type_id, item_name)。
- `seat_audit_whitelist`：豁免名单 (id, character_id, character_name)。
- `seat_audit_status`：记录增量水位线 (id, audit_type, last_id)。
- **seat_audit_violations (违规记录表)**：
    - 必须存储快照信息：`id`, `character_id`, `character_name`（角色名）, `type_id`, `item_name`（物品名）, `amount`（总金额 = 单价 × 数量）, `violation_time`（交易发生时间）, `details`（JSON 原始数据）, `created_at`。

## 4. 核心审计逻辑 (Audit Logic)

### 4.1 增量扫描逻辑
- **水位线**：通过 `seat_audit_status` 获取 `last_id`，仅查询 `id > last_id` 的记录。
- **批处理**：使用 `chunk(500)` 处理，完成后更新 `last_id`。

### 4.2 过滤与匹配流程
1. **白名单拦截**：**首要步骤**。如果该记录的 `character_id` 存在于 `seat_audit_whitelist` 中，则直接跳过该角色的**所有**审计逻辑。
2. **行为判定**：仅审计 `is_buy === 0`（角色卖出物品）的记录。
3. **物品匹配**：检查 `type_id` 是否在 `seat_audit_monitor_items` 名单内。只要匹配，即判定为违规。
4. **排除项**：**当前阶段不记录任何来自钱包日志 (character_wallet_journals) 的捐赠 (Donation) 记录**。

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
- **UI 规范**：违规记录列表需直观显示：角色名、物品名称、交易金额、发生时间。
- **权限**：
    - `seat-audit-monitor.view`：查看违规记录、导出 CSV。
    - `seat-audit-monitor.admin`：管理监控物品、管理白名单、手动触发扫描。

## 7. 扩展规划 (Roadmap)
当前仅审计市场交易，架构已为以下扩展预留设计：
- **合同审计**：未来可新增 `AuditContractsJob`，扫描 `character_contracts` / `character_contract_items` 表。
- **水位线复用**：`seat_audit_status.audit_type` 字段支持多审计类型（当前仅 `wallet_transactions`）。
- **违规分类**：扩展时需在 `seat_audit_violations` 表新增 `audit_type` 字段，用于区分违规来源（市场交易 / 合同等）。
- **监控名单复用**：`seat_audit_monitor_items` 可跨审计类型共用。
