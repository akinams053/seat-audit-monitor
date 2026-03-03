# CLAUDE.md - 项目开发规范：seat-audit-monitor (最终优化版)

## 0. 强制规则 (Mandatory Rules)
- **代码注释**：所有业务逻辑、数据过滤、SeAT 钩子及复杂算法，**必须包含详细的中文注释**。
- **变量命名**：类名、方法名和变量名使用英文 (PSR-12)。
- **输出规范**：代码块顶部必须标注**文件的完整物理路径**。

## 1. 项目环境 (Environment)
- **平台**：Eve SeAT 4.x 插件系统 (Laravel 8.x / 9.x)。
- **运行环境**：PHP 7.4 (严格禁止使用 PHP 8.0+ 特性)。
- **命名空间**：`Seat\SeatAuditMonitor` (映射至 `src/`)。

## 2. 核心数据源 (Data Sources)
审计逻辑仅针对以下原生表进行增量扫描：
- **市场交易表**：`character_wallet_transactions`
    - 关键字段：`id`, `character_id`, `type_id`, `is_buy`, `unit_price`, `quantity`, `date`。
    - 判定条件：`is_buy === 0` (卖出) 且 `type_id` 匹配监控名单。

## 3. 数据库结构 (Schema)
表前缀：`seat_audit_`
- `seat_audit_monitor_items`：监控物品列表 (id, type_id, item_name)。
- `seat_audit_whitelist`：豁免名单 (id, character_id, character_name)。
- `seat_audit_status`：记录增量水位线 (id, audit_type, last_id)。
- **seat_audit_violations (违规记录表)**：
    - 必须存储快照信息：`id`, `character_id`, `character_name` (角色名), `type_id`, `item_name` (物品名), `amount` (总金额 = 单价 * 数量), `violation_time` (交易发生时间), `details` (JSON 原始数据), `created_at`。

## 4. 核心审计逻辑 (Audit Logic)

### 4.1 增量扫描逻辑
- **水位线**：通过 `seat_audit_status` 获取 `last_id`，仅查询 `id > last_id` 的记录。
- **批处理**：使用 `chunk(500)` 处理，完成后更新 `last_id`。

### 4.2 过滤与匹配流程
1. **白名单拦截**：**首要步骤**。如果该记录的 `character_id` 存在于 `seat_audit_whitelist` 中，则直接跳过该角色的**所有**审计逻辑。
2. **行为判定**：仅审计 `is_buy === 0` (角色卖出物品) 的记录。
3. **物品匹配**：检查 `type_id` 是否在 `seat_audit_monitor_items` 名单内。只要匹配，即判定为违规。
4. **排除项**：**当前阶段不要记录任何来自钱包日志 (character_wallet_journals) 的捐赠 (Donation) 记录**。



## 5. 开发约束
- **性能优先**：后台扫描器使用 `DB::table()`。
- **UI 规范**：违规记录列表需直观显示：角色名、物品名称、交易金额、发生时间。
- **权限**：
    - `seat-audit-monitor.view`：查看违规记录。
    - `seat-audit-monitor.admin`：管理监控物品与白名单。

## 6. 模型规范
- 所有自定义模型必须继承 `\Seat\Services\Models\ExtensibleModel`。
- 必须提供 Migration 文件及对应的 Model 代码。