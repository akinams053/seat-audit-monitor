<?php

// src/Services/Audit/ScanProgressStore.php
// 用共享 Cache 在 Web 请求与 Horizon worker 之间传递一次性扫描进度；不保存来源数据、异常详情或授权秘密。

namespace Seat\SeatAuditMonitor\Services\Audit;

use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

final class ScanProgressStore
{
    private const CACHE_PREFIX = 'seat-audit:corporation-scan:';
    private const TTL_MINUTES = 30;
    private const TERMINAL_STATUSES = ['succeeded', 'skipped', 'failed'];

    /**
     * 在 Controller 成功校验请求后创建一次性进度记录。
     *
     * 缓存记录只用于页面轮询和完成提示；审计正确性仍完全由数据库 cursor、事务和
     * source_event_key 唯一索引保证。Cache 被清理时不得影响扫描本身。
     */
    public function create(string $token, string $auditType): void
    {
        Cache::put($this->key($token), [
            'audit_type' => $auditType,
            'status'     => 'queued',
            'chunks'     => 0,
            'inserted'   => 0,
            'reason'     => null,
        ], now()->addMinutes(self::TTL_MINUTES));
    }

    /**
     * 标记 worker 已开始执行。终态记录可能因队列重复投递再次进入 handle，必须保持终态不被覆盖。
     */
    public function markRunning(?string $token): void
    {
        $this->update($token, static function (array $progress): array {
            if (in_array($progress['status'] ?? null, self::TERMINAL_STATUSES, true)) {
                return $progress;
            }

            $progress['status'] = 'running';

            return $progress;
        });
    }

    /**
     * 在一个来源批次的数据库事务已成功提交后累计展示数据。
     *
     * 缓存计数仅作体验反馈；即使 Cache 在提交后短暂不可用，也不能回滚已安全提交的审计数据。
     */
    public function recordBatch(?string $token, int $inserted): void
    {
        $this->update($token, static function (array $progress) use ($inserted): array {
            if (in_array($progress['status'] ?? null, self::TERMINAL_STATUSES, true)) {
                return $progress;
            }

            $progress['status'] = 'running';
            $progress['chunks'] = (int) ($progress['chunks'] ?? 0) + 1;
            $progress['inserted'] = (int) ($progress['inserted'] ?? 0) + $inserted;

            return $progress;
        });
    }

    /**
     * 标记扫描已完整处理到当前 cursor。零新增仍是成功，不能与跳过混淆。
     */
    public function markSucceeded(?string $token): void
    {
        $this->markTerminal($token, 'succeeded');
    }

    /**
     * 标记安全前置条件不满足导致的未执行状态，例如配置未启用或成员名册为空。
     */
    public function markSkipped(?string $token, string $reason): void
    {
        $this->markTerminal($token, 'skipped', $reason);
    }

    /**
     * 仅由队列最终失败回调使用；不把异常原文写入 Cache，避免数据库或运行细节暴露到页面。
     */
    public function markFailed(?string $token): void
    {
        $this->markTerminal($token, 'failed', 'processing_failed');
    }

    /**
     * 读取给 admin 状态端点使用的已脱敏进度。缓存失效时返回 null，由页面明确提示无法继续跟踪。
     *
     * @return array{audit_type: string, status: string, chunks: int, inserted: int, reason: ?string}|null
     */
    public function read(string $token): ?array
    {
        $progress = Cache::get($this->key($token));

        return is_array($progress) ? $progress : null;
    }

    private function markTerminal(?string $token, string $status, ?string $reason = null): void
    {
        $this->update($token, static function (array $progress) use ($status, $reason): array {
            if (in_array($progress['status'] ?? null, self::TERMINAL_STATUSES, true)) {
                return $progress;
            }

            $progress['status'] = $status;
            $progress['reason'] = $reason;

            return $progress;
        });
    }

    /**
     * @param callable(array{audit_type: string, status: string, chunks: int, inserted: int, reason: ?string}): array{audit_type: string, status: string, chunks: int, inserted: int, reason: ?string} $callback
     */
    private function update(?string $token, callable $callback): void
    {
        if ($token === null || $token === '') {
            return;
        }

        $key = $this->key($token);
        $progress = Cache::get($key);
        if (! is_array($progress)) {
            // 进度缓存过期或被运维清理时，Job 仍应继续执行；不可重建一条脱离原请求的新记录。
            return;
        }

        Cache::put($key, $callback($progress), now()->addMinutes(self::TTL_MINUTES));
    }

    private function key(string $token): string
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $token) !== 1) {
            throw new InvalidArgumentException('扫描跟踪令牌格式无效。');
        }

        return self::CACHE_PREFIX . strtolower($token);
    }
}
