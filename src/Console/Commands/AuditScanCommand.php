<?php

// src/Console/Commands/AuditScanCommand.php
// 手动触发钱包交易与合同审计扫描的 Artisan 命令

namespace Seat\SeatAuditMonitor\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use Seat\SeatAuditMonitor\Enums\AuditType;
use Seat\SeatAuditMonitor\Jobs\AuditContractsJob;
use Seat\SeatAuditMonitor\Jobs\AuditWalletTransactionsJob;

class AuditScanCommand extends Command
{
    /**
     * Artisan 命令签名
     */
    protected $signature = 'seat:audit:scan
        {--type=all : 审计类型，可选值：wallet, contracts, all}
        {--since= : 临时回扫起点（仅对 contracts 类型生效），格式 YYYY-MM-DD 或 YYYY-MM-DD HH:mm:ss；使用时本次扫描不推进水位线，重复事件由 source_event_key 自动去重}';

    /**
     * 命令描述
     */
    protected $description = '手动触发钱包交易与合同增量审计扫描';

    public function handle()
    {
        // 读取命令行审计类型选项，并严格限制可选值，避免误传参数导致静默跳过任务。
        // 命令行仍保留 wallet/contracts 短别名；其实际审计类型和值由 AuditType enum 集中提供。
        $type = $this->option('type');
        $allowedTypes = ['wallet', 'contracts', 'all'];

        if (! in_array($type, $allowedTypes, true)) {
            $this->error('无效的审计类型，可选值：' . implode(', ', $allowedTypes));

            return 1;
        }

        // 解析可选 --since（合同审计临时回扫起点）。
        // 仅在 contracts/all 模式下生效；wallet 模式忽略并提示。
        // 旧版本提示“可能产生重复 violation”，现在 source_event_key 唯一索引已负责幂等去重。
        $sinceOverride = null;
        $sinceRaw = $this->option('since');

        if ($sinceRaw !== null && $sinceRaw !== '') {
            try {
                $sinceOverride = Carbon::parse($sinceRaw);
            } catch (\Throwable $e) {
                $this->error('无法解析 --since 时间："' . $sinceRaw . '"，请用 YYYY-MM-DD 或 YYYY-MM-DD HH:mm:ss 格式。');

                return 1;
            }

            if ($type === 'wallet') {
                $this->warn('--since 仅对' . AuditType::Contracts->label() . '审计生效；当前 type=wallet 已忽略该参数。');
            }
        }

        // 钱包交易审计沿用原有同步执行方式，手动触发时直接在当前进程完成扫描。
        if ($type === 'wallet' || $type === 'all') {
            $this->info('开始' . AuditType::WalletTransactions->label() . '审计...');
            Bus::dispatchSync(new AuditWalletTransactionsJob());
            $this->info(AuditType::WalletTransactions->label() . '审计完成。');
        }

        // 合同审计独立于钱包交易水位线，可单独执行，也可在 all 模式下顺序执行。
        if ($type === 'contracts' || $type === 'all') {
            if ($sinceOverride !== null) {
                $this->warn(AuditType::Contracts->label() . '审计将从 ' . $sinceOverride->toDateTimeString() . ' 起一次性回扫；本次不推进水位线，重复事件会自动忽略。');
            }
            $this->info('开始' . AuditType::Contracts->label() . '审计...');
            Bus::dispatchSync(new AuditContractsJob($sinceOverride));
            $this->info(AuditType::Contracts->label() . '审计完成。');
        }

        $this->info('全部审计任务完成。');

        return 0;
    }
}
