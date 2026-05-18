# seat-audit-monitor

Eve SeAT 5.x 角色交易审计监控插件（市场交易 + 合同）

## 项目简介

`seat-audit-monitor` 是一款基于 Eve SeAT 5.x 插件系统的经济合规审计工具。通过增量扫描角色钱包**市场交易**和**合同**记录，自动检测涉及监控物品的可疑行为，帮助联盟管理层进行经济监管。

所有违规记录采用快照存储，不依赖原始数据，确保历史审计数据的完整性和可追溯性。

## 功能特性

- **双审计源** — 同时审计角色钱包市场交易（卖出受监控物品）与合同（包含受监控物品且双方均不在白名单）
- **增量审计** — 基于水位线机制（钱包按 id，合同按 date_completed），每次仅处理新增数据
- **物品监控** — 自定义监控物品名单，输入 type_id 自动查询物品名称
- **白名单豁免** — 指定角色跳过所有审计；合同审计对 issuer/assignee/acceptor 三方任一命中即跳过；**白名单事后变更对历史违规列表实时生效**（UI 查询层软过滤）
- **违规记录** — 自动记录发起方/接收方、物品名、金额、来源类型、合同 ID 等快照信息
- **类型筛选** — 按审计类型（钱包/合同）+ 时间区间组合筛选；零金额合同 UI 灰底标识
- **CSV 导出** — 一键导出违规记录（含审计类型 + Contract ID 列），Excel/WPS 直接打开
- **手动扫描** — Web 界面一键触发（可选审钱包/合同/全部）或 Artisan 命令行 `seat:audit:scan --type=wallet|contracts|all`
- **权限隔离** — 查看权限 (view) 与管理权限 (admin) 分离

## 环境要求

- Eve SeAT 5.x
- PHP 8.1+
- Laravel 10.x
- MySQL / MariaDB
- Composer

## 安装

> 以下命令均在 SeAT 安装目录下执行（通常为 `/var/www/seat`）。

### Composer 安装（推荐）

```bash
cd /var/www/seat

# 安装稳定分支（main，含合同审计）
sudo -u www-data composer require akinams053/seat-audit-monitor:dev-main

# 执行数据库迁移（首次安装会建 4 张基础表 + 2 张合同审计扩展表）
sudo -u www-data php artisan migrate

# 刷新缓存
sudo -u www-data php artisan config:cache
sudo -u www-data php artisan route:cache
```

> 想试用尚未合入 main 的功能分支，把上面 `dev-main` 换成对应分支约束，例如：
> `composer require akinams053/seat-audit-monitor:dev-feature/contract-audit`

### 本地路径安装（开发调试）

```bash
# 1. 克隆仓库到 SeAT 同级目录
cd /var/www
git clone https://github.com/akinams053/seat-audit-monitor.git

# 2. 在 SeAT 的 composer.json 中添加本地仓库源
# "repositories": [{"type": "path", "url": "../seat-audit-monitor"}]

# 3. 安装并迁移
cd /var/www/seat
sudo -u www-data composer require akinams053/seat-audit-monitor:@dev
sudo -u www-data php artisan migrate
sudo -u www-data php artisan config:cache
sudo -u www-data php artisan route:cache
```

### 安装验证

1. 登录 SeAT，侧边栏出现 **审计监控** 菜单
2. 点击 **违规记录**，页面正常加载（首次为空）
3. 在 **Settings > SeAT Module Versions** 中确认插件已识别

> 遇到 500 错误时执行缓存清理：
> ```bash
> sudo -u www-data php artisan config:clear && php artisan route:clear && php artisan view:clear && php artisan cache:clear
> ```

## 升级

> 适用场景：已经装了旧版本（仅市场交易审计或不含「接收方」列的合同审计版本），现在要升级到当前版本。
> 本次升级**无破坏性变更**：
> - 合同审计扩展：2 个 migration 加 `audit_type` / `contract_id` 字段
> - 接收方扩展：2 个 migration 加 `counterparty_id` / `counterparty_name` 字段 + 回填历史数据
> 所有新增字段均可回滚。

