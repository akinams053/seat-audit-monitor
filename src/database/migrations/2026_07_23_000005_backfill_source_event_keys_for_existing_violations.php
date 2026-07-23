<?php

// src/database/migrations/2026_07_23_000005_backfill_source_event_keys_for_existing_violations.php
// 为可识别的旧钱包/合同违规回填 SHA-256 幂等键；历史重复只标记最早一条，不删除任何记录

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BackfillSourceEventKeysForExistingViolations extends Migration
{
    private const CHUNK_SIZE = 500;
    private const WALLET_AUDIT_TYPE = 'wallet_transactions';
    private const CONTRACT_AUDIT_TYPE = 'contracts';

    public function up()
    {
        $stats = [
            'scanned'    => 0,
            'backfilled' => 0,
            'duplicates' => 0,
            'unresolved' => 0,
        ];

        // 按主键升序遍历，确保同一自然键出现多次时，只有最早的历史行获得规范幂等键。
        DB::table('seat_audit_violations')
            ->whereNull('source_event_key')
            ->whereIn('audit_type', [self::WALLET_AUDIT_TYPE, self::CONTRACT_AUDIT_TYPE])
            ->select(['id', 'audit_type', 'character_id', 'contract_id', 'type_id', 'details'])
            ->chunkById(self::CHUNK_SIZE, function ($rows) use (&$stats) {
                $candidates = [];

                foreach ($rows as $row) {
                    $stats['scanned']++;
                    $candidate = $this->buildCandidate($row);

                    if ($candidate === null) {
                        // 无效 JSON、缺失业务源 ID 或字段不一致时保留 NULL，不能猜测一个替代键。
                        $stats['unresolved']++;
                        continue;
                    }

                    $candidates[] = [
                        'id'        => (int) $row->id,
                        'key'       => $candidate['key'],
                        'reference' => $candidate['reference'],
                    ];
                }

                if ($candidates === []) {
                    return;
                }

                // 普通索引让跨 chunk 的已占用键查询保持低成本；chunk 内重复则由 $seenKeys 即时拦截。
                $candidateKeys = array_values(array_unique(array_column($candidates, 'key')));
                $existingKeys = DB::table('seat_audit_violations')
                    ->whereIn('source_event_key', $candidateKeys)
                    ->pluck('source_event_key')
                    ->all();
                $seenKeys = array_fill_keys($existingKeys, true);

                foreach ($candidates as $candidate) {
                    if (isset($seenKeys[$candidate['key']])) {
                        $stats['duplicates']++;
                        continue;
                    }

                    $updated = DB::table('seat_audit_violations')
                        ->where('id', $candidate['id'])
                        ->whereNull('source_event_key')
                        ->update([
                            'source_event_key' => $candidate['key'],
                            'source_reference' => $candidate['reference'],
                        ]);

                    if ($updated === 1) {
                        $seenKeys[$candidate['key']] = true;
                        $stats['backfilled']++;
                    }
                }
            }, 'id');

        Log::info('[seat-audit:migration] 历史 source_event_key 回填完成', $stats);
    }

    public function down()
    {
        // 幂等键已经成为审计追溯数据。单独回滚数据 migration 不清空它们，避免重新引入重复写入窗口。
    }

    /**
     * 根据旧审计类型构造规范键和可读来源引用。
     */
    private function buildCandidate(object $row): ?array
    {
        $details = $this->decodeDetails($row->details);

        if ($row->audit_type === self::WALLET_AUDIT_TYPE) {
            if ($details === null) {
                return null;
            }

            $transactionId = $this->normalizePositiveInteger($details['transaction_id'] ?? null);
            $detailsCharacterId = $this->normalizePositiveInteger($details['character_id'] ?? null);
            $rowCharacterId = $this->normalizePositiveInteger($row->character_id);

            // character_id 必须与源快照一致，否则这条历史行无法安全映射到唯一钱包事件。
            if ($transactionId === null || $detailsCharacterId === null || $detailsCharacterId !== $rowCharacterId) {
                return null;
            }

            return [
                'key'       => hash('sha256', implode('|', [self::WALLET_AUDIT_TYPE, $rowCharacterId, $transactionId])),
                'reference' => 'wallet_transaction:' . $transactionId,
            ];
        }

        if ($row->audit_type === self::CONTRACT_AUDIT_TYPE) {
            // 顶层快照列优先；早期历史行缺列值时才回退到 details JSON。
            $contractId = $this->normalizePositiveInteger($row->contract_id)
                ?? $this->normalizePositiveInteger($details['contract']['contract_id'] ?? null);
            $typeId = $this->normalizePositiveInteger($row->type_id)
                ?? $this->normalizePositiveInteger($details['item']['type_id'] ?? null);

            if ($contractId === null || $typeId === null) {
                return null;
            }

            return [
                'key'       => hash('sha256', implode('|', [self::CONTRACT_AUDIT_TYPE, $contractId, $typeId])),
                'reference' => 'contract:' . $contractId,
            ];
        }

        return null;
    }

    /**
     * MariaDB JSON 列经 Query Builder 读取时通常是字符串；异常 JSON 统一返回 NULL。
     */
    private function decodeDetails(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_object($value)) {
            return (array) $value;
        }

        if (! is_string($value) || $value === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * 将数据库和 JSON 中的正整数 ID 规范化为无前导零字符串，避免哈希输入因类型差异而变化。
     */
    private function normalizePositiveInteger(mixed $value): ?string
    {
        if (is_int($value)) {
            return $value > 0 ? (string) $value : null;
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === '' || preg_match('/^[0-9]+$/', $value) !== 1) {
            return null;
        }

        $normalized = ltrim($value, '0');

        return $normalized !== '' ? $normalized : null;
    }
}
