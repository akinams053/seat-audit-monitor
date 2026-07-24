<?php

// src/Services/Audit/DonationEvent.php
// 已通过来源完整性校验的 canonical ISK 捐赠事件，统一表达单边或正负镜像 journal

namespace Seat\SeatAuditMonitor\Services\Audit;

final readonly class DonationEvent
{
    /**
     * @param array<int, string> $journalNaturalKeys 原始 journal 的 character_id:id 复合键
     * @param array<int, array<string, mixed>> $journalRows 已保留的来源快照，后续写入 details 追溯
     */
    public function __construct(
        public string $canonicalDonationId,
        public int $donorId,
        public int $recipientId,
        public string $amount,
        public string $occurredAt,
        public bool $isMirrored,
        public array $journalNaturalKeys,
        public array $journalRows,
    ) {
    }
}
