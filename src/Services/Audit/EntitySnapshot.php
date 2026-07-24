<?php

// src/Services/Audit/EntitySnapshot.php
// 当前扫描 chunk 内参与方的名称、实体类别与当前军团信息快照

namespace Seat\SeatAuditMonitor\Services\Audit;

final readonly class EntitySnapshot
{
    /**
     * @param 'character'|'corporation'|'alliance'|'unknown' $entityType
     */
    public function __construct(
        public int $entityId,
        public string $name,
        public string $entityType,
        public ?int $corporationId,
        public ?string $corporationName,
        public ?string $corporationTicker,
    ) {
    }

    /**
     * 构造稳定的 Unknown 占位，保留真实 ID 供后续 ESI 名称解析和审计追溯使用。
     */
    public static function unknown(int $entityId): self
    {
        return new self(
            entityId: $entityId,
            name: 'Unknown (ID: ' . $entityId . ')',
            entityType: 'unknown',
            corporationId: null,
            corporationName: null,
            corporationTicker: null,
        );
    }
}
