<?php

// src/Console/Commands/AuditScanCommand.php
// 手动触发旧物品审计及固定军团 98588384 的 donation / 低价合同审计

namespace Seat\SeatAuditMonitor\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use Seat\SeatAuditMonitor\Enums\AuditType;
use Seat\SeatAuditMonitor\Jobs\AuditContractsJob;
use Seat\SeatAuditMonitor\Jobs\AuditDonationsJob;
use Seat\SeatAuditMonitor\Jobs\AuditMemberContractsJob;
use Seat\SeatAuditMonitor\Jobs\AuditWalletTransactionsJob;

class AuditScanCommand extends Command
{
    /**
     * --since 保持为旧监控物品合同的专用回扫参数；新军团审查只从部署时设置的 audit_from
     * 和各自 cursor 读取，避免把未经确认的历史成员行为写入新审计类型。
     */
    protected $signature = 'seat:audit:scan
        {--type=all : 审计类型，可选值：wallet, contracts, donations, member-contracts, all}
        {--since= : 临时回扫起点（仅对 contracts 类型生效），格式 YYYY-MM-DD 或 YYYY-MM-DD HH:mm:ss；使用时本次不推进水位线，重复事件由 source_event_key 自动去重}';

    protected $description = '手动触发钱包、监控物品合同、ISK 捐赠和成员低价合同审计';

    public function handle()
    {
        $type = $this->option('type');
        $allowedTypes = ['wallet', 'contracts', 'donations', 'member-contracts', 'all'];

        if (! in_array($type, $allowedTypes, true)) {
            $this->error('无效的审计类型，可选值：' . implode(', ', $allowedTypes));

            return 1;
        }

        $sinceOverride = null;
        $sinceRaw = $this->option('since');
        if ($sinceRaw !== null && $sinceRaw !== '') {
            try {
                $sinceOverride = Carbon::parse($sinceRaw);
            } catch (\Throwable) {
                $this->error('无法解析 --since 时间："' . $sinceRaw . '"，请用 YYYY-MM-DD 或 YYYY-MM-DD HH:mm:ss 格式。');

                return 1;
            }

            if (! in_array($type, ['contracts', 'all'], true)) {
                $this->warn('--since 仅对旧' . AuditType::Contracts->label() . '审计生效；当前类型将忽略该参数。');
            }
        }

        // 旧 1.0 Job 完全保留原有调度与水位线语义。
        if ($type === 'wallet' || $type === 'all') {
            $this->info('开始' . AuditType::WalletTransactions->label() . '审计...');
            Bus::dispatchSync(new AuditWalletTransactionsJob());
            $this->info(AuditType::WalletTransactions->label() . '审计完成。');
        }

        if ($type === 'contracts' || $type === 'all') {
            if ($sinceOverride !== null) {
                $this->warn(AuditType::Contracts->label() . '审计将从 ' . $sinceOverride->toDateTimeString() . ' 起一次性回扫；本次不推进水位线，重复事件会自动忽略。');
            }
            $this->info('开始' . AuditType::Contracts->label() . '审计...');
            Bus::dispatchSync(new AuditContractsJob($sinceOverride));
            $this->info(AuditType::Contracts->label() . '审计完成。');
        }

        // 新 2.0 Job 固定处理 98588384；是否实际执行由数据库配置的 enabled / audit_from 决定。
        if ($type === 'donations' || $type === 'all') {
            $this->info('开始' . AuditType::IskDonations->label() . '审计（军团 98588384）...');
            Bus::dispatchSync(new AuditDonationsJob());
            $this->info(AuditType::IskDonations->label() . '审计完成。');
        }

        if ($type === 'member-contracts' || $type === 'all') {
            $this->info('开始' . AuditType::MemberContracts->label() . '审计（军团 98588384）...');
            Bus::dispatchSync(new AuditMemberContractsJob());
            $this->info(AuditType::MemberContracts->label() . '审计完成。');
        }

        $this->info('全部请求的审计任务完成。');

        return 0;
    }
}
