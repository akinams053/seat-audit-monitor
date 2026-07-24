<?php

// src/Services/TokenAudit/Dto/TokenAuditGroup.php
// 同一 SeAT 用户下当前军团成员角色的页面分组；未绑定角色保持一人一组。

namespace Seat\SeatAuditMonitor\Services\TokenAudit\Dto;

final class TokenAuditGroup
{
    /**
     * @param array<int, TokenAuditCharacter> $characters
     * @param 'normal'|'expired'|'unbound' $priorityStatus
     */
    public function __construct(
        public readonly string $groupKey,
        public readonly ?int $primaryCharacterId,
        public readonly string $primaryCharacterName,
        public readonly bool $primaryCharacterInScope,
        public readonly array $characters,
        public readonly string $priorityStatus,
    ) {
    }
}
