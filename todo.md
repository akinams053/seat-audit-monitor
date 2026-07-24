# 军团审查 2.0：固定单军团实施状态

> 范围：在不改变 1.0 市场交易/监控物品合同审计的前提下，为 EVE corporation `98588384` 增加成员与外部方之间的 ISK 捐赠、成员低价合同审计。
>
> 本轮明确不做：多受审军团管理、历史成员资格、Web 扫描、`--dry-run`、新页面 CSV/详情 modal、令牌审查。令牌状态能力属于后续阶段。

## 已确定的业务口径

- [x] **固定军团**：新 2.0 逻辑只处理 `corporation_id=98588384`；不提供多军团选择或配置管理页。
- [x] **成员边界**：使用扫描执行时 `corporation_members(corporation_id=98588384)` 的当前成员名册；不根据当前名册回推历史入退团。
- [x] **启用起点**：`audit_from` 由部署时人工设置，且同一配置行必须设置 `enabled=true`；任何新事件均要求发生时间不早于该值。
- [x] **新白名单**：只检查外部方的角色白名单和当前军团白名单；受审成员本人命中白名单仍参与审计，Unknown 外部方不自动豁免。
- [x] **兼容性**：1.0 `wallet_transactions` 与 `contracts` 保持原 Job、水位线、金额规则、白名单软过滤、旧列表和 CSV 查询范围不变。

## 已实现

### 1. 基础数据与幂等写入

- [x] 保留已发布的 2.0 migration：`seat_audit_corporations`、`seat_audit_scan_cursors`、violation 扩展字段和 `source_event_key` 唯一索引。
- [x] 固定 `AuditCorporation::TARGET_CORPORATION_ID = 98588384`，新 cursor 与来源键均使用 EVE corporation ID，而非配置表自增主键。
- [x] `CursorRepository::lockOrCreateForUpdate()` 通过 `insertOrIgnore()` 后加行锁处理首次扫描并发；来源写入与 cursor 推进可置于同一事务。
- [x] 旧钱包/监控物品合同继续使用 `seat_audit_status`；新 donation/member-contract 使用独立的 `seat_audit_scan_cursors`。

### 2. ISK 捐赠审计

- [x] `AuditDonationsJob` 读取固定配置、当前成员名册和白名单；配置未启用、未设 `audit_from` 或名册为空时安全退出且不推进 cursor。
- [x] `DonationJournalScanner` 只扫描 `character_wallet_journals.ref_type='player_donation'`。
- [x] 同一笔正负镜像以共享 journal `id` 作为 canonical ID：单边记录可用，双边镜像必须校验 party、时间、金额、符号和 owner；异常镜像不猜测修正。
- [x] 固定 `first_party_id → second_party_id` 为 donor → recipient；成员 XOR 决定 `outbound` / `inbound`，内部或无关事件跳过。
- [x] Donation cursor 使用 `(occurred_at, 最小 owner character_id, canonical journal id)`；`source_event_key` 保护镜像、重试和重复扫描。

### 3. 成员低价合同审计

- [x] `AuditMemberContractsJob` 独立于旧 `AuditContractsJob`，只处理 `finished` 的 `item_exchange` / `auction`。
- [x] 仅当 `price < 5,000,000.00` 才记录；`price = 5,000,000.00` 不记录，`reward` 不参与门槛，违规金额使用原始 `price`。
- [x] 仅 issuer / acceptor 做成员 XOR；每份命中合同只写一条 `member_contracts` violation，物品字段为 NULL，完整 `contract_items` 保存到 details。
- [x] 以 `(discovered_at, contract_id)` cursor 配合十分钟 overlap 读取延迟同步合同；`discovered_at` 是 `MAX(character_contracts.updated_at)` 的映射发现时间，只用于来源排序与推进，不读取合同业务内容。正式 cursor 只单调推进，来源键负责 overlap 去重。

### 4. 入口与浏览

- [x] `seat:audit:scan --type` 支持 `wallet`、`contracts`、`donations`、`member-contracts`、`all`；`all` 按 wallet → contracts → donations → member-contracts 执行。
- [x] `--since` 保持为旧 `contracts` 的临时回扫参数，不影响新军团审计 cursor 或 `audit_from`。
- [x] 新增只读「军团审计」侧边栏、路由、Controller 与视图，仅查询 `98588384` 的 `isk_donations` / `member_contracts`，提供类型、时间范围和分页。
- [x] 旧 `ViolationController`、旧「违规记录」、旧 CSV 没有扩展到新类型，避免混用旧/新白名单语义。

## 发布前待验证

> 本机没有 PHP 可执行程序；以下项目尚未验证，不能宣称已通过。

- [x] 本机没有 PHP；已将本次修改的 20 个 PHP 文件临时上传至测试服务器 `/tmp/seat-audit-monitor-lint-2ae270e7` 执行 PHP 8.4.21 的 `php -l`，全部通过。命令以 `trap` 在退出时清理该临时目录，未写入 SeAT 部署目录或数据库。
- [x] 已执行 `git diff --check`，未发现补丁空白错误；Windows 工作区仅报告既有 LF/CRLF 转换提示。
- [x] 已在测试服务器只读确认：`contract_details` 不含 `updated_at`，`character_contracts` 具有 `created_at` / `updated_at` 映射时间字段；PHP 8.4.21 / Laravel 10.50.2 可用。
- [x] 已在测试服务器只读验证聚合查询：6,891 份映射合同中 1,041 份存在多条角色映射，`MAX(character_contracts.updated_at)` 能收敛为每合同一个发现位置；5,262 份符合基础完成合同条件。同一 `discovered_at` 存在多份合同，因此 `(discovered_at, contract_id)` 分页消歧是必要的；`EXPLAIN` 显示当前 8,024 条关联行会使用临时表与 filesort，未在本轮新增索引。
- [ ] 在**测试服务器**继续只读确认 `character_contracts.updated_at` 的实际更新语义；在获得写操作授权后的真实扫描中验证十分钟 overlap、cursor 单调推进和来源键幂等。连接前必须重新获得用户对测试环境的授权。
- [ ] 获得单独写操作授权后，以 migration `--pretend` 验证升级路径；不得直接迁移、设置 `enabled`、写入 `audit_from` 或真实扫描。
- [ ] 获得单独写操作授权后，分别执行新审计并 SQL 对账：Donation 镜像/XOR/白名单，合同 `price < 5,000,000.00` / `reward` / 完整 items / overlap 幂等。
- [ ] 回归旧钱包与监控物品合同：确认旧命令、旧列表和旧 CSV 的类型范围、金额和白名单语义均未变化。

## 后续阶段（不属于本轮）

- [ ] 令牌审查：参考用户提供的正常 / 过期 / 未绑定范例，另行确定数据源与页面行为。
- [ ] 审计军团配置页面、历史成员资格、多军团支持。
- [ ] 军团审计详情 modal、CSV 导出、Unknown 实体的专用 ESI 解析。
