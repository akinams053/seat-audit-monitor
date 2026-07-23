# 军团审查 2.0 — 第一阶段 TODO

> 目标：在保留现有违规物品市场/合同审计语义的基础上，新增“军团成员与非成员之间的 ISK 捐赠、已完成合同”审查。
>
> 当前阶段只实施经济交易审查；SeAT 令牌、入团时间、最近上线时间属于第二阶段独立成员状态模块。

## 已确认的业务决策

- [x] 受审成员以 `corporation_members(corporation_id, character_id)` 当前名册为准。
- [x] 第一期只审查功能启用时间之后的事件，不回扫启用前历史，避免当前名册误判历史入退团关系。
- [x] 新的成员合同审查全部 finished `item_exchange` / `auction`，不要求命中监控物品。
- [x] 新的成员合同仅记录 `contract_details.price < 5,000,000` 的合同；等于 5M 不记录，`reward` 不参与门槛判断。
- [x] 新的成员合同记录中以 `price` 作为页面主金额，同时在详情中保留原始 `reward`。
- [x] 原 `contracts` 继续表示“包含监控物品的合同”，不改变历史语义。
- [x] 所有不在目标军团当前名册中的实体均视为外部方，包括角色、军团、联盟和 Unknown 实体。
- [x] 新策略只豁免可信外部方；受审成员本人命中角色白名单时仍继续审查。
- [x] 支持多个审计军团，每个军团独立判断成员边界。
- [x] 初始目标军团来自现有排查表：`98588384`，但不得在 Job 中硬编码。
- [x] 数据导出统一优先使用 UTF-8 BOM 的流式 CSV；不在 Web 请求内生成 XLSX，以降低服务器内存、CPU 和临时文件压力。

## 已完成的探索

- [x] 梳理现有 `AuditWalletTransactionsJob`、`AuditContractsJob`、Command、Controller、UI、CSV 和 migration。
- [x] 确认现有合同扫描并未使用 `corporation_members`，不属于严格的成员对外合同审查。
- [x] 检查 `seat排查简易.xlsx`，确认第二阶段可用数据源：
  - `corporation_members`
  - `corporation_member_trackings`
  - `refresh_tokens` 聚合
  - `character_infos` / `universe_names`
- [x] 在测试服务器只读确认 `character_wallet_journals` 结构。
- [x] 确认测试服存在 32,930 条 `player_donation`。
- [x] 确认 `player_donation` 的 `first_party_id`、`second_party_id`、`amount`、`date` 均可用，金额存在正负镜像。
- [x] 确认 journal 表没有 `first_party_type` / `second_party_type`。
- [x] 确认 journal 唯一键为 `(character_id, id)`。
- [x] 确认 `corporation_members` 主键为 `(corporation_id, character_id)`。

---

## 阶段 1：数据库基础和审计类型

### 1.1 审计军团

- [x] 新增 `seat_audit_corporations` migration。
- [x] 字段包含：
  - `corporation_id`，唯一；
  - `corporation_name` 快照；
  - `enabled`；
  - `audit_donations`；
  - `audit_contracts`；
  - `audit_from`；
  - timestamps。
- [x] 新增 `AuditCorporation` 模型，继承 `ExtensibleModel`。
- [x] 初始化或通过管理页加入军团 `98588384`。
- [x] 停用使用 `enabled=false`，不物理删除历史范围。

### 1.2 新扫描游标

- [x] 新增 `seat_audit_scan_cursors` migration。
- [x] 按 `(scanner, audit_corporation_id)` 建唯一约束。
- [x] 保存：
  - `cursor_at`；
  - `cursor_id`；
  - `cursor_sub_id`；
  - `last_started_at`；
  - `last_succeeded_at`；
  - timestamps。
- [x] 旧全局策略使用 scope `0`，不要使用 NULL。
- [x] 新增 `AuditCursor` 模型和 `CursorRepository`。
- [x] 保留 `seat_audit_status`，本阶段不删除，支持旧任务和切换回滚。

### 1.3 扩展违规表

