<?php

// /Users/akina/project/seat-audit-monitor/src/Models/AuditStatus.php
// 增量扫描水位线模型

namespace Seat\SeatAuditMonitor\Models;

use Seat\Services\Models\ExtensibleModel;

class AuditStatus extends ExtensibleModel
{
    protected $table = 'seat_audit_status';

    protected $fillable = ['audit_type', 'last_id', 'last_completed_at'];

    protected $casts = [
        'last_completed_at' => 'datetime',
    ];

    /**
     * 获取指定审计类型的水位线 ID。
     * 若记录不存在则自动初始化为 0（首次运行场景）。
     *
     * @param string $auditType 审计类型标识，如 'wallet_transactions'
     * @return int 上次扫描到的最大记录 ID
     */
    public static function getLastId(string $auditType): int
    {
        $status = static::firstOrCreate(
            ['audit_type' => $auditType],
            ['last_id'    => 0]
        );

        return (int) $status->last_id;
    }

    /**
     * 更新指定审计类型的水位线 ID。
     *
     * @param string $auditType 审计类型标识
     * @param int    $lastId    本次扫描处理到的最大记录 ID
     */
    public static function setLastId(string $auditType, int $lastId): void
    {
        static::where('audit_type', $auditType)
            ->update(['last_id' => $lastId]);
    }

    /**
     * 获取指定审计类型的完成时间水位线。
     * 合同审计无法依赖自增 ID 表示业务时间顺序，因此使用 date_completed 推进增量扫描。
     *
     * @param string $auditType 审计类型标识，如 'contracts'
     * @return \Carbon\Carbon|null 上次扫描到的最大合同完成时间，首次运行时为 null
     */
    public static function getLastCompletedAt(string $auditType): ?\Carbon\Carbon
    {
        $status = static::firstOrCreate(
            ['audit_type' => $auditType],
            [
                'last_id'           => 0,
                'last_completed_at' => null,
            ]
        );

        return $status->last_completed_at;
    }

    /**
     * 更新指定审计类型的完成时间水位线。
     * 只写入合同扫描已经完整处理过的最大 date_completed，避免下次重复扫描旧合同。
     *
     * @param string         $auditType 审计类型标识
     * @param \Carbon\Carbon $value     本次扫描处理到的最大合同完成时间
     */
    public static function setLastCompletedAt(string $auditType, \Carbon\Carbon $value): void
    {
        static::where('audit_type', $auditType)
            ->update(['last_completed_at' => $value]);
    }
}