```bash
cd /var/www/seat

# 1. 拉新版本（main，或某个功能分支例如 dev-feature/contract-audit）
sudo -u www-data composer require akinams053/seat-audit-monitor:dev-main
# 或：sudo -u www-data composer require akinams053/seat-audit-monitor:dev-feature/contract-audit

# 2. 预览将要执行的 SQL（强烈建议先 dry-run 看一眼）
sudo -u www-data php artisan migrate --pretend \
  --path=vendor/akinams053/seat-audit-monitor/src/database/migrations

# 3. 真正执行迁移（仅本插件路径，避免影响其它模块）
sudo -u www-data php artisan migrate \
  --path=vendor/akinams053/seat-audit-monitor/src/database/migrations

# 4. 清缓存
sudo -u www-data php artisan config:clear && \
sudo -u www-data php artisan route:clear && \
sudo -u www-data php artisan view:clear
```

### 升级验证

1. 侧边栏点击 **违规记录**，确认筛选区出现 **审计类型** 下拉
2. 表格出现 **发起方** / **接收方** / **来源** / **Contract ID** 列；钱包行接收方显示「市场」badge，合同行接收方显示 acceptor 角色名
3. 卡片标题旁有 **白名单实时过滤** 提示图标——白名单更新后违规列表自动按当前白名单排除（发起方或接收方任一命中即隐藏）
4. **首次合同回扫推荐用命令行**（避免 Web 同步按钮被 nginx/php-fpm 60s 超时切断）：
   ```bash
   sudo -u www-data php artisan seat:audit:scan --type=contracts
   ```
   插件 migration 已预置基线 `2026-01-01`，**2026 年以前的合同完全跳过**。首次回扫的实际范围取决于 2026 年起的 finished 合同体量，索引就绪后通常 10–30 秒内完成。
5. 检查水位线已推进：
   ```bash
   sudo mariadb seat -e "SELECT audit_type, last_id, last_completed_at FROM seat_audit_status;"
   ```
   应看到 `contracts` 行的 `last_completed_at` 已超过 `2026-01-01`（推进到实际处理过的最晚合同完成时间）。

### 回扫历史合同（可选）

默认基线 `2026-01-01` 跳过历史。如果某次需要回扫某段历史，使用 `--since` 临时覆盖：

```bash
# 回扫 2025 全年（仅本次有效，不修改水位线）
sudo -u www-data php artisan seat:audit:scan --type=contracts --since=2025-01-01

# 回扫某天起的合同
sudo -u www-data php artisan seat:audit:scan --type=contracts --since="2025-06-01 00:00:00"
```

⚠ **注意**：`--since` 会**重复处理已扫过的合同**（`seat_audit_violations` 表无去重约束），可能产生重复行。建议先 `DELETE FROM seat_audit_violations WHERE audit_type='contracts' AND violation_time >= '<since>'` 清掉对应区间再回扫。

如果希望永久改基线（例如未来想从 2025 起持续审计），直接改水位线行即可：

```sql
UPDATE seat_audit_status SET last_completed_at = '2025-01-01 00:00:00' WHERE audit_type = 'contracts';
```

### 升级回滚（不满意可退回）

```bash
# 0.（可选）若希望回滚后违规列表干净不出现已扫到的合同记录，先手动清理合同来源行：
sudo mariadb seat -e "DELETE FROM seat_audit_violations WHERE audit_type='contracts';"

# 1. 回滚本次新增的 migration（按文件 path 限定，不影响其它模块）
# --step 数量按本次实际跑了几个 migration 来定：
#   - 仅合同审计版（4 个）：--step=4
#   - 含接收方扩展版（6 个）：--step=6
sudo -u www-data php artisan migrate:rollback --step=6 \
  --path=vendor/akinams053/seat-audit-monitor/src/database/migrations

# 2. 切回旧版本（指定具体 commit 更稳）
sudo -u www-data composer require akinams053/seat-audit-monitor:dev-main#0f878da \
  --update-with-dependencies

# 3. 清缓存
sudo -u www-data php artisan config:clear
```

回滚行为说明：
- migration down 仅删除 `audit_type` / `contract_id` / `counterparty_id` / `counterparty_name` / `last_completed_at` 等字段及索引，**不删表行**
- counterparty 回填 migration 的 down 是 no-op（不主动清空回填的数据，靠 dropColumn 整列带走）
- 若跳过步骤 0：合同来源的 violation 行仍在 `seat_audit_violations` 表里，旧版 UI 会把它们当 wallet violation 显示（character_name/item_name/amount 都能正常出现，但来源信息丢失，且 contract_id / counterparty 列没了）
- Wallet 历史记录 100% 不受影响

## 使用说明

### 1. 配置权限

登录 SeAT 管理后台 **Settings > Access Management**，为角色分配权限：

