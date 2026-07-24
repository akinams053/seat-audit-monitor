<?php

// src/Services/TokenAudit/SeatTokenAuditGroupingService.php
// 对脱敏角色投影实施状态/时间/关键词筛选，并按 SeAT 用户主角色关系组成稳定页面分组。

namespace Seat\SeatAuditMonitor\Services\TokenAudit;

use Seat\SeatAuditMonitor\Services\TokenAudit\Dto\TokenAuditCharacter;
use Seat\SeatAuditMonitor\Services\TokenAudit\Dto\TokenAuditGroup;

final class SeatTokenAuditGroupingService
{
    /**
     * 统计当前军团成员的三态数量。数量按角色而非账号组计算，确保状态标签准确表达问题角色数。
     *
     * @param array<int, TokenAuditCharacter> $characters
     * @return array{all: int, normal: int, expired: int, unbound: int}
     */
    public function statusCounts(array $characters): array
    {
        $counts = [
            'all'     => count($characters),
            'normal'  => 0,
            'expired' => 0,
            'unbound' => 0,
        ];

        foreach ($characters as $character) {
            $counts[$character->tokenStatus]++;
        }

        return $counts;
    }

    /**
     * 先按角色应用筛选，再聚合为账号组；这样同一账号的角色状态不同，状态标签仍只显示命中的行。
     *
     * @param array<int, TokenAuditCharacter> $characters
     * @param 'all'|'normal'|'expired'|'unbound' $status
     * @param 'all'|'within_30'|'within_60' $lastSeen
     * @param 'all'|'within_30'|'within_60' $lastLogoff
     * @param 'all'|'within_30'|'within_60' $joinedWithin
     * @return array<int, TokenAuditGroup>
     */
    public function filterAndGroup(
        array $characters,
        string $status,
        string $lastSeen,
        string $lastLogoff,
        string $joinedWithin,
        string $search,
    ): array
    {
        // $characters 是查询层读取的完整当前军团成员名册；先固化 ID 集合，避免后续页面筛选
        // （例如只看「账号过期」）把状态正常的主角色排除后，错误显示为「主角色不在当前军团成员范围」。
        $currentMemberIds = [];
        foreach ($characters as $character) {
            $currentMemberIds[$character->characterId] = true;
        }

        /** @var array<string, array{primary_id: ?int, primary_name: string, characters: array<int, TokenAuditCharacter>}> $groupRows */
        $groupRows = [];

        foreach ($characters as $character) {
            if (! $this->matchesStatus($character, $status)
                || ! $this->matchesLastSeen($character, $lastSeen)
                || ! $this->matchesLastLogoff($character, $lastLogoff)
                || ! $this->matchesJoinedWithin($character, $joinedWithin)
                || ! $this->matchesSearch($character, $search)) {
                continue;
            }

            // 无 token 的成员按定义没有 SeAT 用户，绝不能为了页面整洁错误归并到其他无绑定成员。
            $groupKey = $character->seatUserId === null
                ? 'unbound:' . $character->characterId
                : 'seat-user:' . $character->seatUserId;

            if (! isset($groupRows[$groupKey])) {
                $groupRows[$groupKey] = [
                    'primary_id'   => $character->primaryCharacterId,
                    'primary_name' => $character->primaryCharacterName
                        ?? ($character->seatUserId === null ? $character->characterName : 'SeAT 主角色未配置'),
                    'characters'   => [],
                ];
            }

            $groupRows[$groupKey]['characters'][] = $character;
        }

        $groups = [];
        foreach ($groupRows as $groupKey => $groupRow) {
            $groupCharacters = $groupRow['characters'];
            usort($groupCharacters, function (TokenAuditCharacter $left, TokenAuditCharacter $right): int {
                // 主角色永远排在组内第一位；其余角色再按令牌问题优先级和稳定名称排序。
                if ($left->isPrimaryCharacter() !== $right->isPrimaryCharacter()) {
                    return $left->isPrimaryCharacter() ? -1 : 1;
                }

                $statusComparison = $this->statusRank($left->tokenStatus) <=> $this->statusRank($right->tokenStatus);
                if ($statusComparison !== 0) {
                    return $statusComparison;
                }

                $nameComparison = strcmp($this->lower($left->characterName), $this->lower($right->characterName));

                return $nameComparison !== 0
                    ? $nameComparison
                    : ($left->characterId <=> $right->characterId);
            });

            $priorityStatus = $this->priorityStatus($groupCharacters);
            $primaryCharacterId = $groupRow['primary_id'];
            $groups[] = new TokenAuditGroup(
                groupKey: $groupKey,
                primaryCharacterId: $primaryCharacterId,
                primaryCharacterName: $groupRow['primary_name'],
                // 组内角色只代表当前筛选命中的显示行；成员范围必须始终按完整名册判定。
                primaryCharacterInScope: $primaryCharacterId !== null
                    && isset($currentMemberIds[$primaryCharacterId]),
                characters: $groupCharacters,
                priorityStatus: $priorityStatus,
            );
        }

        usort($groups, function (TokenAuditGroup $left, TokenAuditGroup $right): int {
            $statusComparison = $this->statusRank($left->priorityStatus) <=> $this->statusRank($right->priorityStatus);
            if ($statusComparison !== 0) {
                return $statusComparison;
            }

            $nameComparison = strcmp($this->lower($left->primaryCharacterName), $this->lower($right->primaryCharacterName));
            if ($nameComparison !== 0) {
                return $nameComparison;
            }

            return strcmp($left->groupKey, $right->groupKey);
        });

        return $groups;
    }

