# seat-audit-monitor

Eve SeAT 4.x 插件 · 角色钱包交易增量审计监控

---

## 功能概述

`seat-audit-monitor` 是一个基于 Eve SeAT 4.x 插件系统的合规审计工具。它通过定期或手动扫描角色钱包交易记录，检测并记录角色卖出受监控物品的行为，帮助联盟管理人员进行经济合规审查。

### 核心功能

- **增量扫描**：基于水位线机制，每次仅扫描新产生的交易记录，无性能损耗
- **白名单豁免**：支持将指定角色加入白名单，其交易记录将被完全跳过
- **违规检测**：自动匹配卖出交易与监控物品名单，命中即记录违规
- **快照存储**：违规记录保存角色名和物品名快照，历史数据不受原始数据变更影响
- **可视化管理**：提供 Web 界面管理监控物品、白名单，查看违规记录列表
- **权限隔离**：查看权限与管理权限分离，支持细粒度访问控制

---

## 目录结构

```
seat-audit-monitor/
├── composer.json                          # 插件包配置、PSR-4 autoload、SeAT 注册
├── CLAUDE.md                              # 项目开发规范
└── src/
    ├── SeatAuditMonitorServiceProvider.php  # 服务提供者，插件注册总入口
    │
    ├── Config/
    │   └── seat-audit-monitor.permission.php  # 权限定义配置
    │
    ├── Console/
    │   └── Commands/
    │       └── AuditScanCommand.php        # Artisan 手动扫描命令
    │
    ├── Http/
    │   ├── Controllers/
    │   │   ├── ViolationController.php     # 违规记录查看控制器
    │   │   └── AdminController.php         # 物品/白名单管理控制器
    │   └── routes.php                      # 路由定义（按权限分层）
    │
    ├── Jobs/
    │   └── AuditWalletTransactionsJob.php  # 核心增量审计后台 Job
    │
    ├── Models/
    │   ├── MonitorItem.php                 # 监控物品模型
    │   ├── Whitelist.php                   # 白名单模型
    │   ├── AuditStatus.php                 # 增量水位线模型
    │   └── Violation.php                   # 违规记录模型
    │
    ├── database/
    │   └── migrations/
    │       ├── ..._create_seat_audit_monitor_items_table.php
    │       ├── ..._create_seat_audit_whitelist_table.php
    │       ├── ..._create_seat_audit_status_table.php
    │       └── ..._create_seat_audit_violations_table.php
    │
    └── resources/
        └── views/
            ├── violations/
            │   └── index.blade.php         # 违规记录列表页
            └── admin/
                ├── items.blade.php         # 监控物品管理页
                └── whitelist.blade.php     # 白名单管理页
```

---

## 数据库结构

插件创建以下四张数据库表（统一前缀 `seat_audit_`）：

### `seat_audit_monitor_items` — 监控物品列表

| 字段 | 类型 | 说明 |
|------|------|------|
| `id` | bigint | 主键 |
| `type_id` | bigint UNIQUE | Eve 物品 ID |
| `item_name` | varchar | 物品名称 |
| `created_at` / `updated_at` | timestamp | 时间戳 |

### `seat_audit_whitelist` — 豁免白名单

| 字段 | 类型 | 说明 |
|------|------|------|
| `id` | bigint | 主键 |
| `character_id` | bigint UNIQUE | 豁免角色 ID |
| `character_name` | varchar | 角色名称（快照） |
| `created_at` / `updated_at` | timestamp | 时间戳 |

### `seat_audit_status` — 增量扫描水位线

| 字段 | 类型 | 说明 |
|------|------|------|
| `id` | bigint | 主键 |
| `audit_type` | varchar UNIQUE | 审计类型标识（如 `wallet_transactions`） |
| `last_id` | bigint | 上次扫描处理到的最大记录 ID |
| `created_at` / `updated_at` | timestamp | 时间戳 |

### `seat_audit_violations` — 违规记录（核心）

| 字段 | 类型 | 说明 |
|------|------|------|
| `id` | bigint | 主键 |
| `character_id` | bigint | 违规角色 ID（索引） |
| `character_name` | varchar | 角色名快照 |
| `type_id` | bigint | 违规物品 ID |
| `item_name` | varchar | 物品名快照 |
| `amount` | decimal(20,2) | 总金额（单价 × 数量） |
| `violation_time` | timestamp | 交易实际发生时间（索引） |
| `details` | json | 原始交易记录完整快照 |
| `created_at` | timestamp | 记录写入时间 |

---

## 审计逻辑

每次扫描按以下顺序处理每条交易记录：

