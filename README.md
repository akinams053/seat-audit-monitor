# seat-audit-monitor

Eve SeAT 5.x 角色交易审计监控插件（市场交易 + 合同）

## 项目简介

`seat-audit-monitor` 是一款基于 Eve SeAT 5.x 插件系统的经济合规审计工具。通过增量扫描角色钱包**市场交易**和**合同**记录，自动检测涉及监控物品的可疑行为，帮助联盟管理层进行经济监管。

所有违规记录采用快照存储，不依赖原始数据，确保历史审计数据的完整性和可追溯性。

## 功能特性

- **双审计源** — 同时审计角色钱包市场交易（卖出受监控物品）与合同（包含受监控物品且双方均不在白名单）
- **增量审计** — 基于水位线机制（钱包按 id，合同按 date_completed），每次仅处理新增数据
- **物品监控** — 自定义监控物品名单，输入 type_id 自动查询物品名称
- **白名单豁免** — 两级白名单（角色 + 军团），语义不同：
    - **角色白名单**（个人豁免，OR 语义）：钱包/合同任一方在角色白名单 → 跳过
    - **军团白名单**（内部互转豁免，AND 语义）：仅合同审计生效；发起方军团 AND 接收方军团 都在军团白名单 → 跳过（典型用例：内部主公司加进军团白名单 → 内部成员之间合同豁免，与外部之间的合同仍审计）
    - **白名单事后变更对历史违规列表实时生效**（UI 查询层软过滤）
- **违规记录** — 自动记录发起方/接收方角色与军团、物品名、金额、来源类型、合同 ID 等快照信息
- **类型筛选** — 按审计类型（钱包/合同）+ 时间区间 + 通用关键词（角色名/军团名/ticker 任一命中）组合筛选；零金额合同 UI 灰底标识
- **来源细分** — 合同行 badge 按 availability 进一步分公开 / 私人 / 军团 / 联盟（颜色区分）
- **合同详情 modal** — 点击 Contract ID 弹出快照详情（合同 / 命中物品 / 三方角色）
- **CSV 导出** — 违规记录与军团审计均支持按当前筛选流式导出 CSV，Excel/WPS 可直接打开；外部文本均防护公式注入
- **手动扫描** — 旧钱包/监控物品合同保留原 Web 扫描入口；军团审计页可按当前标签异步提交 ISK 捐赠或成员低价合同扫描，浏览器在任务结束后自动刷新并提示结果；Artisan 仍可按 `wallet`、`contracts`、`donations`、`member-contracts` 或 `all` 执行四类审计
- **外部角色名 ESI 批量解析** — 对未在 SeAT 注册的外部玩家（违规记录显示 "Unknown (ID: X)"），Web UI 一键调 ESI 公开接口同时解析**角色名 + 当前所属军团 + 军团名字**并回写本地缓存（`universe_names` + `character_affiliations`），或 Artisan `seat:audit:resolve-unknown-names`
- **白名单加入外部角色** — 角色白名单支持加入非 SeAT 内的外部角色（本地搜不到时通过 ESI `/universe/ids/` 精确名字查找）
- **权限隔离** — 查看权限 (view) 与管理权限 (admin) 分离

## 军团审查 2.0 开发状态

当前 `feature/corporation-audit-2.0` 分支将 2.0 审计范围固定为 EVE corporation **`98588384`**，审计其**扫描执行时的当前成员名册**与外部方之间的交易；不管理多军团，也不推断历史入退团关系。

