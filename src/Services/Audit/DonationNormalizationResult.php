<?php

// src/Services/Audit/DonationNormalizationResult.php
// 表达单个 canonical journal 分组是否可安全规范为捐赠事件，避免扫描器对异常数据猜测修复

namespace Seat\SeatAuditMonitor\Services\Audit;

final readonly class DonationNormalizationResult
{
    private function __construct(
        public ?DonationEvent $event,
        public ?string $invalidReason,
    ) {
    }

    public static function valid(DonationEvent $event): self
    {
        return new self(event: $event, invalidReason: null);
    }

    public static function invalid(string $reason): self
    {
        return new self(event: null, invalidReason: $reason);
    }

    public function isValid(): bool
    {
        return $this->event !== null;
    }
}
