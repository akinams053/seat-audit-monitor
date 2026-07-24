<?php

// src/Services/Audit/DonationJournalBatch.php
// 保存固定受审军团的一批 canonical donation 来源的候选违规与进度位置，不负责写入数据库

namespace Seat\SeatAuditMonitor\Services\Audit;

final readonly class DonationJournalBatch
{
    /**
     * @param array<int, array<string, mixed>> $violations 已通过成员 XOR、audit_from 与外部方白名单的待写记录
     * @param array<string, int> $invalidReasons canonical group 不可安全判定时的来源行计数，供 Job 日志追溯
     */
    public function __construct(
        public array $violations,
        public int $sourceRows,
        public array $invalidReasons,
        public DonationCursorPosition $lastSourcePosition,
    ) {
    }
}
