<?php

// src/Enums/AuditType.php
// 集中定义所有审计类型、页面分组和中文标签，避免 Controller、Job、Blade、CSV 各自维护字符串白名单

namespace Seat\SeatAuditMonitor\Enums;

enum AuditType: string
{
    case WalletTransactions = 'wallet_transactions';
    case Contracts = 'contracts';
    case IskDonations = 'isk_donations';
    case MemberContracts = 'member_contracts';

    /**
     * 返回可持久化到 seat_audit_violations.audit_type 的全部值。
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $type): string => $type->value,
            self::cases()
        );
    }

    /**
     * 旧“物品违规审查”页面允许的审计类型。
     */
    public static function itemViolationValues(): array
    {
        return [
            self::WalletTransactions->value,
            self::Contracts->value,
        ];
    }

    /**
     * 新“军团对外审查”页面允许的审计类型。
     */
    public static function corporationAuditValues(): array
    {
        return [
            self::IskDonations->value,
            self::MemberContracts->value,
        ];
    }

    /**
     * 旧页面筛选与 CSV 使用的标签映射。
     */
    public static function itemViolationLabels(bool $includeAll = false): array
    {
        $labels = [
            self::WalletTransactions->value => self::WalletTransactions->label(),
            self::Contracts->value          => self::Contracts->label(),
        ];

        return $includeAll ? ['all' => '全部'] + $labels : $labels;
    }

    /**
     * 新页面筛选与 CSV 使用的标签映射。
     */
    public static function corporationAuditLabels(bool $includeAll = false): array
    {
        $labels = [
            self::IskDonations->value    => self::IskDonations->label(),
            self::MemberContracts->value => self::MemberContracts->label(),
        ];

        return $includeAll ? ['all' => '全部'] + $labels : $labels;
    }

    /**
     * 返回用户界面和导出文件使用的稳定中文名称。
     */
    public function label(): string
    {
        return match ($this) {
            self::WalletTransactions => '钱包交易',
            self::Contracts          => '监控物品合同',
            self::IskDonations       => 'ISK 捐赠',
            self::MemberContracts    => '成员低价合同',
        };
    }
}
