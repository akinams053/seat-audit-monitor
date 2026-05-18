<?php

// src/Console/Commands/ResolveUnknownNamesCommand.php
// 手动触发外部角色 ID 名字解析（命令行版，便于排查和首次大批量回填）

namespace Seat\SeatAuditMonitor\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use Seat\SeatAuditMonitor\Jobs\ResolveUnknownNamesJob;

class ResolveUnknownNamesCommand extends Command
{
    protected $signature = 'seat:audit:resolve-unknown-names';

    protected $description = '调用 ESI 公开 /universe/names/ 接口批量解析违规记录中的「Unknown (ID:X)」角色名';

    public function handle()
    {
        $this->info('开始解析未知角色名...');
        // 同步执行：命令行场景适合直接拿到结果，不丢进队列
        Bus::dispatchSync(new ResolveUnknownNamesJob());
        $this->info('解析任务结束（详细统计见 laravel.log 中 [seat-audit:resolve-unknown] 前缀）。');

        return 0;
    }
}
