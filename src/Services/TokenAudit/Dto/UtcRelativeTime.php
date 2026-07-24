<?php

// src/Services/TokenAudit/Dto/UtcRelativeTime.php
// 将 SeAT 的 UTC 追踪时间转换为页面可安全展示的相对时间及筛选元数据。

namespace Seat\SeatAuditMonitor\Services\TokenAudit\Dto;

use Carbon\CarbonImmutable;
use Throwable;

final class UtcRelativeTime
{
    public function __construct(
        public readonly string $label,
        public readonly ?string $tooltip,
        public readonly string $band,
        public readonly ?int $ageDays,
    ) {
    }

    /**
     * 统一按 UTC 自然日计算时间段，避免 PHP 服务器时区或浏览器时区影响 30/60 天筛选边界。
     *
     * 空值、无效值和未来值均保留为明确的展示状态；它们不能被误判为近期上线记录。
     */
    public static function from(mixed $value, CarbonImmutable $asOf): self
    {
        $normalized = trim((string) ($value ?? ''));
        if ($normalized === '') {
            return new self('—（无记录）', null, 'missing', null);
        }

        try {
            $occurredAt = CarbonImmutable::parse($normalized, 'UTC')->utc();
        } catch (Throwable) {
            return new self('—（无效时间）', null, 'invalid', null);
        }

        $tooltip = $occurredAt->format('Y-m-d H:i:s') . ' UTC';
        if ($occurredAt->gt($asOf)) {
            return new self('—（晚于审查基准）', $tooltip, 'future', null);
        }

        // diffInDays 只在已确认不是未来值时计算，因此使用绝对差值不会掩盖未来数据异常。
        $ageDays = $occurredAt->startOfDay()->diffInDays($asOf->startOfDay());
        $band = $ageDays <= 30
            ? 'within_30'
            : ($ageDays <= 60 ? 'within_60' : 'over_60');

        return new self(self::relativeLabel($occurredAt, $asOf, $ageDays), $tooltip, $band, $ageDays);
    }

    /**
     * 以简洁中文显示相对时间；30/60 天筛选仍严格以 UTC 自然日 ageDays 为准，而非这里的小时显示。
     */
    private static function relativeLabel(CarbonImmutable $occurredAt, CarbonImmutable $asOf, int $ageDays): string
    {
        $seconds = $occurredAt->diffInSeconds($asOf);

        if ($seconds < 60) {
            return '刚刚';
        }
        if ($seconds < 3600) {
            return intdiv($seconds, 60) . ' 分钟前';
        }
        if ($seconds < 86400) {
            return intdiv($seconds, 3600) . ' 小时前';
        }
        if ($ageDays < 30) {
            return $ageDays . ' 天前';
        }
        if ($ageDays < 365) {
            return intdiv($ageDays, 30) . ' 个月前';
        }

        return intdiv($ageDays, 365) . ' 年前';
    }
}