- [x] 新增 `source_event_key CHAR(64) NULL`。
- [x] 新增 `source_reference`。
- [x] 新增 `audit_corporation_id`。
- [x] 新增 `member_character_id`。
- [x] 新增 `external_party_id`。
- [x] 新增 `external_party_type`。
- [x] 新增 `direction`。
- [x] 新增 `character_corporation_id` 和 `counterparty_corporation_id` 快照。
- [x] 将 `type_id`、`item_name` 改为 nullable，允许表示纯 ISK 和整份合同事件。
- [x] 保持金额为 decimal 精度，业务代码避免转 float。
- [x] 更新 `Violation` 的 fillable/casts。
- [x] 增加索引：
  - unique `source_event_key`；
  - `(audit_type, violation_time)`；
  - `(audit_corporation_id, audit_type, violation_time)`；
  - `member_character_id`；
  - `external_party_id`。

### 1.4 旧数据幂等键

- [x] 钱包旧记录键：`wallet_transactions|character_id|source_transaction_id`。
- [x] 合同旧记录键：`contracts|contract_id|type_id`。
- [x] 历史重复组只给最早一条写入规范键，其余历史记录保留。
- [x] 无法解析源 ID 的旧记录保留 `source_event_key=NULL` 并输出统计。
- [x] 不在 migration 中自动删除任何历史违规。

### 1.5 集中审计类型

- [x] 新增 PHP 8.1 enum 或 registry：
  - `wallet_transactions`
  - `contracts`
  - `isk_donations`
  - `member_contracts`
- [x] Command、Controller、Blade、CSV 和 resolver 共用该定义，避免重复硬编码。

---

## 阶段 2：成员范围与共享运行上下文

- [ ] 新增 `MembershipProvider` 接口。
- [ ] 新增 `CurrentCorporationMembersProvider`，从 `corporation_members` 批量读取当前成员。
- [ ] 接口保留事件时间参数，为第二阶段历史成员实现预留替换点。
- [ ] 记录成员判断模式：`current_roster_at_scan`。
- [ ] 新增 `AuditRunContext` / `AuditRunContextFactory`。
- [ ] 每次运行只加载一次：
  - 启用的审计军团；
  - 各军团当前成员集合；
  - 角色白名单；
  - 军团白名单；
  - 监控物品。
- [ ] 新增 `EntitySnapshotResolver`。
- [ ] 名称仅按当前 chunk 的参与方 ID 批量查询：
  - `character_infos`
  - `universe_names`
  - `character_affiliations`
  - `corporation_infos`
- [ ] 不再为每个 Job 全量 `pluck()` 整张 `character_infos`。
- [ ] Unknown 实体不得阻塞扫描，后续通过 ESI 补全。

---

## 阶段 3：统一幂等写入

- [x] 新增 `SourceEventKeyFactory`。
- [x] 新增 `ViolationWriter`。
- [ ] 所有策略统一通过 `insertOrIgnore()` 或等价实现写入。
- [x] 返回准确的 inserted/duplicate 数量。
- [ ] 每个 chunk 在事务内完成：
  1. 规范化源事件；
  2. 执行策略；
  3. 幂等写入；
  4. 更新 cursor；
  5. 提交。
- [ ] chunk 任一步骤失败时违规记录和 cursor 一起回滚。

---

## 阶段 4：ISK 捐赠扫描

### 4.1 数据规则

- [ ] 新增 `DonationJournalScanner`。
- [ ] 只扫描 `ref_type='player_donation'`。
- [ ] 规范化：
  - `donor_id = first_party_id`；
  - `recipient_id = second_party_id`；
  - `amount = abs(raw_amount)`。
- [ ] 不根据金额正负交换 donor/recipient。
- [ ] 对每个审计军团做 XOR 判断：
  - 双方成员：internal，不记录；
  - 双方非成员：unrelated，不记录；
  - donor 为成员：`outbound`；
  - recipient 为成员：`inbound`。
- [ ] `character_id` / `counterparty_id` 保持 donor / recipient 方向。
- [ ] 单独保存 `member_character_id` 和 `external_party_id`。

### 4.2 镜像去重

- [ ] chunk 内按 journal event 合并双方正负镜像。
- [ ] 自然键：`isk_donations|audit_corporation_id|journal_id|first_party_id|second_party_id`。
- [ ] 生成 SHA-256 `source_event_key`。
- [ ] 支持只有单边 journal 的情况。
- [ ] 跨 chunk、任务重试、临时回扫均由唯一键去重。

### 4.3 异常数据