| 权限 | 说明 |
|------|------|
| `seat-audit-monitor.view` | 查看违规记录、导出 CSV |
| `seat-audit-monitor.admin` | 管理监控物品、管理白名单、手动触发扫描 |

未授权用户不可见侧边栏菜单，直接访问 URL 返回 403。

### 2. 配置监控物品

路径：侧边栏 **审计监控 > 监控物品**（需 admin 权限）

- 输入 Eve 物品 type_id，系统自动从 SDE 查询物品名称
- 点击添加即可，重复 type_id 会提示错误
- 删除监控物品不影响已有违规记录

### 3. 配置白名单

路径：侧边栏 **审计监控 > 白名单**（需 admin 权限）

- 输入角色名搜索，选择后自动填入 character_id
- 白名单角色的所有交易将被完全跳过
- 移除白名单后，该角色在下次扫描起重新纳入审计

### 4. 查看违规记录

路径：侧边栏 **审计监控 > 违规记录**（需 view 权限）

- 按违规时间倒序展示，每页 50 条
- 支持按**审计类型**（全部 / 钱包交易 / 合同）+ 开始日期 / 结束日期组合筛选
- 表格 "来源" 列用 badge 区分：钱包（蓝）/ 合同（橙）；合同行多显示 Contract ID
- **零金额合同**整行用灰底标识，便于一眼区分"成交套现"与"零价物资划转"
- 点击 **导出 CSV (Excel)** 导出当前筛选结果（含审计类型 + Contract ID 两列）

### 5. 触发审计扫描

**Web 界面**：违规记录页右上角下拉选择审计类型（全部 / 仅钱包 / 仅合同），点击 **立即审查**（需 admin 权限）。

**命令行**：
```bash
# 默认全部审计
sudo -u www-data php artisan seat:audit:scan

# 仅审钱包交易
sudo -u www-data php artisan seat:audit:scan --type=wallet

# 仅审合同
sudo -u www-data php artisan seat:audit:scan --type=contracts
```

扫描基于水位线增量执行，重复运行不会产生重复记录。

### 6. 配置定时自动扫描（可选）

在 SeAT 的 `app/Console/Kernel.php` 中添加：

```php
use Seat\SeatAuditMonitor\Jobs\AuditWalletTransactionsJob;
use Seat\SeatAuditMonitor\Jobs\AuditContractsJob;

protected function schedule(Schedule $schedule)
{
    $schedule->job(new AuditWalletTransactionsJob)->hourly();
    // 合同扫描相对低频，每 6 小时一次即可
    $schedule->job(new AuditContractsJob)->cron('0 */6 * * *');
}
```

## 管理要点

- **水位线机制**：扫描进度记录在 `seat_audit_status` 表中（钱包按 `last_id` 推进，合同按 `last_completed_at` 推进），确保每条记录只处理一次
- **快照存储**：违规记录保存发起方/接收方角色名、物品名的快照副本，不受原始数据变更影响；合同审计还快照 issuer/assignee/acceptor 三方信息到 `details.parties`
- **接收方语义**：钱包审计 `counterparty_name='市场'`（对手方是市场撮合系统）；合同审计 `counterparty_name=acceptor 角色名`
- **批量处理**：每次以 500 条为一批处理，白名单 / 监控名单 / 角色名映射预加载至内存，避免 N+1 查询
- **审计优先级**：
    - 钱包：白名单拦截 > 仅卖出（is_buy=0）> 物品匹配
    - 合同：仅 finished + item_exchange/auction > 白名单三方拦截（issuer/assignee/acceptor 任一命中即跳过）> 物品匹配
- **白名单实时过滤**：违规列表/导出查询时会 LEFT JOIN 白名单，发起方或接收方任一命中当前白名单则不展示（DB 历史数据不变；白名单加/减都即时生效、可逆）
- **数据安全**：删除监控物品或移除白名单角色，均不会影响已有的违规记录

## 卸载

```bash
cd /var/www/seat

# 1. 回滚数据库（删除插件的 4 张表）
sudo -u www-data php artisan migrate:rollback \
  --path=vendor/akinams053/seat-audit-monitor/src/database/migrations

# 2. 移除插件包
sudo -u www-data composer remove akinams053/seat-audit-monitor

# 3. 清理缓存
sudo -u www-data php artisan config:clear
sudo -u www-data php artisan route:clear
sudo -u www-data php artisan view:clear
```

卸载后可在 **Settings > Access Management** 中手动清理残留的 `seat-audit-monitor.*` 权限条目。

## License

GPL-2.0-only
