# 军团审查 2.0 — 第一阶段开发计划

> 目标：保留现有“受监控物品的钱包/合同审计”语义，同时新增“受审军团成员与外部方之间的 ISK 捐赠、低价已完成合同”审计。
>
> 本轮仅处理经济交易审计。SeAT 令牌、入团时间、最近上线时间等成员状态能力属于第二阶段，不在本计划实现。

## 固定业务边界

- [x] 成员边界以 `corporation_members(corporation_id, character_id)` 的**扫描时当前名册**为准。
- [x] 每个审计军团使用自己的 `audit_from`；不回扫启用前事件，避免以当前名册误判历史入退团关系。
- [x] 支持多个审计军团，成员边界分别计算。
- [x] 新 `member_contracts` 只审 `finished` 的 `item_exchange` / `auction`：`price < 5,000,000` 才记录，等于 5M 不记录，`reward` 不参与门槛；页面主金额为 `price`，详情保留原始 `reward`。
- [x] 新 `isk_donations` 固定以 `first_party_id → second_party_id` 表示 donor → recipient，金额使用绝对值；不得根据正负镜像交换方向。
- [x] 新策略只对**外部方**应用角色/军团白名单；受审成员自身命中白名单仍继续审查。
- [x] 原 `contracts` 始终表示“命中监控物品的合同”，保持既有角色 OR、军团双方 AND 的豁免语义。
- [x] 外部方可以是角色、军团、联盟或 Unknown 实体；Unknown 不得阻断扫描，后续由 ESI 补全名称。
- [x] 导出统一使用 UTF-8 BOM 流式 CSV；不在同步 Web 请求中生成 XLSX。

### 已确认的既有勘察信息

此前在测试服务器的只读勘察中已确认 `character_wallet_journals` 可提供 `player_donation` 的 party、金额、时间与 `(character_id, id)` 唯一标识，且同一捐赠存在正负镜像；`corporation_members` 使用 `(corporation_id, character_id)` 作为成员主键。这些结论仅作为开发输入；在新扫描器部署测试服务器时，以新的只读统计和 SQL 对账复验。

## 当前已实现基线

以下项目已有可定位的 migration 或源码实现，可供后续复用：

- [x] 2026-07-23 的六个基础设施 migration：受审军团、初始种子、复合扫描游标、违规表扩展、历史来源键回填、来源键唯一索引。
- [x] `AuditCorporation`、`AuditCursor` 与 `CursorRepository` 已提供受审军团配置、按 `(scanner, audit_corporation_id)` 隔离的游标及行锁/推进能力。
- [x] 初始军团 `98588384` 的**种子 migration** 已提供，且默认 `enabled=false`；这不代表任何环境已部署、启用或完成扫描。
- [x] `Violation` 已支持来源键、审计军团、成员/外部方、方向、双方军团快照与允许为空的物品字段。
- [x] `AuditType` 已集中定义 `wallet_transactions`、`contracts`、`isk_donations`、`member_contracts`，并提供新旧页面的类型分组和中文标签。
- [x] `SourceEventKeyFactory` 与 `ViolationWriter` 已实现 SHA-256 来源键、`insertOrIgnore()` 幂等写入及 inserted/duplicate 计数。
- [x] 旧 `AuditWalletTransactionsJob`、`AuditContractsJob` 已接入来源键与 `ViolationWriter`；旧合同 `--since` 回扫不推进原水位线。
- [x] 旧 `seat_audit_status` 继续保留，旧钱包/合同扫描仍可兼容运行。

### 已知缺口（不得误标为完成）

- [ ] `CursorRepository::GLOBAL_SCOPE = 0` 仅为新 scanner 预留；现有 wallet/contracts Job 仍使用 `seat_audit_status`，尚未迁移到新 cursor。
- [ ] `AuditType` 已被旧页面的筛选/CSV 分组使用；`AuditScanCommand` 目前仍只支持 `wallet`、`contracts`、`all`，没有 donations/member-contracts、`--corporation` 或 `--dry-run`。
- [ ] 尚无 `DonationJournalScanner`、成员名册 Provider、运行上下文、统一 `ContractScanner`、新合同 policy、orchestrator 或新 Job。
- [ ] 尚无审计军团管理页、独立“军团对外审查”页面/CSV，`ResolveUnknownNamesJob` 也未处理两种新类型。
- [ ] 新 scanner 尚未实现“每个 chunk 的违规写入与 cursor 推进在同一事务提交”；现有 Writer 不代表全部策略已接入。
- [ ] 现有旧 CSV 虽为响应流和 UTF-8 BOM，但仍有全量读取路径；惰性分块读取和 CSV Formula Injection 防护尚未实现。
- [ ] 仓库尚未建立自动化测试结构。