- [ ] 以下情况跳过并计入 invalid：
  - party 缺失；
  - 双方 ID 相同；
  - amount 为零；
  - 当前 journal `character_id` 不属于任一 party；
  - 镜像金额绝对值不一致。
- [ ] 记录结构化日志和计数，不猜测修正。

### 4.4 白名单

- [ ] 只对外部方应用豁免。
- [ ] 外部角色命中角色白名单时豁免。
- [ ] 受审成员本人命中角色白名单时不豁免。
- [ ] 外部实体类型未知时先记录，不能因无法分类而漏审。

---

## 阶段 5：合同源扫描重构

### 5.1 单次源查询

- [ ] 新增 `ContractScanner`。
- [ ] 一次读取 `contract_details`，同时应用两个 policy：
  - `MonitoredItemContractPolicy`；
  - `ExternalMemberContractPolicy`。
- [ ] 当前 chunk 的 `contract_items` 只查询一次。
- [ ] 旧 `contracts` 仍按 `(contract_id, type_id)` 拆分。
- [ ] 新 `member_contracts` 每个 `(audit_corporation_id, contract_id)` 只写一条。

### 5.2 成员合同规则

- [ ] 只处理：
  - `status='finished'`；
  - `type IN ('item_exchange','auction')`；
  - `date_completed IS NOT NULL`；
  - `date_completed >= audit_from`；
  - `price < 5,000,000`，严格小于 5M，等于 5M 不记录。
- [ ] 5M 门槛只用于新 `member_contracts` policy，不影响旧 `contracts` 监控物品策略。
- [ ] `reward` 不参与 5M 门槛判断；即使 reward 高于 5M，只要 price 低于 5M 仍可记录。
- [ ] 只以 issuer/acceptor 判断成员边界。
- [ ] issuer 成员、acceptor 外部：`member_initiated`。
- [ ] issuer 外部、acceptor 成员：`member_accepted`。
- [ ] 双方成员：internal，不记录。
- [ ] 双方外部：unrelated，不记录。
- [ ] `assignee_id`、`availability`、`issuer_corporation_id`、`is_included` 只保存在详情，不用于成员资格判断。
- [ ] `details.items` 保存完整合同物品列表。
- [ ] 新 `member_contracts.amount` 保存合同 `price`，UI 明确显示“合同价格”。
- [ ] `details.contract` 同时保留原始 `price`、`reward`，便于追溯；旧 `contracts` 的金额口径保持不变。

### 5.3 新合同白名单

- [ ] 只对外部方应用白名单。
- [ ] 外部角色命中角色白名单时豁免。
- [ ] 外部角色/实体所属军团命中军团白名单时豁免。
- [ ] 受审成员命中白名单时不豁免。
- [ ] 旧 `contracts` 保持当前角色 OR、军团双方 AND 的历史规则。

### 5.4 旧合同水位线切换

- [ ] 首次启用新 scanner 时读取旧 `seat_audit_status.contracts.last_completed_at`。
- [ ] 先补齐旧策略到切换点，再建立新 contract cursor。
- [ ] 新 `member_contracts` 只从对应军团 `audit_from` 开始。
- [ ] 切换完成前不删除旧状态。

---

## 阶段 6：游标、迟到数据与索引

- [ ] donation cursor 使用 `created_at, id, character_id`。
- [ ] contract cursor 使用 `updated_at, contract_id`。
- [ ] 默认 overlap 10 分钟。
- [ ] 业务范围继续以 `date` / `date_completed` 与 `audit_from` 判断。
- [ ] 启用日前事件之后才同步到 SeAT 时仍应排除。
- [ ] 启用日后事件延迟同步时应被 overlap 捕获。
- [ ] 在测试服先执行 `EXPLAIN`：
  - journal 候选索引 `(ref_type, created_at, id, character_id)`；
  - contract 候选索引 `(status, type, updated_at, contract_id)`。
- [ ] 只有确认查询收益和建索引影响后，才新增 SeAT 源表索引 migration。

---

## 阶段 7：统一 Orchestrator 与兼容入口

- [ ] 新增 `AuditOrchestrator`。
- [ ] 使用 Laravel `Cache::lock('seat-audit-monitor:scan', ...)` 防止并发。
- [ ] 新增统一结果对象，至少返回：
  - seen；
  - normalized；
  - inserted；
  - duplicate；
  - internal；
  - unrelated；
  - exempted；
  - invalid；
  - cursor from/to。
