<?php

declare(strict_types=1);

namespace HardImpact\OpenClaw\Concerns;

use Carbon\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;

/**
 * Trait for agent models that participate in the OpenClaw wake/status system.
 *
 * Requires the model to implement OpenClawAgent and have these columns:
 * - wake_enabled (bool)
 * - wake_interval_seconds (int)
 * - last_wake_at (datetime, nullable)
 * - wake_count_minute (int)
 * - consecutive_failures (int)
 * - backoff_until (datetime, nullable)
 * - wake_count (int)
 * - wake_count_today (int)
 * - wake_count_today_date (date, nullable)
 * - is_awake (bool)
 * - session_age_ms (int, nullable)
 * - last_status_check_at (datetime, nullable)
 *
 * @property bool $wake_enabled
 * @property int $wake_interval_seconds
 * @property Carbon|null $last_wake_at
 * @property int $wake_count_minute
 * @property int $consecutive_failures
 * @property Carbon|null $backoff_until
 * @property int $wake_count
 * @property int $wake_count_today
 * @property Carbon|null $wake_count_today_date
 * @property bool $is_awake
 * @property int|null $session_age_ms
 * @property Carbon|null $last_status_check_at
 *
 * @mixin \Illuminate\Database\Eloquent\Model
 */
trait HasWakeManagement
{
    /**
     * Check if this agent can be woken (respects all rate limiting layers).
     */
    public function canWake(): bool
    {
        if (! $this->wake_enabled) {
            return false;
        }

        /** @var Carbon|null $lastWakeAt */
        $lastWakeAt = $this->last_wake_at;

        if ($lastWakeAt !== null && $lastWakeAt->diffInMinutes(now()) >= 1) {
            $this->wake_count_minute = 0;
        }

        $model = $this->getAgentModel();
        /** @var array<string, int> $modelLimits */
        $modelLimits = Config::get('openclaw.rate_limits.model_limits', []);
        /** @var int $defaultRpm */
        $defaultRpm = Config::get('openclaw.rate_limits.default_rpm', 50);
        $limit = $modelLimits[$model ?? ''] ?? $defaultRpm;

        if ($this->wake_count_minute >= $limit) {
            return false;
        }

        if ($this->backoff_until !== null && $this->backoff_until->isFuture()) {
            return false;
        }

        if ($lastWakeAt !== null) {
            $secondsSinceLastWake = $lastWakeAt->diffInSeconds(now());

            return $secondsSinceLastWake >= $this->wake_interval_seconds;
        }

        return true;
    }

    /**
     * Get seconds until this agent can be woken again.
     */
    public function secondsUntilCanWake(): int
    {
        if (! $this->wake_enabled) {
            return PHP_INT_MAX;
        }

        if ($this->backoff_until !== null && $this->backoff_until->isFuture()) {
            return (int) now()->diffInSeconds($this->backoff_until, false);
        }

        /** @var Carbon|null $lastWakeAt */
        $lastWakeAt = $this->last_wake_at;

        if ($lastWakeAt !== null) {
            $secondsSinceLastWake = (int) $lastWakeAt->diffInSeconds(now(), false);
            $remaining = $this->wake_interval_seconds - $secondsSinceLastWake;

            return max(0, $remaining);
        }

        return 0;
    }

    /**
     * Record a successful wake attempt.
     */
    public function recordWakeSuccess(): void
    {
        /** @var Carbon|null $lastWakeAt */
        $lastWakeAt = $this->last_wake_at;

        if ($lastWakeAt !== null && $lastWakeAt->diffInMinutes(now()) >= 1) {
            $this->wake_count_minute = 0;
        }

        /** @var Carbon|null $wakeCountTodayDate */
        $wakeCountTodayDate = $this->wake_count_today_date;

        $isToday = $wakeCountTodayDate !== null && $wakeCountTodayDate->isToday();

        $wakeCountToday = $isToday
            ? $this->wake_count_today + 1
            : 1;

        $this->update([
            'last_wake_at' => now(),
            'wake_count_minute' => $this->wake_count_minute + 1,
            'consecutive_failures' => 0,
            'backoff_until' => null,
            'wake_count' => $this->wake_count + 1,
            'wake_count_today' => $wakeCountToday,
            'wake_count_today_date' => now(),
        ]);
    }

    /**
     * Record a failed wake attempt and apply exponential backoff.
     */
    public function recordWakeFailure(): void
    {
        /** @var int $maxBackoff */
        $maxBackoff = Config::get('openclaw.rate_limits.max_backoff_seconds', 600);
        /** @var int $baseBackoff */
        $baseBackoff = Config::get('openclaw.rate_limits.base_backoff_seconds', 120);

        $failures = $this->consecutive_failures + 1;

        $maxExponent = (int) ceil(log($maxBackoff / $baseBackoff, 2));

        $backoffSeconds = ($failures - 1) >= $maxExponent
            ? $maxBackoff
            : (int) ($baseBackoff * (2 ** ($failures - 1)));

        $backoffSeconds = min($maxBackoff, max(0, $backoffSeconds));

        $this->update([
            'consecutive_failures' => $failures,
            'backoff_until' => Date::now()->addSeconds($backoffSeconds),
        ]);
    }

    /**
     * Record a rate limit response with server-specified retry-after.
     */
    public function recordRateLimitResponse(int $retryAfterSeconds): void
    {
        /** @var int $maxBackoff */
        $maxBackoff = Config::get('openclaw.rate_limits.max_backoff_seconds', 600);
        /** @var int $baseBackoff */
        $baseBackoff = Config::get('openclaw.rate_limits.base_backoff_seconds', 120);

        $failures = $this->consecutive_failures + 1;

        $backoffSeconds = max(
            $baseBackoff,
            min($maxBackoff, $retryAfterSeconds)
        );

        $this->update([
            'consecutive_failures' => $failures,
            'backoff_until' => Date::now()->addSeconds($backoffSeconds),
        ]);

        Log::info('Agent rate limited by server', [
            'agent' => $this->getAgentDisplayName(),
            'retry_after' => $retryAfterSeconds,
            'applied_backoff' => $backoffSeconds,
            'consecutive_failures' => $failures,
        ]);
    }

    /**
     * Get the current backoff duration in seconds (for reporting).
     */
    public function getCurrentBackoffSeconds(): int
    {
        if ($this->backoff_until === null) {
            return 0;
        }

        if ($this->backoff_until->isPast()) {
            return 0;
        }

        return (int) now()->diffInSeconds($this->backoff_until);
    }

    /**
     * Update the agent's awake status based on session age.
     */
    public function updateAwakeStatus(int $sessionAgeMs): void
    {
        /** @var int $awakeThreshold */
        $awakeThreshold = Config::get('openclaw.health.awake_threshold_ms', 60000);

        $this->update([
            'is_awake' => $sessionAgeMs < $awakeThreshold,
            'session_age_ms' => $sessionAgeMs,
            'last_status_check_at' => now(),
        ]);
    }

    /**
     * Mark agent as sleeping (no active session found).
     */
    public function markAsSleeping(): void
    {
        $this->update([
            'is_awake' => false,
            'session_age_ms' => null,
            'last_status_check_at' => now(),
        ]);
    }
}
