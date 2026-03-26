<?php

// src/Models/AuditStatus.php
// 增量扫描水位线模型

namespace Seat\SeatAuditMonitor\Models;

use Seat\Services\Models\ExtensibleModel;

class AuditStatus extends ExtensibleModel
{
    protected $table = 'seat_audit_status';

    protected $fillable = ['audit_type', 'last_id'];

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
}