- [ ] 保留 `AuditWalletTransactionsJob` 作为兼容包装。
- [ ] 保留 `AuditContractsJob` 作为兼容包装。
- [ ] 新增 `AuditDonationsJob`。
- [ ] 新增 `RunAuditScanJob`。
- [ ] 修改 `AuditScanCommand`，支持：
  - `wallet`
  - `donations`
  - `contracts`
  - `member-contracts`
  - `all`
  - `--corporation`
  - `--since`
  - `--dry-run`
- [ ] `--since` 和 `--dry-run` 不推进正式 cursor。
- [ ] Web 手动扫描使用 orchestrator 返回计数，不再使用全表 count 前后差值。

---

## 阶段 8：审计军团管理与权限

- [ ] 新增 `AuditCorporationController`。
- [ ] 新增 `admin/audit-corporations.blade.php`。
- [ ] 支持：
  - 添加军团；
  - 启用/停用；
  - donation 策略开关；
  - member contract 策略开关；
  - 设置 `audit_from`。
- [ ] 修改 routes 和 sidebar，增加“审计军团”管理入口。
- [ ] 将侧边栏固定为以下结构：
  - `物品违规审查`：现有违规记录页面，只显示旧 `wallet_transactions` / `contracts`；
  - `军团对外审查`：新增独立子页面，只显示 `isk_donations` / `member_contracts`；
  - `监控物品`：保留现有共用管理页面；
  - `白名单`：保留现有共用管理页面。
- [ ] 原侧边栏“违规记录”重命名为“物品违规审查”。
- [ ] 新增独立“军团对外审查”路由和侧边栏子项，不在旧页面内用标签混合展示。
- [ ] 所有管理路由执行服务端 `seat-audit-monitor.admin` Gate 校验。
- [ ] 顺带修正现有 AdminController 只隐藏菜单、未统一做服务端 Gate 的问题。

---

## 阶段 9：违规查询、UI 与 CSV

### 9.1 页面和 Controller 边界

- [ ] 保留现有 `ViolationController` 和现有页面作为“物品违规审查”，仅查询：
  - `wallet_transactions`；
  - `contracts`。
- [ ] 新增独立 `CorporationAuditController`（或同等清晰命名）和 `corporation-audits/index.blade.php`，仅查询：
  - `isk_donations`；
  - `member_contracts`。
- [ ] 两个页面使用独立路由、独立分页参数和独立 CSV 导出入口。
- [ ] 新增共享查询服务，复用实体名称、军团 JOIN、关键词、时间和白名单基础逻辑，但通过页面 scope 严格限制审计类型，防止新旧记录串页。
- [ ] 现有监控物品和白名单管理页保持唯一，不为新审查复制第二套管理页面。

### 9.2 物品违规审查页面

- [ ] 侧边栏名称由“违规记录”改为“物品违规审查”。
- [ ] 保持现有钱包交易、监控物品合同、合同详情和筛选行为。
- [ ] 继续使用共享监控物品和白名单。
- [ ] 不在该页面显示 `isk_donations` / `member_contracts`。

### 9.3 军团对外审查页面

- [ ] 侧边栏新增独立“军团对外审查”子栏。
- [ ] 页面只显示 ISK 捐赠和低价成员合同。
- [ ] 支持筛选：
  - 审计军团；
  - `isk_donations` / `member_contracts`；
  - direction；
  - 时间；
  - 角色/实体/军团关键词。
- [ ] 显示成员/外部 badge、审计军团和方向 badge。
- [ ] donation 摘要显示“ISK 捐赠”，数据库 `type_id/item_name` 仍为 NULL。
- [ ] 新增 Journal 详情 modal。
- [ ] `member_contracts` 显示“合同价格”，并对 `price < 5M` 规则提供明确提示。
- [ ] `member_contracts` modal 展示完整 items、price、reward 和 5M 命中依据。
- [ ] 页面提示成员身份按扫描时当前名册判断，且仅审查功能启用后的事件。
- [ ] 新策略查询层白名单只过滤外部方；旧页面继续保持原软过滤语义。

### 9.4 流式 CSV 导出

