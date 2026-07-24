<?php

// src/Services/Audit/DonationEventNormalizer.php
// 将相同 character_wallet_journals.id 的单边或正负镜像日志规范为安全可审计的捐赠事件

namespace Seat\SeatAuditMonitor\Services\Audit;

final class DonationEventNormalizer
{
    /**
     * 规范一个 canonical journal ID 的全部来源行。
     *
     * 测试服务器已确认 player_donation 的正负镜像共享相同 id，而原始主键仍是 (character_id, id)。
     * 因此 id 是跨镜像稳定的 canonical identity；本方法只接受同一 id 的一或两条行，
     * 并对 party、所属角色、金额和时间进行严格校验。任何不完整或矛盾数据均返回 invalid，
     * 不依赖金额正负猜测 donor/recipient，也不尝试合并不同 id 的“看起来相似”事件。
     *
     * @param array<int, array<string, mixed>|object> $journalRows
     */
    public function normalize(array $journalRows): DonationNormalizationResult
    {
        if ($journalRows === []) {
            return DonationNormalizationResult::invalid('empty_canonical_group');
        }
        if (count($journalRows) > 2) {
            return DonationNormalizationResult::invalid('too_many_mirror_rows');
        }

        $normalizedRows = [];
        foreach ($journalRows as $journalRow) {
            $row = (array) $journalRow;
            $normalized = $this->normalizeRow($row);

            if ($normalized instanceof DonationNormalizationResult) {
                return $normalized;
            }

            $normalizedRows[] = $normalized;
        }

        $firstRow = $normalizedRows[0];
        foreach ($normalizedRows as $row) {
            if ($row['id'] !== $firstRow['id']) {
                return DonationNormalizationResult::invalid('canonical_id_mismatch');
            }
            if ($row['first_party_id'] !== $firstRow['first_party_id']
                || $row['second_party_id'] !== $firstRow['second_party_id']) {
                return DonationNormalizationResult::invalid('mirror_party_mismatch');
            }
            if ($row['date'] !== $firstRow['date']) {
                return DonationNormalizationResult::invalid('mirror_date_mismatch');
            }
        }

        // EVE 的 party 字段固定表达 donor -> recipient，绝不可依据账户流水正负号交换方向。
        if ($firstRow['first_party_id'] === $firstRow['second_party_id']) {
            return DonationNormalizationResult::invalid('same_party');
        }

        if (count($normalizedRows) === 2) {
            $mirrorResult = $this->validateMirrorPair($normalizedRows, $firstRow);
            if ($mirrorResult !== null) {
                return $mirrorResult;
            }
        }

        $journalNaturalKeys = array_map(
            static fn (array $row): string => $row['character_id'] . ':' . $row['id'],
            $normalizedRows
        );

        return DonationNormalizationResult::valid(new DonationEvent(
            canonicalDonationId: $firstRow['id'],
            donorId: $firstRow['first_party_id'],
            recipientId: $firstRow['second_party_id'],
            amount: $firstRow['absolute_amount'],
            occurredAt: $firstRow['date'],
            isMirrored: count($normalizedRows) === 2,
            journalNaturalKeys: $journalNaturalKeys,
            journalRows: array_map(static fn (array $row): array => $row['raw'], $normalizedRows),
        ));
    }