- **ISK 捐赠**：读取 `character_wallet_journals.ref_type=player_donation`。同一 donation 的正负镜像共享 journal `id`，规范化后只写一条；donor/recipient 始终是 `first_party_id → second_party_id`，不根据金额正负交换方向。
- **成员低价合同**：只审 `finished` 的 `item_exchange` / `auction`，且 `price < 5,000,000.00`；`reward` 不参与阈值，命中记录的金额固定为 `price`，每份合同最多写一条，并保存完整 `contract_items` 快照。合同业务字段来自 `contract_details`，物品来自 `contract_items`；低价判断额外在 MariaDB 中将 `price` 投影为 `DECIMAL(20, 2)`，避免 PDO 将原始字段返回为 PHP float 而产生金额精度误判。
- **成员与白名单规则**：以当前 `corporation_members(corporation_id=98588384)` 做成员 XOR；成员→外部为 `outbound`、外部→成员为 `inbound`，内部或外部双方交易跳过。新规则只对**外部方**应用角色/军团白名单，Unknown 外部实体不会被自动豁免。
- **启用前提**：migration 的初始配置默认停用。部署前须人工把 `seat_audit_corporations` 中 `corporation_id=98588384` 的 `enabled` 设为真，并设置实际业务生效时间 `audit_from`；所有事件仍须晚于或等于该时间。
- **独立进度与幂等**：旧钱包/监控物品合同继续使用 `seat_audit_status`；新 donation/member-contract 使用 `seat_audit_scan_cursors`。成员合同以 `MAX(character_contracts.updated_at)` 聚合为 `discovered_at`，仅用于发现来源与 cursor；`date_completed` 仍是业务时间和 `audit_from` 判断依据。`audit_from` 是首次扫描允许追溯的起点，不是每次扫描的全量范围；cursor 建立后只读取新来源及向前十分钟 overlap。每批将写入和 cursor 推进置于同一事务，`source_event_key` 唯一索引防止镜像、重试和 overlap 重复写入。若修复曾导致 cursor 已越过的历史漏报，必须通过受控补扫处理，不能期待正常增量扫描自动补录。
- **独立审计入口与导出**：侧边栏「军团审计」以「ISK 捐赠」和「成员低价合同」标签分别显示两类 2.0 记录；具有 admin 权限的用户可从当前标签异步提交扫描。提交后页面以 30 分钟短 TTL 的共享 Cache 轮询本次任务状态，显示排队/批次/新增数，并在成功、跳过或最终失败后自动刷新一次并提示结果；它不提供跨页面或跨管理员的 Job 去重，使用者仍应等待本次结果而不要重复点击。Cache 被清理或过期时只会失去页面跟踪，不影响 Job、cursor 或已入库数据。具有 view 权限的用户可导出当前标签和日期范围内的全部 2.0 记录；导出不分页、不混入旧 1.0 数据、不输出 `details` JSON，并以流式 CSV、UTF-8 BOM 和 Excel/WPS 公式注入防护输出。页面日期只过滤已入库结果，不改变 `audit_from` 或 cursor 的实际扫描范围；运行 Web 扫描前必须确保 SeAT 的 queue worker / Horizon 与共享 Cache 正常运行。

测试服务器已完成 2.0 migration、PHP 语法、队列/Horizon 与真实成员低价合同扫描验证：合同 `#234305678` 已写入一条 `member_contracts` 违规，金额为 `0.00`，并确认内部 `price_decimal` 投影不会混入 `details.contract` 快照。当前已知待处理事项是：如需补录修复前被 cursor 越过的历史合同，须先统计范围并执行受控补扫；ISK 捐赠完整落库验证与旧 1.0 审计回归仍应按 [`todo.md`](todo.md) 继续执行。

## 令牌审查 2.1

侧边栏「审计监控 → 令牌审查」提供固定军团 **`98588384`** 的只读 SeAT 状态页面。它不创建插件数据表、不派发 Job、不调用 ESI/SSO，也不会读取、输出或记录 token、refresh token、scope、JWT 或 `expires_on`。

