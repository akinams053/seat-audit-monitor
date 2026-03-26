<?php

// src/Console/Commands/AuditScanCommand.php
// 手动触发审计扫描的 Artisan 命令

namespace Seat\SeatAuditMonitor\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use Seat\SeatAuditMonitor\Jobs\AuditWalletTransactionsJob;

class AuditScanCommand extends Command
{
    /**
     * Artisan 命令签名
     */
    protected $signature = 'seat:audit:scan';

    /**
     * 命令描述
     */
    protected $description = '手动触发钱包交易增量审计扫描';

    public function handle()
    {
        $this->info('开始执行钱包交易审计扫描...');

        // 同步执行 Job（手动触发时直接在当前进程运行，无需队列）
        Bus::dispatchSync(new AuditWalletTransactionsJob());

        $this->info('审计扫描完成。');
    }
}