    /**
     * 将单条 journal 做无需猜测的字段校验和无浮点金额规范化。
     *
     * @param array<string, mixed> $row
     * @return array{raw: array<string, mixed>, character_id: int, id: string, date: string, first_party_id: int, second_party_id: int, amount: string, absolute_amount: string, is_negative: bool}|DonationNormalizationResult
     */
    private function normalizeRow(array $row): array|DonationNormalizationResult
    {
        $characterId = $this->positiveInteger($row['character_id'] ?? null);
        $journalId = $this->positiveIntegerString($row['id'] ?? null);
        $firstPartyId = $this->positiveInteger($row['first_party_id'] ?? null);
        $secondPartyId = $this->positiveInteger($row['second_party_id'] ?? null);
        $date = trim((string) ($row['date'] ?? ''));

        if ($characterId === null || $journalId === null || $firstPartyId === null || $secondPartyId === null || $date === '') {
            return DonationNormalizationResult::invalid('missing_party_or_identity');
        }
        if ($characterId !== $firstPartyId && $characterId !== $secondPartyId) {
            return DonationNormalizationResult::invalid('journal_owner_not_party');
        }

        // Scanner 必须以 CAST(amount AS DECIMAL(...)) 的 amount_decimal 字段传入，
        // 防止 PHP float 参与大额 ISK 金额处理。保留 amount 仅用于兼容明确传入字符串的规则测试。
        $amountValue = $row['amount_decimal'] ?? $row['amount'] ?? null;
        if (is_float($amountValue)) {
            return DonationNormalizationResult::invalid('floating_amount_not_allowed');
        }

        $amount = $this->normalizeSignedDecimal($amountValue);
        if ($amount === null || $this->absoluteDecimal($amount) === '0.00') {
            return DonationNormalizationResult::invalid('zero_or_invalid_amount');
        }

        return [
            'raw'             => $row,
            'character_id'    => $characterId,
            'id'              => $journalId,
            'date'            => $date,
            'first_party_id'  => $firstPartyId,
            'second_party_id' => $secondPartyId,
            'amount'          => $amount,
            'absolute_amount' => $this->absoluteDecimal($amount),
            'is_negative'     => str_starts_with($amount, '-'),
        ];
    }

    /**
     * 验证一正一负的镜像是否完整一致。
     *
     * 两条来源行必须分别属于 donor 和 recipient，金额绝对值一致且方向相反；
     * 任一条件不满足都说明不能安全消重，应计入 invalid 而不是任选一条写入。
     *
     * @param array<int, array{character_id: int, id: string, date: string, first_party_id: int, second_party_id: int, amount: string, absolute_amount: string, is_negative: bool}> $rows
     */
    private function validateMirrorPair(array $rows, array $firstRow): ?DonationNormalizationResult
    {
        if ($rows[0]['is_negative'] === $rows[1]['is_negative']) {
            return DonationNormalizationResult::invalid('mirror_sign_mismatch');
        }
        if ($rows[0]['absolute_amount'] !== $rows[1]['absolute_amount']) {
            return DonationNormalizationResult::invalid('mirror_amount_mismatch');
        }

        $ownerIds = [$rows[0]['character_id'] => true, $rows[1]['character_id'] => true];
        if (count($ownerIds) !== 2
            || ! isset($ownerIds[$firstRow['first_party_id']])
            || ! isset($ownerIds[$firstRow['second_party_id']])) {
            return DonationNormalizationResult::invalid('mirror_owner_mismatch');
        }

        return null;
    }

    private function positiveInteger(mixed $value): ?int
    {
        $normalized = $this->positiveIntegerString($value);

        return $normalized === null ? null : (int) $normalized;
    }

    private function positiveIntegerString(mixed $value): ?string
    {
        $normalized = trim((string) ($value ?? ''));
        if (preg_match('/^[1-9][0-9]*$/', $normalized) !== 1) {
            return null;
        }

        return $normalized;
    }

    /**
     * 将带符号金额规范为两位小数的字符串；不允许指数记法、float 或超过数据库目标精度的额外小数。
     */
    private function normalizeSignedDecimal(mixed $value): ?string
    {
        $normalized = trim((string) ($value ?? ''));
        if (preg_match('/^-?[0-9]+(?:\.[0-9]{1,2})?$/', $normalized) !== 1) {
            return null;
        }

        $negative = str_starts_with($normalized, '-');
        $normalized = ltrim($negative ? substr($normalized, 1) : $normalized, '0');
        if ($normalized === '' || $normalized === '.') {
            $normalized = '0';
        }

        [$integer, $fraction] = array_pad(explode('.', $normalized, 2), 2, '');
        $integer = $integer === '' ? '0' : $integer;
        $fraction = str_pad($fraction, 2, '0');

        return ($negative ? '-' : '') . $integer . '.' . $fraction;
    }

    private function absoluteDecimal(string $amount): string
    {
        return ltrim($amount, '-');
    }
}