- **成员范围**：仅从 `corporation_members(corporation_id=98588384)` 读取当前 SeAT 已同步的成员；没有绑定 SeAT 的成员仍会显示为「无 SeAT 用户」。
- **令牌三态**：只使用 `refresh_tokens.character_id`、`user_id` 与 `deleted_at`；存在未软删除记录为「状态正常」、仅有已删除记录为「账号过期」、没有记录为「无 SeAT 用户」。这只是 SeAT 本地记录状态，不做实时 token/SSO 有效性探测。
- **主/子角色**：按 `refresh_tokens.user_id → users.main_character_id` 分组。状态与时间范围先在角色级别筛选，再按账号组分页，因此同账号只显示命中条件的角色行。
- **头衔与时间**：角色头衔来自 `character_infos.title`；入团时间来自 `corporation_member_trackings.start_date`；最后上线来自 `character_onlines.last_login`；最后离线来自 `corporation_member_trackings.logoff_date`。最后上线与最后离线分别表示最后登录与军团追踪到的最后登出，均不是当前在线状态，不能互相替代；无记录会明确显示「无记录」。
- **筛选与时区**：支持令牌状态、关键词、最后上线、最后离线、入团时间以及每页账号组数。三种时间筛选均按角色级 AND 组合；30/60 天边界按 UTC 自然日计算，60 天内包含 30 天内；悬停时间单元格可查看精确 UTC 时间。
- **CSV 导出**：具有 `seat-audit-monitor.view` 权限的用户可导出当前角色级筛选命中的全部成员，不受账号组分页影响。每行保留主角色归属、角色、头衔、令牌状态、入团/最后上线/最后离线的精确 UTC 时间；不导出 SeAT 内部 user ID、group key 或任何 token/refresh token/scope/JWT/`expires_on` 数据。所有单元格均实施 Excel/WPS 公式注入防护。
- **性能决策**：测试服务器实测目标军团 2,171 名成员时，成员范围、token、用户、角色、名称、tracking 的最终 JOIN 均由索引/主键驱动，无重复关联或全表扫描。页面保持实时单次查询，不预先增加缓存或快照表；仅在未来出现慢查询、高并发或计划退化的证据时，再评审短 TTL 脱敏缓存。

> SeAT 升级后，应在测试环境重新核验 `corporation_members`、`refresh_tokens`、`character_onlines`、`corporation_member_trackings` 的字段、唯一键与最终查询 `EXPLAIN`，再部署令牌审查变更。

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

# 安装正式版 2.1（含军团审计、令牌审查与 CSV 导出）
sudo -u www-data composer require akinams053/seat-audit-monitor:2.1 --update-with-dependencies

# 执行数据库迁移（首次安装会建 4 张基础表 + 2 张合同审计扩展表）
sudo -u www-data php artisan migrate

# 刷新缓存
sudo -u www-data php artisan config:cache
sudo -u www-data php artisan route:cache
```

> 想试用尚未合入 main 的军团审查 2.0 基础设施分支，必须先确认该分支已经推送到远端，然后使用：
> `composer require akinams053/seat-audit-monitor:dev-feature/corporation-audit-2.0 --update-with-dependencies`
>
> 同一分支后续更新可执行：
> `composer update akinams053/seat-audit-monitor --with-dependencies`
>
> 尚未 push 的本地分支无法被服务器 Composer 拉取；这种情况只能使用上文的本地 path repository 方式。

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

> 适用场景：已经装了旧版本，现在要升级到当前版本（含军团白名单 + 合同详情 modal + availability 细分）。
> 本次升级**无破坏性变更**：
> - 合同审计扩展：2 个 migration 加 `audit_type` / `contract_id` 字段
> - 接收方扩展：2 个 migration 加 `counterparty_id` / `counterparty_name` 字段 + 回填历史数据
> - 军团白名单：1 个 migration 新建 `seat_audit_corporation_whitelist` 表
> - 合同可见性：2 个 migration 加 `contract_availability` 字段 + 回填历史数据
> 所有新增字段/表均可回滚。

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

### 正式版 2.1 升级

正式标签 `2.1` 包含军团审查 2.0、令牌审查 2.1，以及军团审计扫描完成自动刷新与 CSV 导出。**已经完成 2.0 migration 的实例**（包括此前安装 `dev-feature/corporation-audit-2.0` 开发版的实例）可按以下步骤升级；本次只更新 PHP、Blade、路由和 Cache 进度逻辑，**不需要再次执行 migration，也不要手动触发扫描**：

```bash
cd /var/www/seat

# 1. 将 Composer 依赖从开发分支或旧正式版本切换到正式 2.1 标签
sudo -u www-data composer require \
  akinams053/seat-audit-monitor:2.1 \
  --update-with-dependencies