关键现有实现：

- `src/Repositories/CursorRepository.php`
- `src/Enums/AuditType.php`
- `src/Services/Audit/SourceEventKeyFactory.php`
- `src/Services/Audit/ViolationWriter.php`
- `src/Jobs/AuditWalletTransactionsJob.php`
- `src/Jobs/AuditContractsJob.php`
- `src/Console/Commands/AuditScanCommand.php`

---

## 阶段 A：扫描内核与安全写入

### A1. 成员范围与运行上下文

- [ ] 新增 `MembershipProvider` 与 `CurrentCorporationMembersProvider`，按审计军团批量读取 `corporation_members`；接口保留事件时间参数，为第二阶段成员历史实现预留替换点。
- [ ] 新增 `AuditRunContext` / Factory；一次扫描预加载启用军团、各军团成员集合、角色/军团白名单和监控物品。
- [ ] 新增 `EntitySnapshotResolver`，仅按当前 chunk 的参与方批量解析 `character_infos`、`universe_names`、`character_affiliations`、`corporation_infos`；Unknown 实体保留 ID 并继续扫描。

### A2. 来源扫描与策略

- [ ] 实现 `DonationJournalScanner`：只读取 `ref_type='player_donation'`，稳定规范 donor/recipient/绝对金额，并按每个审计军团的成员 XOR 判定 `inbound` / `outbound`；内部和无关事件不记录。
- [ ] 合并正负镜像，支持单边 journal；使用 `SourceEventKeyFactory::iskDonation()` 与唯一来源键覆盖跨 chunk、重试与回扫去重。
- [ ] 对 party 缺失、同一 party、零金额、journal 所属角色不在双方或镜像金额不一致的事件计入 invalid 并记录结构化统计，不猜测修正。
- [ ] 实现统一 `ContractScanner`：每个合同 chunk 只读取一次 `contract_items`，并同时执行旧 `MonitoredItemContractPolicy` 与新 `ExternalMemberContractPolicy`。
- [ ] 旧策略继续按 `(contract_id, type_id)` 写入；新策略按 `(audit_corporation_id, contract_id)` 仅写一条，保存完整物品列表、合同原始 `price` / `reward` 及成员方向。
- [ ] 新成员合同只使用 issuer/acceptor 进行成员 XOR 判断；`assignee_id`、`availability`、`issuer_corporation_id`、`is_included` 仅保留在详情，不参与成员资格判定。
- [ ] 新策略只对白名单外部方豁免；旧 `contracts` 保持历史白名单规则。

### A3. Cursor、运行入口与兼容性

- [ ] 每个 chunk 在一个数据库事务内完成规范化、策略执行、幂等写入与 cursor 推进；失败时 violation 与 cursor 一起回滚。
- [ ] donation 使用稳定复合 cursor，contract 使用 `updated_at + contract_id`；采用 10 分钟 overlap，并以事件时间和 `audit_from` 作最终业务过滤。
- [ ] 首次切换合同扫描时，以旧 `seat_audit_status.contracts.last_completed_at` 补齐旧策略后再建立新 cursor；切换完成前不删除旧状态。
- [ ] 新增 `AuditOrchestrator`、统一扫描结果对象及 `Cache::lock('seat-audit-monitor:scan', ...)`。
- [ ] 新增 donations 入口，并把 Command 扩展为 `wallet`、`donations`、`contracts`、`member-contracts`、`all`，支持 `--corporation`、`--since`、`--dry-run`；回扫和 dry-run 不推进正式 cursor。
- [ ] Web 手动扫描改用 orchestrator 返回的统计，不再以全表 count 前后差计算新增数。
- [ ] 仅在测试服务器对 journal/contract 候选索引执行 `EXPLAIN` 并确认收益后，才决定是否新增 SeAT 源表索引 migration。

阶段 A 完成条件：两种新审计类型可安全扫描；重试、镜像与回扫不会新增重复记录；旧 wallet/contracts 入口和行为保持可用。

