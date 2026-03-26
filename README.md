# seat-audit-monitor

Eve SeAT 5.x 角色钱包交易审计监控插件

## 项目简介

`seat-audit-monitor` 是一款基于 Eve SeAT 5.x 插件系统的经济合规审计工具。通过增量扫描角色钱包交易记录，自动检测角色卖出受监控物品的行为，帮助联盟管理层进行经济监管。

所有违规记录采用快照存储，不依赖原始数据，确保历史审计数据的完整性和可追溯性。

## 功能特性

- **增量审计** — 基于水位线机制，每次仅处理新增交易，不重复扫描
- **物品监控** — 自定义监控物品名单，输入 type_id 自动查询物品名称
- **白名单豁免** — 指定角色跳过所有审计逻辑
- **违规记录** — 自动记录角色名、物品名、交易金额等快照信息
- **时间筛选** — 支持按日期区间筛选违规记录
- **CSV 导出** — 一键导出违规记录，Excel/WPS 直接打开
- **手动扫描** — Web 界面一键触发或通过 Artisan 命令行执行
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
- 支持开始日期 / 结束日期区间筛选
- 点击 **导出 CSV (Excel)** 导出当前筛选结果

### 5. 触发审计扫描

**Web 界面**：违规记录页右上角点击 **立即审查**（需 admin 权限）

**命令行**：
```bash
sudo -u www-data php artisan seat:audit:scan
```

扫描基于水位线增量执行，重复运行不会产生重复记录。

### 6. 配置定时自动扫描（可选）

在 SeAT 的 `app/Console/Kernel.php` 中添加：

```php
use Seat\SeatAuditMonitor\Jobs\AuditWalletTransactionsJob;

protected function schedule(Schedule $schedule)
{
    $schedule->job(new AuditWalletTransactionsJob)->hourly();
}
```

## 管理要点

- **水位线机制**：扫描进度记录在 `seat_audit_status` 表中，确保每条交易只处理一次
- **快照存储**：违规记录保存角色名和物品名的快照副本，不受原始数据变更影响
- **批量处理**：每次以 500 条为一批处理，白名单和监控名单预加载至内存，避免性能问题
- **审计优先级**：白名单拦截 > 行为判定（仅卖出）> 物品匹配
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
