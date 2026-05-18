# seat-audit-monitor

Eve SeAT 5.x 角色交易审计监控插件（市场交易 + 合同）

## 项目简介

`seat-audit-monitor` 是一款基于 Eve SeAT 5.x 插件系统的经济合规审计工具。通过增量扫描角色钱包**市场交易**和**合同**记录，自动检测涉及监控物品的可疑行为，帮助联盟管理层进行经济监管。

所有违规记录采用快照存储，不依赖原始数据，确保历史审计数据的完整性和可追溯性。

## 功能特性

- **双审计源** — 同时审计角色钱包市场交易（卖出受监控物品）与合同（包含受监控物品且双方均不在白名单）
- **增量审计** — 基于水位线机制（钱包按 id，合同按 date_completed），每次仅处理新增数据
- **物品监控** — 自定义监控物品名单，输入 type_id 自动查询物品名称
- **白名单豁免** — 指定角色跳过所有审计；合同审计对 issuer/assignee/acceptor 三方任一命中即跳过
- **违规记录** — 自动记录角色名、物品名、金额、来源类型、合同 ID 等快照信息
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

# 安装插件
sudo -u www-data composer require akinams053/seat-audit-monitor

# 执行数据库迁移
sudo -u www-data php artisan migrate

# 刷新缓存
sudo -u www-data php artisan config:cache
sudo -u www-data php artisan route:cache
```

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
- **快照存储**：违规记录保存角色名和物品名的快照副本，不受原始数据变更影响；合同审计还快照 issuer/assignee/acceptor 三方信息到 `details.parties`
- **批量处理**：每次以 500 条为一批处理，白名单 / 监控名单 / 角色名映射预加载至内存，避免 N+1 查询
- **审计优先级**：
    - 钱包：白名单拦截 > 仅卖出（is_buy=0）> 物品匹配
    - 合同：仅 finished + item_exchange/auction > 白名单三方拦截（issuer/assignee/acceptor 任一命中即跳过）> 物品匹配
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