---

## 阶段 B：管理、查询与导出

- [ ] 新增审计军团管理 Controller 与页面：添加军团、启停、donation/member-contract 策略开关和 `audit_from`；所有管理路由统一执行 `seat-audit-monitor.admin` Gate。
- [ ] 调整侧边栏为“物品违规审查 / 军团对外审查 / 监控物品 / 白名单”。现有违规页只查询 `wallet_transactions`、`contracts`；新增独立军团审查路由、Controller 和页面，只查询 `isk_donations`、`member_contracts`。
- [ ] 两页共用实体名称、军团、关键词、时间与白名单查询基础逻辑，但各自使用独立分页参数与严格 audit type scope，禁止记录串页。
- [ ] 军团审查页展示审计军团、成员/外部方、方向和成员身份口径；提供 Journal 详情，以及含完整 items、`price`、`reward` 和低价规则依据的合同详情。
- [ ] 扩展 `ResolveUnknownNamesJob`：可补齐新类型的双方实体名称、实体类型与当前 affiliation；ESI 不参与历史成员资格判断。
- [ ] 保留旧 CSV 兼容性，并新增独立军团审查 CSV。两个导出均复用当前页筛选，以 `streamDownload()` 和 `lazyById` / `chunkById` 分批读取所需字段，不生成同步 XLSX 临时文件。
- [ ] 对 CSV 中可能以 `=`, `+`, `-`, `@` 开头的文本执行 Formula Injection 防护。

阶段 B 完成条件：新旧审查各自拥有受权限保护的页面、详情与 CSV；筛选范围和导出范围严格一致，且互不串页。

---

## 阶段 C：文档、发布与验收

- [ ] 更新 `README.md` 与 `CLAUDE.md`：明确四类审计类型、受审军团管理、调度、dry-run、幂等回扫、当前成员名册口径和第二阶段边界；统一 `isk_donations` 名称。
- [ ] 补充功能分支部署、Composer 更新、插件 migration 路径、`--pretend`、缓存清理和升级后的验收说明。
- [ ] 明确 refresh/access token 不能写入日志、CSV 或违规快照。

### 本地最小验证

仅保留低成本、可重复的规则保护；不建立本地 SeAT 大数据 E2E、十万行压测或并行 agent 验证流程。

- [ ] 对修改的 PHP 文件执行语法检查及项目已有的轻量检查。
- [ ] 建立必要的规则级测试：5M 门槛、成员 XOR、来源键稳定性、镜像幂等、dry-run 不写 violation/cursor。
- [ ] 对旧 wallet/contracts 执行针对性回归检查，确认没有改变其审计类型、金额或白名单语义。

### 测试服务器验收

部署后再验证真实数据、索引、并发、ESI 与页面交互。每次连接前先明确测试环境；migration、正式扫描或其他写操作仍须单独确认。

1. [ ] 只读统计成员数、捐赠镜像分布、低价合同候选和现有来源键重复；对候选索引执行 `EXPLAIN`。
2. [ ] 执行插件 migration `--pretend`；获得写操作确认后再执行实际 migration。
3. [ ] 分别对 donations、member-contracts 运行 dry-run，并以独立 SQL 对账成员 XOR、镜像、`audit_from`、`price < 5,000,000` 与 `reward` 边界。
4. [ ] 获得写操作确认后正式扫描；重复运行相同命令时新增必须为 0，同时确认旧 wallet/contracts 无异常变化。
5. [ ] 验收新旧页面、筛选、详情、CSV、Unknown resolver 与权限边界；确认 CSV 可由 Excel/WPS 正确打开。

## 完成标准

- [ ] `isk_donations` 与 `member_contracts` 按既定成员边界、白名单和金额规则正确入库。
- [ ] 多军团、`audit_from`、镜像、重试、回扫与并发不会产生新的重复 violation。
- [ ] 旧 wallet 和监控物品 contracts 的语义、兼容入口与查询结果不回归。
- [ ] Command、管理页面、独立新旧列表、详情、Unknown resolver 与 CSV 覆盖各自应支持的审计类型。
- [ ] CSV 按当前筛选导出，不全量加载、不生成同步 XLSX 临时文件，并已处理公式注入风险。
- [ ] 测试服务器的 dry-run、正式扫描、SQL 对账和 UI/CSV 验收全部通过。
