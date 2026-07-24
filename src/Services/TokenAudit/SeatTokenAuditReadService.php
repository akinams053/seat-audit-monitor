<?php

// src/Services/TokenAudit/SeatTokenAuditReadService.php
// 固定军团 98588384 的令牌审查只读数据投影；严格隔离 refresh token 等敏感授权字段。

namespace Seat\SeatAuditMonitor\Services\TokenAudit;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Seat\SeatAuditMonitor\Models\AuditCorporation;
use Seat\SeatAuditMonitor\Services\TokenAudit\Dto\TokenAuditCharacter;
use Seat\SeatAuditMonitor\Services\TokenAudit\Dto\UtcRelativeTime;

final class SeatTokenAuditReadService
{
    /**
     * 读取当前军团成员所需的最小展示字段。
     *
     * 成员资格只能来自 corporation_members；refresh_tokens 仅提供 character_id、user_id、deleted_at
     * 三个脱敏字段来判断状态和 SeAT 用户关系。查询绝不选择 token、refresh_token、scopes 或 expires_on。
     * character_infos.title 已由 SeAT 角色概览源码确认是概览「头衔」字段，和 $character->titles
     * 对应的 corporation_member_titles 权限头衔列表不是同一业务概念。
     *
     * @return array<int, TokenAuditCharacter>
     */
    public function readCurrentMembers(CarbonImmutable $asOf): array
    {
        return DB::table('corporation_members as cm')
            ->leftJoin('refresh_tokens as rt', 'rt.character_id', '=', 'cm.character_id')
            ->leftJoin('users as u', 'u.id', '=', 'rt.user_id')
            ->leftJoin('character_infos as ci', 'ci.character_id', '=', 'cm.character_id')
            ->leftJoin('universe_names as un', function ($join) {
                $join->on('un.entity_id', '=', 'cm.character_id')
                    ->where('un.category', '=', 'character');
            })
            ->leftJoin('corporation_member_trackings as cmt', function ($join) {
                $join->on('cmt.corporation_id', '=', 'cm.corporation_id')
                    ->on('cmt.character_id', '=', 'cm.character_id');
            })
            // 主角色可能不在当前军团，但仍需作为 SeAT 用户分组标题；只补齐它的公开名称，不把它加入成员行。
            ->leftJoin('character_infos as primary_ci', 'primary_ci.character_id', '=', 'u.main_character_id')
            ->leftJoin('universe_names as primary_un', function ($join) {
                $join->on('primary_un.entity_id', '=', 'u.main_character_id')
                    ->where('primary_un.category', '=', 'character');
            })
            ->where('cm.corporation_id', AuditCorporation::TARGET_CORPORATION_ID)
            ->select([
                'cm.character_id',
                DB::raw("COALESCE(NULLIF(ci.name, CHAR(0)), NULLIF(un.name, CHAR(0)), CONCAT('Unknown #', cm.character_id)) AS character_name"),
                'ci.title as character_title',
                // refresh_tokens.character_id 仅作是否存在 token 行的标志；不会把任何 token 内容带出数据库。
                'rt.character_id as token_character_id',
                'rt.user_id as seat_user_id',
                'rt.deleted_at as token_deleted_at',
                'u.main_character_id',
                DB::raw("COALESCE(NULLIF(primary_ci.name, CHAR(0)), NULLIF(primary_un.name, CHAR(0)), CONCAT('Unknown #', u.main_character_id)) AS primary_character_name"),
                'cmt.start_date',
                'cmt.logoff_date',
            ])
            ->orderBy('cm.character_id')
            ->get()
            ->map(fn (object $row): TokenAuditCharacter => $this->toCharacter($row, $asOf))
            ->all();
    }

    private function toCharacter(object $row, CarbonImmutable $asOf): TokenAuditCharacter
    {
        $hasTokenRecord = $row->token_character_id !== null;
        // refresh_tokens.character_id 在当前 SeAT schema 中唯一；一条未软删除记录即为状态正常。
        $tokenStatus = ! $hasTokenRecord
            ? 'unbound'
            : ($row->token_deleted_at === null ? 'normal' : 'expired');

        $primaryCharacterId = $this->positiveInteger($row->main_character_id ?? null);
        $primaryCharacterName = $primaryCharacterId === null
            ? null
            : trim((string) ($row->primary_character_name ?? ''));

        return new TokenAuditCharacter(
            characterId: (int) $row->character_id,
            characterName: trim((string) $row->character_name),
            characterTitle: $this->nullableString($row->character_title ?? null),
            tokenStatus: $tokenStatus,
            seatUserId: $this->positiveInteger($row->seat_user_id ?? null),
            primaryCharacterId: $primaryCharacterId,
            primaryCharacterName: $primaryCharacterName === '' ? null : $primaryCharacterName,
            joinedAt: UtcRelativeTime::from($row->start_date ?? null, $asOf),
            lastLogoffAt: UtcRelativeTime::from($row->logoff_date ?? null, $asOf),
        );
    }

    private function nullableString(mixed $value): ?string
    {
        $normalized = trim((string) ($value ?? ''));

        return $normalized === '' ? null : $normalized;
    }

    private function positiveInteger(mixed $value): ?int
    {
        $normalized = trim((string) ($value ?? ''));
        if (preg_match('/^[1-9][0-9]*$/', $normalized) !== 1) {
            return null;
        }

        return (int) $normalized;
    }
}