# 2. 让 Web 与 queue worker 后续加载新的配置、路由和 Blade
sudo -u www-data php artisan config:clear
sudo -u www-data php artisan route:clear
sudo -u www-data php artisan view:clear
```

升级后保持 queue worker / Horizon 与 Web 使用同一个共享 Cache（生产环境通常为 Redis），再通过「军团审计」页面提交一次扫描验收自动刷新和 CSV。若实例尚未安装 2.0 基础设施，必须先按下方开发分支升级流程预览并执行插件 migration；不要因为升级到 2.1 而跳过旧版本所缺的 migration。

### 军团审查 2.0 / 令牌审查 2.1 开发分支升级

`feature/corporation-audit-2.0` 分支保留给尚未发布的后续开发验证。首次从旧版本升级到 2.0 时，建议先预览插件 migration，再执行实际迁移；已经完成 2.0 migration、仅验证开发中页面或 CSV 改动时，只需 Composer 更新并清理缓存，**不需要再次执行 migration**：

```bash
cd /var/www/seat

# 1. 拉取已推送的开发分支
sudo -u www-data composer require \
  akinams053/seat-audit-monitor:dev-feature/corporation-audit-2.0 \
  --update-with-dependencies

# 2. 只预览本插件 migration
sudo -u www-data php artisan migrate --pretend \
  --path=vendor/akinams053/seat-audit-monitor/src/database/migrations

# 3. 确认 SQL 后再执行实际迁移
sudo -u www-data php artisan migrate \
  --path=vendor/akinams053/seat-audit-monitor/src/database/migrations