```
读取水位线 last_id
    │
    ▼
chunk(500) 批量读取 character_wallet_transactions WHERE id > last_id
    │
    ├─► ① 白名单拦截：character_id 在白名单中？→ 跳过（最高优先级）
    │
    ├─► ② 行为判定：is_buy ≠ 0（买入）？→ 跳过
    │
    ├─► ③ 物品匹配：type_id 不在监控名单中？→ 跳过
    │
    └─► 命中 → 写入 seat_audit_violations（批量 insert）
    │
更新水位线 last_id → 本批最大 id
```

> 白名单、监控名单在 `chunk` 循环外一次性预加载至内存，避免 N+1 查询问题。

---

## 权限说明

本插件的所有功能入口均受权限保护，**未授权用户无法看到侧边栏菜单，也无法访问任何页面**。

| 权限标识 | 控制范围 |
|----------|---------|
| `seat-audit-monitor.view` | 侧边栏菜单可见；可访问 `/seat-audit/violations` 违规记录列表 |
| `seat-audit-monitor.admin` | 在违规记录页可见管理快捷按钮；可访问 `/seat-audit/admin/items` 和 `/seat-audit/admin/whitelist` |

### 权限层级关系

```
无任何权限
└─ 侧边栏不显示审计监控入口，直接访问 URL 返回 403

具备 seat-audit-monitor.view
└─ 侧边栏显示「审计监控」菜单
└─ 可查看违规记录列表（只读）
└─ 无法访问管理页面，直接访问返回 403

具备 seat-audit-monitor.admin（通常同时具备 view）
└─ 违规记录页额外显示「管理监控名单」和「管理白名单」快捷按钮
└─ 可访问监控物品管理页（增删）
└─ 可访问白名单管理页（增删）
```

### 授权方式

在 SeAT 管理后台进入 **Administration → Roles**，创建或编辑一个审计专用角色（如「Auditor」），为其分配 `seat-audit-monitor.view` 权限，再将该角色绑定给具体成员。若需要管理权限，额外分配 `seat-audit-monitor.admin`。

---

## 安装

### 环境要求

- PHP 7.4
- Eve SeAT 4.x（基于 Laravel 8.x / 9.x）
- Composer

### 步骤一：安装插件包

在 SeAT 宿主项目根目录执行：

```bash
composer require akinams053/seat-audit-monitor
```

或者，如果通过本地路径开发调试，在宿主项目的 `composer.json` 中添加：

```json
"repositories": [
    {
        "type": "path",
        "url": "../seat-audit-monitor"
    }
],
"require": {
    "akinams053/seat-audit-monitor": "*"
}
```

然后执行：

```bash
composer update akinams053/seat-audit-monitor
```

### 步骤二：运行数据库迁移

```bash
php artisan migrate
```

执行后将创建四张 `seat_audit_` 前缀的数据库表。

### 步骤三：分配权限

登录 SeAT 管理后台，进入 **Administration → Roles**，为对应角色分配以下权限：

- `seat-audit-monitor.view`：分配给需要查看违规记录的成员
- `seat-audit-monitor.admin`：分配给需要管理监控名单的管理员

### 步骤四：配置监控名单

以具有 `admin` 权限的账号登录 SeAT，通过侧边栏进入：

- **审计监控 → 管理监控名单**：添加需要监控的 Eve 物品（填写 `type_id` 和物品名称）
- **审计监控 → 管理白名单**：将豁免角色加入白名单

### 步骤五：触发首次扫描

手动触发一次扫描以建立初始水位线：

```bash
php artisan seat:audit:scan
```

### （可选）配置定时自动扫描

在 SeAT 宿主项目的 `app/Console/Kernel.php` 中注册定时任务：

```php
use Seat\SeatAuditMonitor\Jobs\AuditWalletTransactionsJob;

protected function schedule(Schedule $schedule)
{
    // 每小时自动执行一次审计扫描
    $schedule->job(new AuditWalletTransactionsJob)->hourly();
}
```

---

## 使用方法

### 1. 查看违规记录

**访问路径**：`/seat-audit/violations`（需要 `seat-audit-monitor.view` 权限）

登录 SeAT 后通过侧边栏进入违规记录列表页，页面以表格形式展示所有违规交易，列定义如下：

| 列名 | 说明 |
|------|------|
| 角色名 | 违规角色的名称快照 |
| 物品名称 | 被卖出的受监控物品名称快照 |
| 交易金额 (ISK) | 总金额（单价 × 数量），保留两位小数 |
| 发生时间 | 该笔交易在 Eve 中实际发生的时间 |

