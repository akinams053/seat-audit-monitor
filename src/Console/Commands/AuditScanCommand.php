<?php

// /Users/akina/project/seat-audit-monitor/src/Console/Commands/AuditScanCommand.php
// 手动触发钱包交易与合同审计扫描的 Artisan 命令

namespace Seat\SeatAuditMonitor\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use Seat\SeatAuditMonitor\Jobs\AuditContractsJob;
use Seat\SeatAuditMonitor\Jobs\AuditWalletTransactionsJob;

class AuditScanCommand extends Command
{
    /**
     * Artisan 命令签名
     */
    protected $signature = 'seat:audit:scan {--type=all : 审计类型，可选值：wallet, contracts, all}';

    /**
     * 命令描述
     */
    protected $description = '手动触发钱包交易与合同增量审计扫描';

    public function handle()
    {
        // 读取命令行审计类型选项，并严格限制可选值，避免误传参数导致静默跳过任务。
        $type = $this->option('type');

        if (!in_array($type, ['wallet', 'contracts', 'all'], true)) {
            $this->error('无效的审计类型，可选值：wallet, contracts, all');

            return 1;
        }

        // 钱包交易审计沿用原有同步执行方式，手动触发时直接在当前进程完成扫描。
        if ($type === 'wallet' || $type === 'all') {
            $this->info('开始钱包交易审计...');
            Bus::dispatchSync(new AuditWalletTransactionsJob());
            $this->info('钱包交易审计完成。');
        }

        // 合同审计独立于钱包交易水位线，可单独执行，也可在 all 模式下顺序执行。
        if ($type === 'contracts' || $type === 'all') {
            $this->info('开始合同审计...');
            Bus::dispatchSync(new AuditContractsJob());
            $this->info('合同审计完成。');
        }

        $this->info('全部审计任务完成。');

        return 0;
    }
}
