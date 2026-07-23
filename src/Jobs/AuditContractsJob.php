<?php

// src/Jobs/AuditContractsJob.php
// 核心增量审计 Job，扫描已完成合同记录，检测包含监控物品的违规合同

namespace Seat\SeatAuditMonitor\Jobs;

use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Seat\SeatAuditMonitor\Enums\AuditType;
use Seat\SeatAuditMonitor\Models\AuditStatus;
use Seat\SeatAuditMonitor\Services\Audit\SourceEventKeyFactory;
use Seat\SeatAuditMonitor\Services\Audit\ViolationWriter;

class AuditContractsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * 每批处理的合同数量，避免一次性加载过多合同和物品明细。
     */
    const CHUNK_SIZE = 500;

    /**
     * 一次性回扫起点（覆盖水位线）。
     * 默认 null，按常规增量从 seat_audit_status.last_completed_at 推进。
     * 由调用方通过构造函数注入，例如 AuditScanCommand --since 选项；
     * 使用此参数时本次扫描**不会推进水位线**，避免临时回扫破坏正常增量进度。
     */
    public readonly ?Carbon $sinceOverride;

    public function __construct(?Carbon $sinceOverride = null)
    {
        $this->sinceOverride = $sinceOverride;
    }

    public function handle()
    {
        // 步骤1：决定本次扫描起点。
        // - 若调用方注入了 sinceOverride（如 --since 命令行回扫），优先使用该值，且本次不推进水位线
        // - 否则按常规从水位线 last_completed_at 增量扫描；首次运行（无水位线行）会兜底到 Unix 纪元
        //   注：本插件 migration 已预置 contracts 基线为 2026-01-01，正常情况下不会落入兜底分支
        $lastCompletedAt = $this->sinceOverride
            ?? AuditStatus::getLastCompletedAt(AuditType::Contracts->value)
            ?? Carbon::createFromTimestamp(0);

        // 统一幂等键工厂和写入器：旧监控物品合同也开始写 source_event_key，
        // 让 --since 回扫、任务重试或并发情况下依赖唯一索引自动去重。
        $sourceEventKeyFactory = new SourceEventKeyFactory();
        $violationWriter = new ViolationWriter();

        // 步骤2：预加载白名单角色 ID，并翻转为哈希表。
        // 合同审计要求 issuer / acceptor 任意一方命中白名单就跳过整份合同。
        $whitelistIds = array_flip(
            DB::table('seat_audit_whitelist')
                ->pluck('character_id')
                ->toArray()
        );

        // 步骤2.5：预加载军团白名单 — 仅用于合同审计的额外拦截。
        // 拦截规则：issuer 军团 AND acceptor 当前所属军团都在军团白名单时跳过整份合同。
        // 钱包审计不受此名单影响（按 §4.4 设计决策）。
        $corporationWhitelistIds = array_flip(
            DB::table('seat_audit_corporation_whitelist')
                ->pluck('corporation_id')
                ->toArray()
        );

        // 步骤3：预加载监控物品映射（type_id => item_name）。
        // 后续在 contract_items 中只拉取命中这些 type_id 的物品，降低扫描开销。
        $monitoredTypeIds = DB::table('seat_audit_monitor_items')
            ->pluck('item_name', 'type_id')
            ->toArray();

        // 没有配置监控物品时，合同不可能形成违规，直接退出且不推进水位线。
        if (empty($monitoredTypeIds)) {
            return;
        }

        // 步骤4：预加载角色名称快照。
        // details.parties 需要记录三方角色名，缺失时使用 Unknown (ID: X) 兜底。
        $characterNames = DB::table('character_infos')
            ->pluck('name', 'character_id')
            ->toArray();

        // 记录本次扫描处理到的最大完成时间，扫描结束后统一回写水位线。
        $maxCompletedAt = $lastCompletedAt;

        // 步骤5：按 date_completed 增量扫描已完成的物品交换和拍卖合同。
        // 查询条件只包含 status=finished，避免 outstanding 后续取消导致的复杂回退问题。
        DB::table('contract_details')
            ->where('status', 'finished')
            ->whereIn('type', ['item_exchange', 'auction'])
            ->where('date_completed', '>', $lastCompletedAt->toDateTimeString())
            ->whereNotNull('date_completed')
            ->orderBy('date_completed', 'asc')
            ->orderBy('contract_id', 'asc')
            ->chunk(self::CHUNK_SIZE, function ($contracts) use (
                $whitelistIds,
                $corporationWhitelistIds,
                $monitoredTypeIds,
                $characterNames,
                $sourceEventKeyFactory,
                $violationWriter,
                &$maxCompletedAt
            ) {
                // 步骤6a：收集当前 chunk 的合同 ID，用于一次性查询物品明细。
                $contractIds = $contracts->pluck('contract_id')->toArray();

                if (empty($contractIds)) {
                    return;
                }

                // 步骤6a-2：本批 acceptor 当前所属军团 ID 映射（character_id => corporation_id）
                // 仅拉取本批合同的 acceptor，避免一次性把全表 affiliations 搬进 PHP。
                // issuer corp 直接从合同表的 issuer_corporation_id 字段拿，无需 affiliations 查询。
                $acceptorIds = $contracts->pluck('acceptor_id')->filter()->unique()->values()->toArray();
                $acceptorCorpMap = empty($acceptorIds)
                    ? []
                    : DB::table('character_affiliations')
                        ->whereIn('character_id', $acceptorIds)
                        ->pluck('corporation_id', 'character_id')
                        ->toArray();

                // 步骤6b：只拉取本批合同中命中监控 type_id 的物品行。
                // 这里在 SQL 层完成 contract_id 和 type_id 双重过滤，避免把无关明细搬进 PHP。
                $items = DB::table('contract_items')
                    ->whereIn('contract_id', $contractIds)
                    ->whereIn('type_id', array_keys($monitoredTypeIds))
                    ->get();

                // 将物品行按 contract_id 分组，并在同一合同内按 type_id 聚合数量。
                // 产品口径：一个 (contract_id, type_id) 一条违规。但 EVE 合同中同一 type_id 可能
                // 出现多个 record（典型场景：is_singleton 装配舰船每艘单独成行），需把 quantity
                // 加总后落到 details.item.quantity，避免审计追溯时丢失全量信息。
                // 同时记录 record_ids 列表，便于回查原始明细。
                $itemsByContract = [];

                foreach ($items as $item) {
                    $bucket = $itemsByContract[$item->contract_id][$item->type_id] ?? null;

                    if ($bucket === null) {
                        // 首次出现：拷贝物品行作为快照，把 quantity 转 int 便于后续累加。
                        $itemsByContract[$item->contract_id][$item->type_id] = (object) [
                            'record_id'   => $item->record_id,
                            'type_id'     => $item->type_id,
                            'quantity'    => (int) $item->quantity,
                            'is_included' => $item->is_included,
                            'record_ids'  => [$item->record_id],
                        ];
                        continue;
                    }

                    // 后续同 type_id 的 record：累加数量并追加 record_id。
                    $bucket->quantity += (int) $item->quantity;
                    $bucket->record_ids[] = $item->record_id;
                }

                // 本批次待插入的违规记录集合；一个 (contract_id, type_id) 对应一条违规。
                $violations = [];

                foreach ($contracts as $contract) {
                    // date_completed 从 DB::table() 读取时通常是字符串，统一 parse 成 Carbon 再比较和回写。
                    $completedAt = Carbon::parse($contract->date_completed);

                    if ($completedAt->gt($maxCompletedAt)) {
                        $maxCompletedAt = $completedAt;
                    }

                    // 过滤步骤①：白名单综合判定。两套白名单语义不同：
                    //  - 角色白名单：任一方在角色白名单（issuer OR acceptor） → 跳过（单方拦截）
                    //  - 军团白名单：发起方军团 AND 接收方军团 都在军团白名单 → 跳过（双方都内部互转才豁免）
                    // 综合：任一条件成立即跳过。assignee 不参与新语义（acceptor 已覆盖私下合同；公开/corp 合同 assignee 不是 character）。
                    $acceptorCorp = $contract->acceptor_id
                        ? ($acceptorCorpMap[$contract->acceptor_id] ?? null)
                        : null;

                    $issuerCharWhitelisted = isset($whitelistIds[$contract->issuer_id]);
                    $acceptorCharWhitelisted = isset($whitelistIds[$contract->acceptor_id]);
                    $issuerCorpWhitelisted = isset($corporationWhitelistIds[$contract->issuer_corporation_id]);
                    $acceptorCorpWhitelisted = $acceptorCorp !== null
                        && isset($corporationWhitelistIds[$acceptorCorp]);

                    $exempt = $issuerCharWhitelisted
                        || $acceptorCharWhitelisted
                        || ($issuerCorpWhitelisted && $acceptorCorpWhitelisted);

                    if ($exempt) {
                        continue;
                    }

                    // 过滤步骤②：若该合同没有任何命中监控 type_id 的物品，则不是违规合同。
                    $matchedItems = $itemsByContract[$contract->contract_id] ?? [];

                    if (empty($matchedItems)) {
                        continue;
                    }

                    // 合同金额按产品决策取 price / reward 二者较大值，使用 decimal 字符串比较，避免转 float。
                    $amount = $this->maxDecimalString($contract->price, $contract->reward);

                    // parties 快照保存三方名称，避免后续 character_infos 变化影响历史审计记录。
                    // 三方 ID 在边角情况下可能为空（公开合同的 assignee 等），空 ID 不构造「Unknown (ID: )」字符串，
                    // 保留 null 以便上层渲染统一兜底为 '-'。
                    $issuerName = $contract->issuer_id
                        ? ($characterNames[$contract->issuer_id] ?? 'Unknown (ID: ' . $contract->issuer_id . ')')
                        : null;
                    $assigneeName = $contract->assignee_id
                        ? ($characterNames[$contract->assignee_id] ?? 'Unknown (ID: ' . $contract->assignee_id . ')')
                        : null;
                    $acceptorName = $contract->acceptor_id
                        ? ($characterNames[$contract->acceptor_id] ?? 'Unknown (ID: ' . $contract->acceptor_id . ')')
                        : null;

                    foreach ($matchedItems as $item) {
                        // 违规粒度为一个 (contract_id, type_id) 一条记录。
                        // 若同一合同包含多种监控物品，将分别落库，便于后续按物品筛选和统计。
                        $violations[] = [
                            'source_event_key' => $sourceEventKeyFactory->monitoredContract(
                                $contract->contract_id,
                                $item->type_id
                            ),
                            'source_reference' => 'contract:' . $contract->contract_id,
                            'character_id'     => $contract->issuer_id,
                            'character_name'   => $issuerName,
                            // 接收方快照：合同 finished 时 acceptor 必为 character ID，对应 character_infos.name 取出的名字。
                            // 与 character_id 一起用于查询层白名单软过滤（任一在白名单中则 UI 实时排除）。
                            'counterparty_id'   => $contract->acceptor_id,
                            'counterparty_name' => $acceptorName,
                            'type_id'           => $item->type_id,
                            'item_name'         => $monitoredTypeIds[$item->type_id],
                            'amount'            => $amount,
                            'violation_time'    => $completedAt->toDateTimeString(),
                            'details'           => [
                                'contract' => [
                                    'contract_id'    => $contract->contract_id,
                                    'type'           => $contract->type,
                                    'status'         => $contract->status,
                                    'issuer_id'      => $contract->issuer_id,
                                    'assignee_id'    => $contract->assignee_id,
                                    'acceptor_id'    => $contract->acceptor_id,
                                    'price'          => $contract->price,
                                    'reward'         => $contract->reward,
                                    'date_issued'    => $contract->date_issued,
                                    'date_completed' => $contract->date_completed,
                                    'title'          => $contract->title,
                                ],
                                'item' => [
                                    // 同 type_id 多 record 已聚合：quantity 是合计，record_ids 列出所有原始明细。
                                    'record_id'   => $item->record_id,
                                    'record_ids'  => $item->record_ids,
                                    'type_id'     => $item->type_id,
                                    'quantity'    => $item->quantity,
                                    'is_included' => $item->is_included,
                                ],
                                'parties' => [
                                    'issuer_name'   => $issuerName,
                                    'assignee_name' => $assigneeName,
                                    'acceptor_name' => $acceptorName,
                                ],
                            ],
                            'audit_type'      => AuditType::Contracts->value,
                            'contract_id'     => $contract->contract_id,
                            // availability 快照：public / personal / corporation / alliance。
                            // 用于 UI 来源列细分；details JSON 不再单独冗余存储。
                            'contract_availability'      => $contract->availability,
                            // 保存扫描时双方军团快照，供后续新页面或审计详情追溯当时判定依据。
                            'character_corporation_id'   => $contract->issuer_corporation_id,
                            'counterparty_corporation_id' => $acceptorCorp,
                            'created_at'                 => now()->toDateTimeString(),
                        ];
                    }
                }

                // 本批次有违规记录才批量幂等写入，减少数据库往返次数并自动忽略重复 source_event_key。
                if (!empty($violations)) {
                    $violationWriter->insertOrIgnore($violations);
                }
            });

        // 步骤7：扫描完成后推进 date_completed 水位线。
        // 仅在常规增量模式下推进（sinceOverride=null）；--since 临时回扫保留原水位线，
        // 避免一次性回扫的进度污染正常增量轨迹。
        if ($this->sinceOverride === null && $maxCompletedAt->gt($lastCompletedAt)) {
            AuditStatus::setLastCompletedAt(AuditType::Contracts->value, $maxCompletedAt);
        }
    }

    /**
     * 在不转 float 的情况下比较两个 decimal 字符串，返回较大的规范金额字符串。
     */
    private function maxDecimalString(int|string|null $left, int|string|null $right): string
    {
        $left = $this->normalizeDecimalString($left);
        $right = $this->normalizeDecimalString($right);

        return $this->compareDecimalStrings($left, $right) >= 0 ? $left : $right;
    }

    /**
     * 将数据库 decimal 值规范化为至少两位小数的字符串，空值按 0.00 处理。
     */
    private function normalizeDecimalString(int|string|null $value): string
    {
        $value = trim((string) ($value ?? '0'));

        if (preg_match('/^[0-9]+(?:\.[0-9]+)?$/', $value) !== 1) {
            throw new \InvalidArgumentException('合同 price/reward 不是有效 decimal。');
        }

        [$integer, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $integer = ltrim($integer, '0') ?: '0';
        $fraction = rtrim($fraction, '0');

        return $fraction === '' ? $integer . '.00' : $integer . '.' . $fraction;
    }

    /**
     * 比较两个非负 decimal 字符串；返回 1 表示 left 大，-1 表示 right 大，0 表示相等。
     */
    private function compareDecimalStrings(string $left, string $right): int
    {
        [$leftInteger, $leftFraction] = array_pad(explode('.', $left, 2), 2, '');
        [$rightInteger, $rightFraction] = array_pad(explode('.', $right, 2), 2, '');

        $integerCompare = strlen($leftInteger) <=> strlen($rightInteger);
        if ($integerCompare !== 0) {
            return $integerCompare;
        }

        $integerCompare = strcmp($leftInteger, $rightInteger);
        if ($integerCompare !== 0) {
            return $integerCompare <=> 0;
        }

        $scale = max(strlen($leftFraction), strlen($rightFraction));
        $leftFraction = str_pad($leftFraction, $scale, '0');
        $rightFraction = str_pad($rightFraction, $scale, '0');
        $fractionCompare = strcmp($leftFraction, $rightFraction);

        return $fractionCompare <=> 0;
    }
}
