<?php

// src/Services/Audit/ViolationWriter.php
// 所有新扫描策略共用的违规写入器：规范化快照并依赖 source_event_key 唯一索引实现幂等写入

namespace Seat\SeatAuditMonitor\Services\Audit;

use BackedEnum;
use InvalidArgumentException;
use JsonException;
use Illuminate\Support\Facades\DB;
use Seat\SeatAuditMonitor\Enums\AuditType;

final class ViolationWriter
{
    /**
     * 批量写入违规记录。
     * 调用方应把本方法与 cursor 更新放进同一个数据库事务，确保当前 chunk 全部成功后才推进进度。
     */
    public function insertOrIgnore(array $violations): ViolationWriteResult
    {
        if ($violations === []) {
            return new ViolationWriteResult(0, 0, 0);
        }

        $rows = array_map(
            fn (array $violation): array => $this->normalize($violation),
            array_values($violations)
        );

        // 唯一索引只允许同一 source_event_key 首次写入；重复镜像、重试和 overlap 回扫都会被忽略。
        $inserted = DB::table('seat_audit_violations')->insertOrIgnore($rows);
        $attempted = count($rows);

        return new ViolationWriteResult(
            attempted: $attempted,
            inserted: $inserted,
            duplicate: $attempted - $inserted
        );
    }

    /**
     * 对单条写入结构做集中校验，防止 INSERT IGNORE 把字段截断等数据错误误判为“重复”。
     * 金额禁止传入 float，避免大额 ISK 在 PHP 浮点运算中丢失精度。
     *
     * @throws InvalidArgumentException|JsonException
     */
    private function normalize(array $violation): array
    {
        $auditType = $violation['audit_type'] ?? null;
        if ($auditType instanceof BackedEnum) {
            $auditType = $auditType->value;
        }

        if (! is_string($auditType) || AuditType::tryFrom($auditType) === null) {
            throw new InvalidArgumentException('audit_type 不是已注册的审计类型。');
        }

        $sourceEventKey = $violation['source_event_key'] ?? null;
        if (! is_string($sourceEventKey) || preg_match('/^[a-f0-9]{64}$/', $sourceEventKey) !== 1) {
            throw new InvalidArgumentException('source_event_key 必须是 64 位小写 SHA-256。');
        }

        foreach (['character_id', 'character_name', 'amount', 'violation_time', 'details'] as $requiredField) {
            if (! array_key_exists($requiredField, $violation)) {
                throw new InvalidArgumentException('违规记录缺少必填字段：' . $requiredField);
            }
        }

        if (is_float($violation['amount'])) {
            throw new InvalidArgumentException('amount 不允许使用 float，请传入 decimal 字符串或整数。');
        }

        $amount = (string) $violation['amount'];
        if (preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/', $amount) !== 1) {
            throw new InvalidArgumentException('amount 必须是有效的十进制定点数字符串。');
        }

        $details = $violation['details'];
        if (is_array($details) || is_object($details)) {
            $details = json_encode(
                $details,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
        }

        if (! is_string($details) || $details === '') {
            throw new InvalidArgumentException('details 必须是非空 JSON 字符串、数组或对象。');
        }

        // 字符串输入也必须先验证为合法 JSON，避免 INSERT IGNORE 把结构错误吞成普通忽略结果。
        json_decode($details, true, 512, JSON_THROW_ON_ERROR);

        $violation['audit_type'] = $auditType;
        $violation['amount'] = $amount;
        $violation['details'] = $details;
        $violation['created_at'] ??= now()->toDateTimeString();

        return $violation;
    }
}
