<?php

// src/Services/Audit/ViolationWriteResult.php
// 幂等批量写入结果值对象，向扫描器准确返回尝试、成功和重复数量

namespace Seat\SeatAuditMonitor\Services\Audit;

final readonly class ViolationWriteResult
{
    public function __construct(
        public int $attempted,
        public int $inserted,
        public int $duplicate
    ) {
    }
}
