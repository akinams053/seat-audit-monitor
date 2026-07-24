<?php

// src/Services/Audit/SourceEventKeyFactory.php
// 统一构造各审计来源的 SHA-256 幂等键，保证扫描重试、回扫和镜像数据使用完全相同的规范

namespace Seat\SeatAuditMonitor\Services\Audit;

use InvalidArgumentException;
use Seat\SeatAuditMonitor\Enums\AuditType;

final class SourceEventKeyFactory
{
    public function walletTransaction(int|string $characterId, int|string $transactionId): string
    {
        return $this->hash([
            AuditType::WalletTransactions->value,
            $this->normalizePositiveInteger($characterId, 'characterId'),
            $this->normalizePositiveInteger($transactionId, 'transactionId'),
        ]);
    }

    public function monitoredContract(int|string $contractId, int|string $typeId): string
    {
        return $this->hash([
            AuditType::Contracts->value,
            $this->normalizePositiveInteger($contractId, 'contractId'),
            $this->normalizePositiveInteger($typeId, 'typeId'),
        ]);
    }

    /**
     * 构造 ISK 捐赠来源键。
     *
     * character_wallet_journals 的原始主键是 (character_id, id)，但测试服务器已确认同一笔
     * player_donation 的正负镜像共享 id。因此 canonicalDonationId 使用该共享 id，
     * 同时仍包含审计军团和固定 donor/recipient 方向，保证多军团审查与镜像重试均幂等。
     */
    public function iskDonation(
        int|string $auditCorporationId,
        int|string $canonicalDonationId,
        int|string $firstPartyId,
        int|string $secondPartyId
    ): string {
        return $this->hash([
            AuditType::IskDonations->value,
            $this->normalizePositiveInteger($auditCorporationId, 'auditCorporationId'),
            $this->normalizePositiveInteger($canonicalDonationId, 'canonicalDonationId'),
            $this->normalizePositiveInteger($firstPartyId, 'firstPartyId'),
            $this->normalizePositiveInteger($secondPartyId, 'secondPartyId'),
        ]);
    }

    public function memberContract(int|string $auditCorporationId, int|string $contractId): string
    {
        return $this->hash([
            AuditType::MemberContracts->value,
            $this->normalizePositiveInteger($auditCorporationId, 'auditCorporationId'),
            $this->normalizePositiveInteger($contractId, 'contractId'),
        ]);
    }

    /**
     * 固定使用竖线拼接和小写十六进制 SHA-256；数据库只保存 64 字符摘要，不保存可变长度自然键。
     */
    private function hash(array $segments): string
    {
        return hash('sha256', implode('|', $segments));
    }

    /**
     * ID 统一规范化为无前导零的正整数字符串，防止整数与字符串输入生成不同键。
     */
    private function normalizePositiveInteger(int|string $value, string $field): string
    {
        $normalized = trim((string) $value);

        if ($normalized === '' || preg_match('/^[0-9]+$/', $normalized) !== 1) {
            throw new InvalidArgumentException($field . ' 必须是正整数。');
        }

        $normalized = ltrim($normalized, '0');

        if ($normalized === '') {
            throw new InvalidArgumentException($field . ' 必须大于 0。');
        }

        return $normalized;
    }
}