- 记录按**违规时间倒序**排列，最新违规显示在最前
- 每页显示 **50 条**，超出时页面底部出现分页导航
- 若当前用户同时具有 `admin` 权限，页面右上角会显示 **管理监控名单** 和 **管理白名单** 的快捷入口按钮

---

### 2. 管理监控物品

**访问路径**：`/seat-audit/admin/items`（需要 `seat-audit-monitor.admin` 权限）

#### 添加监控物品

页面顶部提供添加表单，填写以下两个字段后点击 **添加**：

| 字段 | 说明 | 约束 |
|------|------|------|
| Eve 物品 ID (type_id) | Eve 游戏内的物品数字 ID | 必填，正整数，不可重复 |
| 物品名称 | 物品的显示名称，用于违规记录快照 | 必填，最长 255 字符 |

> `type_id` 如已存在于监控名单中，提交后会显示验证错误，不会重复添加。

添加成功后页面顶部显示绿色提示：**监控物品已添加。**

#### 查看与删除监控物品

页面下方的表格列出当前所有监控物品，列为 **物品 ID (type_id)**、**物品名称**、**操作**。

点击对应行的 **删除** 按钮，浏览器弹出确认对话框：

```
确认删除此监控物品？
```

确认后该物品从监控名单中移除，页面显示：**监控物品已删除。**

> 删除监控物品不会清除已产生的历史违规记录，`seat_audit_violations` 表中的记录保持不变。

页面底部提供 **← 返回违规记录** 按钮。

---

### 3. 管理白名单

**访问路径**：`/seat-audit/admin/whitelist`（需要 `seat-audit-monitor.admin` 权限）

#### 添加豁免角色

页面顶部提供添加表单，填写以下两个字段后点击 **添加**：

| 字段 | 说明 | 约束 |
|------|------|------|
| 角色 ID (character_id) | Eve 游戏内的角色数字 ID | 必填，正整数，不可重复 |
| 角色名称 | 角色的显示名称，仅用于人工识别 | 必填，最长 255 字符 |

> `character_id` 如已在白名单中，提交后会显示验证错误，不会重复添加。

添加成功后页面顶部显示绿色提示：**角色已加入白名单。**

#### 查看与移除豁免角色

页面下方的表格列出当前所有豁免角色，列为 **角色 ID**、**角色名称**、**操作**。

点击对应行的 **移除** 按钮，浏览器弹出确认对话框：

```
确认将此角色从白名单移除？
```

确认后该角色从白名单中移除，页面显示：**角色已从白名单移除。**

> 移除白名单角色后，该角色在**下次扫描**起将重新纳入审计范围。已发生的历史违规记录不受影响。

页面底部提供 **← 返回违规记录** 按钮。

---

### 4. 手动触发扫描

```bash
php artisan seat:audit:scan
```

命令执行时会在终端输出以下信息：

```
开始执行钱包交易审计扫描...
审计扫描完成。
```

扫描过程为**同步执行**（在当前进程内运行，不经过队列），适用于：

- 安装插件后的首次扫描
- 添加新监控物品后需要立即检查历史数据的场景
- 定时任务之外的临时触发

> 扫描基于水位线增量进行，重复运行不会产生重复的违规记录。

---

## 卸载

### 步骤一：回滚数据库迁移

```bash
php artisan migrate:rollback --step=4
```

> `--step=4` 将回滚最近 4 次迁移（对应本插件的四张表）。
> 如果在安装本插件后还执行过其他迁移，请先确认步骤数量，或使用迁移名称精确回滚：

```bash
php artisan migrate:rollback --path=vendor/akinams053/seat-audit-monitor/src/database/migrations
```

### 步骤二：移除 Composer 包

```bash
composer remove akinams053/seat-audit-monitor
```

### 步骤三：清理缓存

```bash
php artisan config:clear
php artisan route:clear
php artisan view:clear
```

### 步骤四：清理权限（可选）

登录 SeAT 管理后台，在 **Administration → Roles** 中找到包含以下权限的角色，手动移除：

- `seat-audit-monitor.view`
- `seat-audit-monitor.admin`

> 卸载 Composer 包后，Sea 在权限列表中可能仍残留上述条目，移除后即完全清理。

---

## 开发规范

- **PHP 版本**：严格兼容 PHP 7.4，禁止使用 PHP 8.0+ 特性
- **查询方式**：后台扫描全部使用 `DB::table()`，禁止在 Job 中使用 Eloquent
- **模型基类**：所有 Model 继承 `\Seat\Services\Models\ExtensibleModel`
- **代码注释**：所有业务逻辑必须包含中文注释
- **命名规范**：类名、方法名、变量名使用英文，遵循 PSR-12