- [ ] 导出格式统一采用 UTF-8 BOM CSV，确保 Excel/WPS 可直接打开中文内容。
- [ ] 不在同步 Web 请求内生成 XLSX；XLSX 需要额外压缩、XML 组装、内存和临时文件，服务器压力明显高于 CSV。
- [ ] 旧“物品违规审查”CSV 保持兼容。
- [ ] 新增独立“军团对外审查”CSV，包含：
  - 审计军团；
  - 审计类型；
  - 成员角色名、角色 ID；
  - 外部实体名、实体 ID；
  - 外部实体类型；
  - 方向；
  - Journal/Contract ID；
  - 捐赠金额或合同 price；
  - 原始 reward；
  - 发生时间；
  - membership resolution；
  - 物品摘要或捐赠 reason（不默认导出整段 details JSON）。
- [ ] 只导出当前页面筛选条件对应的数据，筛选参数必须原样传递给导出路由。
- [ ] 使用 `response()->streamDownload()`/StreamedResponse 直接写 `php://output`，不先生成服务器临时文件。
- [ ] 使用 `chunkById` / `lazyById` 每批读取约 500～1,000 行，只 select 导出所需字段，不调用 `get()` 加载全部记录。
- [ ] 对可能以 `= + - @` 开头的角色名、reason 等文本做 CSV Formula Injection 防护。
- [ ] 每个页面与其对应 CSV 的筛选计数必须一致。
- [ ] 初期不提供 gzip/ZIP；若未来单次导出达到十万级以上，再改为后台队列生成 `.csv.gz`，避免长时间占用 PHP-FPM 请求。

---

## 阶段 10：ESI 名称与实体补全

- [ ] 扩展 `ResolveUnknownNamesJob`，处理 `isk_donations` 和 `member_contracts`。
- [ ] 同时解析双方 ID，不只处理合同 acceptor。
- [ ] 支持 character/corporation/alliance/unknown 分类。
- [ ] ESI 仅补名称、实体类型和当前 affiliation，不参与成员资格判断。
- [ ] 按现有模式每批最多 1,000 ID，并保留失败日志。
- [ ] 如重构收益明确，再抽取共享 `EsiEntityResolver`，供 Job 和 AdminController 复用。

---

## 阶段 11：自动化测试

- [ ] 建立 `tests/`、`phpunit.xml.dist` 和最小 SeAT 包测试结构。

### Donation

- [ ] donor 成员、recipient 外部、负金额镜像 → outbound 一条。
- [ ] 只有 recipient 正金额镜像也能正确识别。
- [ ] donor 外部、recipient 成员 → inbound 一条。
- [ ] 正负双镜像只产生一条。
- [ ] 镜像跨 chunk 仍只产生一条。
- [ ] 连续运行两次，第二次 inserted=0。
- [ ] 双方成员不记录。
- [ ] 双方外部不记录。
- [ ] Unknown/军团/联盟外部实体仍记录。
- [ ] 异常行进入 invalid。

### Contract

- [ ] issuer 成员、acceptor 外部 → member_initiated。
- [ ] issuer 外部、acceptor 成员 → member_accepted。
- [ ] 双方成员、双方外部均不记录。
- [ ] assignee 是成员但 issuer/acceptor 都不是时不记录。
- [ ] 非 finished、courier、loan 不记录。
- [ ] `price=4,999,999.99` 时符合低价门槛。
- [ ] `price=5,000,000` 和更高价格时，新 `member_contracts` 不记录。
- [ ] `price<5M` 且 `reward>5M` 时仍记录，证明 reward 不参与门槛。
- [ ] `price>=5M` 但命中监控物品时，旧 `contracts` 仍可正常记录。
- [ ] 多物品合同的新策略只产生一条并保存全部 items。
- [ ] 同一合同命中两个监控物品时：旧策略两条、新策略一条。
- [ ] 同一合同可同时命中两个策略，幂等键互不冲突。

### Cursor / migration / concurrency

- [ ] overlap 捕获迟到数据。
- [ ] chunk 失败时 cursor 不推进。
- [ ] dry-run 不写 violation 和 cursor。
- [ ] 旧违规完整保留。
- [ ] donation 可写 NULL `type_id/item_name`。
- [ ] 唯一键阻止重试和并发重复。
- [ ] Web 和 Command 并发时只有一个获得锁。
- [ ] CSV 导出 10 万行模拟数据时保持恒定低内存，不生成临时 XLSX 文件。
- [ ] CSV 中文在 Excel/WPS 中正常打开，危险公式前缀得到转义。
- [ ] 导出结果严格匹配页面筛选条件。