# 4. 清理插件相关缓存
sudo -u www-data php artisan config:clear
sudo -u www-data php artisan route:clear
sudo -u www-data php artisan view:clear
```

2.0 基础设施新增 6 个 migration：受审军团配置、初始军团配置、复合扫描游标、违规表扩展、历史来源事件键回填、来源事件键唯一索引。回填 migration 不删除历史重复行；只有每组最早记录获得规范键，其余历史行保留 `source_event_key=NULL`。迁移日志会输出 scanned / backfilled / duplicates / unresolved 统计。令牌审查 2.1、军团审计的自动刷新与 CSV 导出均不新增 migration。

### 升级验证

1. 侧边栏点击 **违规记录**，确认筛选区出现 **审计类型** 下拉
2. 表格出现 **发起方** / **接收方** / **来源** / **Contract ID** 列；钱包行接收方显示「市场」badge，合同行接收方显示 acceptor 角色名
3. 卡片标题旁有 **白名单实时过滤** 提示图标——角色白名单更新后违规列表自动按当前白名单排除（发起方或接收方任一命中即隐藏）；军团白名单仅作用于合同行（按角色当前所属军团判断）
4. 侧边栏「白名单」页面现在分两个 tab：**角色白名单**（钱包+合同均生效）+ **军团白名单**（仅合同生效），后者只能选 SeAT 已收录的军团
5. **首次合同回扫推荐用命令行**（避免 Web 同步按钮被 nginx/php-fpm 60s 超时切断）：
   ```bash
   sudo -u www-data php artisan seat:audit:scan --type=contracts
   ```
   插件 migration 已预置基线 `2026-01-01`，**2026 年以前的合同完全跳过**。首次回扫的实际范围取决于 2026 年起的 finished 合同体量，索引就绪后通常 10–30 秒内完成。
6. 检查水位线已推进：
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

`--since` 会重新读取指定时间后的合同，但不会推进正式水位线。当前版本通过 `source_event_key = SHA-256(contracts|contract_id|type_id)` 和唯一索引幂等写入，已存在的 `(contract_id, type_id)` 会被自动忽略，**无需也不应为回扫预先删除历史违规记录**。

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
#   - 含军团白名单版（7 个）：--step=7
#   - 含 availability 细分版（9 个）：--step=9
sudo -u www-data php artisan migrate:rollback --step=9 \
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

页面分两个 tab：

**角色白名单**（钱包+合同均生效）
- 输入角色名搜索，选择后自动填入 character_id
- 白名单角色的所有交易将被完全跳过
- **外部角色（非 SeAT 内）**：本地搜不到时下拉菜单底部会出现「在 ESI 中精确查找 'XXX'」按钮（输入 ≥3 字符触发），点击调 EVE 官方接口按完整名字查 ID 后加入白名单

**军团白名单**（仅合同生效）
- 输入军团名或 ticker 搜索（数据源是 SeAT 已收录的 corporation_infos）
- 钱包审计不受军团白名单影响（钱包对手方是市场，无军团）

**豁免判定（两套白名单语义不同）**：
- **角色白名单**（OR）：合同的 issuer 或 acceptor 任一在角色白名单 → 跳过（钱包审计同样按角色白名单单方拦截）。
- **军团白名单**（AND，仅合同）：合同的 issuer 军团 AND acceptor 军团 都在军团白名单 → 跳过。
- 把内部主公司（如 YeLuo-XingHai）加进军团白名单 → 内部成员之间的合同豁免，内部与外部之间的合同仍审计。
- 把高度信任的个人放进角色白名单 → 他无论和谁交易都不审。

移除白名单后：变更对**历史违规列表**实时生效（软过滤），同时下次扫描起该角色/军团重新纳入审计。

### 4. 查看违规记录

路径：侧边栏 **审计监控 > 违规记录**（需 view 权限）

- 按违规时间倒序展示，每页 50 条
- 支持按**审计类型**（全部 / 钱包交易 / 合同）+ 开始日期 / 结束日期 + **通用关键词**（角色名 / 军团名 / ticker）组合筛选
- **通用关键词**：模糊匹配 character_name / counterparty_name / 双方军团 name / 双方军团 ticker 任一命中（搜"市场"= 钱包行；搜"Unknown"= 外部未授权角色；搜军团 ticker = 该军团相关合同）
- 表格列：**发起方 / 发起方军团 / 接收方 / 接收方军团 / 物品 / 金额 / 来源 / Contract ID / 时间**（双方军团显示 ticker，hover 显示全名）
- **来源** badge 细分：钱包（灰）/ 合同·公开（绿）/ 合同·私人（橙）/ 合同·军团（蓝绿）/ 合同·联盟（深蓝）
- 点击 **Contract ID** 弹出 modal 显示完整合同快照详情（合同信息 / 命中物品 / 三方角色）
- **零金额合同**整行用灰底标识，便于一眼区分"成交套现"与"零价物资划转"
- 点击 **导出 CSV (Excel)** 导出当前筛选结果（含发起方/接收方军团 + availability 列）

### 5. 触发审计扫描

**旧 1.0 Web 界面**：违规记录页右上角下拉选择审计类型（全部 / 仅钱包 / 仅合同），点击 **立即审查**（需 admin 权限）。

**军团审计 Web 界面**：在「军团审计」页顶部切换 **ISK 捐赠** / **成员低价合同** 标签；admin 点击当前标签旁的扫描按钮后，任务会异步加入队列。页面会立即提示“已提交，请勿重复点击”，并通过共享 Cache 每 3 秒轮询排队/处理进度；任务成功、跳过或最终失败后会自动刷新一次并显示提示。浏览器页面关闭、Cache 过期或 Cache 被清理时不影响后台 Job，只是页面无法继续跟踪该次任务，届时应手动刷新查看记录。该体验层不阻止多个页面或管理员重复投递扫描。页面日期筛选仅影响显示结果，不会改变 `audit_from` 或 cursor 的实际扫描范围。

**军团审计 CSV**：具有 view 权限的用户可在当前标签旁点击 **导出 CSV**，下载该审计类型和当前日期范围内的全部结果，不受页面 50 条分页限制。导出包含成员/外部方、方向、金额、来源引用或 Contract ID、军团 ID 快照与发生时间；不包含 `details` JSON，且所有单元格均防护 Excel/WPS 公式注入。

**命令行**：
```bash
# 默认全部审计
sudo -u www-data php artisan seat:audit:scan

# 仅审钱包交易
sudo -u www-data php artisan seat:audit:scan --type=wallet

# 仅审旧监控物品合同；--since 仅对此类型生效，且本次不推进旧水位线
sudo -u www-data php artisan seat:audit:scan --type=contracts
sudo -u www-data php artisan seat:audit:scan --type=contracts --since="2026-07-01"

