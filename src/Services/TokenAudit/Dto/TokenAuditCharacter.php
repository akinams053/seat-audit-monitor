<?php

// src/Services/TokenAudit/Dto/TokenAuditCharacter.php
// 令牌审查页面的脱敏角色投影；绝不持有 refresh/access token、scope 或原始 token 行。

namespace Seat\SeatAuditMonitor\Services\TokenAudit\Dto;

final class TokenAuditCharacter
{
    /**
     * @param 'normal'|'expired'|'unbound' $tokenStatus
     */
    public function __construct(
        public readonly int $characterId,
        public readonly string $characterName,
        public readonly ?string $characterTitle,
        public readonly string $tokenStatus,
        public readonly ?int $seatUserId,
        public readonly ?int $primaryCharacterId,
        public readonly ?string $primaryCharacterName,
        // 入团时间仍来自成员追踪表；空值与异常值由 UtcRelativeTime 显式表达。
        public readonly UtcRelativeTime $joinedAt,
        // 最后上线唯一来自 SeAT character_onlines.last_login，不能误用成员追踪表的 logoff_date。
        public readonly UtcRelativeTime $lastLoginAt,
    ) {
    }

    public function displayTitle(): string
    {
        $title = trim((string) $this->characterTitle);

        return $title === '' ? '无头衔' : $title;
    }

    public function isPrimaryCharacter(): bool
    {
        return $this->primaryCharacterId !== null && $this->primaryCharacterId === $this->characterId;
    }

    public function statusLabel(): string
    {
        return match ($this->tokenStatus) {
            'normal' => '状态正常',
            'expired' => '账号过期',
            default => '无 SeAT 用户',
        };
    }
}
