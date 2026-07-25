<?php

// src/Console/Commands/ResolveUnknownNamesCommand.php
// 手动触发审计记录 Unknown 实体、角色当前军团和军团/联盟名称解析（命令行版）

namespace Seat\SeatAuditMonitor\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use Seat\SeatAuditMonitor\Jobs\ResolveUnknownNamesJob;

class ResolveUnknownNamesCommand extends Command
{
    protected $signature = 'seat:audit:resolve-unknown-names';

    protected $description = '调用 ESI 公开接口批量解析旧违规和军团审计中的 Unknown 实体、角色军团与军团/联盟名称';

    public function handle()
    {
        $this->info('开始解析未知实体和军团名称...');
        // 同步执行：命令行场景适合直接拿到结果，不丢进队列
        Bus::dispatchSync(new ResolveUnknownNamesJob());
        $this->info('解析任务结束（详细统计见 laravel.log 中 [seat-audit:resolve-unknown] 前缀）。');

        return 0;
    }
}