# 仅审固定军团 98588384 的 ISK 捐赠或成员低价合同
sudo -u www-data php artisan seat:audit:scan --type=donations
sudo -u www-data php artisan seat:audit:scan --type=member-contracts
```

旧钱包/监控物品合同基于 `seat_audit_status` 增量执行；两类军团审计使用独立 cursor，并要求配置已启用且已人工设置 `audit_from`。`audit_from` 仅决定首次扫描允许读取的最早业务时间，后续正常扫描只处理 cursor 之后的新来源和十分钟 overlap；所有类型都依赖来源键唯一索引防止重复记录。

### 5.5 解析外部角色名（Unknown ID）

违规记录里会出现 `Unknown (ID: 2120882761)` 这类条目——这是未在你 SeAT 实例授权过 ESI 的外部玩家，SeAT 本地没有他们的名字。

**触发方式**（任一即可，admin 权限）：

- Web 界面：违规记录页右上角 **解析未知来源** 按钮。任务异步入 Horizon 队列，几秒~几十秒后刷新页面可见结果。
- 命令行：`sudo -u www-data php artisan seat:audit:resolve-unknown-names`（同步执行）。

**工作原理**（三步走，均调 ESI 公开接口，无需 token）：

1. **解析角色名** — `POST /universe/names/` 把所有 Unknown 的 character_id 解析为名字，UPDATE 到 `seat_audit_violations` + UPSERT 到 `universe_names`（SeAT 共用名字缓存）
2. **解析当前军团** — `POST /characters/affiliation/` 拿到 char→corp 映射，UPSERT 到 `character_affiliations`（让 UI 能 JOIN 出当前军团）
3. **解析军团名字** — `POST /universe/names/` 把上一步拿到的所有 corp_id 解析为军团名，UPSERT 到 `universe_names`（违规列表「发起方军团/接收方军团」列就能显示外部军团名）

**注意**：
- ESI 整批包含已注销/无效 ID 时该批可能返回 4xx，本插件按设计跳过失败批次，下次重跑会再试
- 详细进度看 `storage/logs/laravel.log` 中 `[seat-audit:resolve-unknown]` 前缀
- 已解析过的 ID 不会重复请求（角色名 WHERE 限定 `LIKE 'Unknown%'`；affiliation/corp 名字会先查 `character_affiliations`/`universe_names` 已有的跳过）
- 外部军团目前只能拿到 name 没有 ticker（ESI 单调用慢，未做 ticker fallback），UI 显示军团名而非 ticker

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

- **水位线机制**：旧扫描进度继续记录在 `seat_audit_status` 表中（钱包按 `last_id` 推进，合同按 `last_completed_at` 推进）；军团审查 2.0 后续扫描器使用 `seat_audit_scan_cursors` 按 scanner 和受审军团分别推进
- **幂等写入**：钱包事件使用 `wallet_transactions|character_id|transaction_id`，监控物品合同使用 `contracts|contract_id|type_id` 生成 SHA-256 `source_event_key`；唯一索引负责拦截重试、回扫和并发重复写入
- **快照存储**：违规记录保存发起方/接收方角色名、物品名的快照副本，不受原始数据变更影响；合同审计还快照 issuer/assignee/acceptor 三方信息到 `details.parties`
- **接收方语义**：钱包审计 `counterparty_name='市场'`（对手方是市场撮合系统）；合同审计 `counterparty_name=acceptor 角色名`
- **批量处理**：每次以 500 条为一批处理，白名单 / 监控名单 / 角色名映射预加载至内存，避免 N+1 查询
- **审计优先级**：
    - 钱包：发起方在角色白名单 → 豁免；否则按 is_buy=0 + 物品匹配判定（军团白名单不参与）
    - 合同：仅 finished + item_exchange/auction → 任一方在角色白名单 OR (双方军团都在军团白名单) → 豁免；否则按物品匹配判定（不再单独检查 assignee）
- **白名单实时过滤**：违规列表/导出查询时按上述豁免语义做 LEFT JOIN 软过滤；DB 历史数据不变；白名单加/减都即时生效、可逆
- **数据安全**：删除监控物品或移除白名单角色，均不会影响已有的违规记录

## 卸载

```bash
cd /var/www/seat

# 1. 按实际已执行批次回滚插件 migration
# 建议先用 migrate:status 核对 batch；不要在共享 SeAT 数据库中盲目指定过大的 --step。
sudo -u www-data php artisan migrate:status \
  --path=vendor/akinams053/seat-audit-monitor/src/database/migrations
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
