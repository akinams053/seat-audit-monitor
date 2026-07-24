<?php

// src/Services/Audit/DonationCursorPosition.php
// 表达 ISK 捐赠 canonical event 在稳定来源排序中的最后位置，供后续 A3 游标事务推进复用

namespace Seat\SeatAuditMonitor\Services\Audit;

final readonly class DonationCursorPosition
{
    /**
     * 捐赠来源按 occurredAt、characterId、canonicalDonationId 排序。
     *
     * character_wallet_journals 的单个原始主键仍是 (character_id, id)，镜像行共享 id。
     * 因此以分组后的最小 character_id 作为第二排序键、共享 id 作为第三排序键，既可稳定
     * 分页，又不会把同一 canonical event 的镜像拆成两个进度位置。
     */
    public function __construct(
        public string $occurredAt,
        public int $characterId,
        public string $canonicalDonationId,
    ) {
    }
}