---

## 阶段 12：测试服务器验证

> 连接测试服务器已获得本轮只读授权；后续 migration、正式扫描等写操作仍需在执行前单独确认。

- [ ] 只读统计目标军团成员数、捐赠镜像分布、候选合同和现有重复违规。
- [ ] 抽样确认：
  - donor 侧 `character_id=first_party_id` 且金额为负；
  - recipient 侧 `character_id=second_party_id` 且金额为正；
  - 双边 journal ID/party 顺序/绝对金额一致。
- [ ] migration 执行前运行 `--pretend`。
- [ ] 新索引 migration 前运行 `EXPLAIN`。
- [ ] dry-run：
  - `donations --corporation=98588384 --dry-run`
  - `member-contracts --corporation=98588384 --dry-run`
- [ ] 用独立 XOR SQL 与 dry-run 结果对账。
- [ ] 获得写操作确认后正式扫描 donations。
- [ ] 同一命令再运行一次，新增必须为 0。
- [ ] 获得写操作确认后正式扫描 contracts/member-contracts。
- [ ] 检查旧合同违规数量没有异常变化。
- [ ] 验证启用日前事件不入库。
- [ ] 验证启用日后的迟到事件可捕获。
- [ ] 验证低价合同候选 SQL 严格使用 `price < 5,000,000`，并与 dry-run 对账。
- [ ] 验证 `price=5,000,000` 的边界合同不进入新页面。
- [ ] 验证侧边栏显示“物品违规审查 / 军团对外审查 / 监控物品 / 白名单”。
- [ ] 验证新旧审查分别进入独立页面和独立 CSV，不发生记录串页。
- [ ] 验证 UI、modal、CSV 和 Unknown resolver。
- [ ] 验证旧 wallet/contracts 功能没有回归。

---

## 阶段 13：文档与第二阶段边界

- [ ] 更新 README：审计类型、审计军团、调度、dry-run、幂等回扫和当前名册口径。
- [ ] 更新 README 安装/升级章节，补充 `dev-feature/corporation-audit-2.0` 测试分支安装、同分支 `composer update`、migration `--pretend`、插件路径 migration、缓存清理和升级验收步骤。
- [ ] 明确功能分支必须先 push 到远端仓库，测试/生产服务器的 Composer 才能拉取；未 push 时只能使用本地 path repository。
- [ ] 修改原有“`--since` 可能产生重复、需手工删除”的说明，改为幂等回扫说明。
- [ ] 记录第二阶段数据源：
  - 当前成员：`corporation_members`；
  - 入团/最近上线：`corporation_member_trackings`；
  - 令牌状态：`refresh_tokens` 聚合；
  - 名称：`character_infos` → `universe_names` → ESI。
- [ ] 第二阶段使用独立成员状态表、页面和权限。
- [ ] 任何日志、CSV 或快照都不得包含 refresh/access token 内容。

## 完成标准

- [ ] 新增 ISK 捐赠和低价成员对外合同两种审计类型。
- [ ] 新成员合同严格执行 `price < 5,000,000`，等于 5M 不记录，reward 不参与门槛。
- [ ] 新旧合同策略共用一次合同源扫描。
- [ ] 受审军团可配置且支持多军团。
- [ ] 新策略严格执行“一方为当前成员、另一方为非成员”。
- [ ] 启用日前事件不会进入新策略违规记录。
- [ ] 任务重试、镜像、回扫和并发不会产生新重复。
- [ ] 侧边栏包含独立“物品违规审查”和“军团对外审查”子栏，并共用“监控物品/白名单”管理页面。
- [ ] 新旧审查使用独立列表和独立 UTF-8 BOM 流式 CSV，导出过程不全量加载数据、不生成同步 XLSX 临时文件。
- [ ] UI、详情和 ESI 补全支持新类型。
- [ ] 旧钱包和旧监控物品合同行为保持兼容。
- [ ] 自动化测试通过。
- [ ] 测试服务器 dry-run 和正式验证结果与独立 SQL 对账一致。