    /** @param 'all'|'normal'|'expired'|'unbound' $status */
    private function matchesStatus(TokenAuditCharacter $character, string $status): bool
    {
        return $status === 'all' || $character->tokenStatus === $status;
    }

    /** @param 'all'|'within_30'|'within_60' $lastSeen */
    private function matchesLastSeen(TokenAuditCharacter $character, string $lastSeen): bool
    {
        // 最后上线唯一使用 character_onlines.last_login；不能回退到成员追踪表的 logoff_date。
        return $this->matchesTimeBand($character->lastLoginAt->band, $lastSeen);
    }

    /** @param 'all'|'within_30'|'within_60' $lastLogoff */
    private function matchesLastLogoff(TokenAuditCharacter $character, string $lastLogoff): bool
    {
        // 最后离线仅使用军团成员追踪的 logoff_date，和 character_onlines 的最后上线语义独立。
        return $this->matchesTimeBand($character->lastLogoffAt->band, $lastLogoff);
    }

    /** @param 'all'|'within_30'|'within_60' $joinedWithin */
    private function matchesJoinedWithin(TokenAuditCharacter $character, string $joinedWithin): bool
    {
        return $this->matchesTimeBand($character->joinedAt->band, $joinedWithin);
    }

    /**
     * 将 UTC 时间值对象的离散区间应用到“最近 N 天内”的用户筛选。
     *
     * @param 'all'|'within_30'|'within_60' $filter
     */
    private function matchesTimeBand(string $band, string $filter): bool
    {
        return match ($filter) {
            'within_30' => $band === 'within_30',
            // 60 天内包含 30 天内，按用户选择的“最近 N 天”直觉处理，而不是只显示第 31 至 60 天。
            'within_60' => in_array($band, ['within_30', 'within_60'], true),
            default => true,
        };
    }

    private function matchesSearch(TokenAuditCharacter $character, string $search): bool
    {
        $needle = $this->lower(trim($search));
        if ($needle === '') {
            return true;
        }

        foreach ([
            $character->characterName,
            $character->primaryCharacterName ?? '',
            $character->displayTitle(),
            (string) $character->characterId,
        ] as $candidate) {
            if (str_contains($this->lower($candidate), $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, TokenAuditCharacter> $characters
     * @return 'normal'|'expired'|'unbound'
     */
    private function priorityStatus(array $characters): string
    {
        $status = 'normal';
        foreach ($characters as $character) {
            if ($this->statusRank($character->tokenStatus) < $this->statusRank($status)) {
                $status = $character->tokenStatus;
            }
        }

        return $status;
    }

    /** @param 'normal'|'expired'|'unbound' $status */
    private function statusRank(string $status): int
    {
        return match ($status) {
            'unbound' => 0,
            'expired' => 1,
            default => 2,
        };
    }

    private function lower(string $value): string
    {
        return function_exists('mb_strtolower')
            ? mb_strtolower($value, 'UTF-8')
            : strtolower($value);
    }
}
