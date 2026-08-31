<?php

namespace App\Services\Zoho;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The single chokepoint every Zoho Inventory API call must pass through.
 *
 * Why this class exists at all: the previous implementation had ~22 independent
 * paths to Zoho's API, and the rate-limit guard was wired into only two of them
 * (the two cron entry points). All seven model observers called the push methods
 * directly, so ordinary UI activity kept draining quota straight through the
 * "cooldown" that was supposed to let it recover — which is why the outage never
 * self-healed. Funnelling everything through one acquire()/record() pair makes the
 * breaker, the pacing and the daily budget impossible to bypass by construction,
 * including from code written later.
 *
 * Three independent guards, checked in order of cost:
 *   1. Circuit breaker (closed → open → half-open) — free, purely local.
 *   2. Daily + per-run budget — a hard ceiling, so exceeding quota is structurally
 *      impossible rather than merely unlikely.
 *   3. GCRA pacing — smooths the short-window rate.
 */
class ZohoApiGate
{
    private const PREFIX = 'zoho:inv:';

    private const BREAKER_KEY = self::PREFIX.'breaker';

    private const PROBE_KEY = self::PREFIX.'breaker_probe';

    private const TAT_KEY = self::PREFIX.'gcra_tat';

    private const TRANSIENT_STREAK_KEY = self::PREFIX.'transient_streak';

    public const STATE_CLOSED = 'closed';

    public const STATE_OPEN = 'open';

    public const STATE_HALF_OPEN = 'half_open';

    /** Zoho Books/Inventory error code for "exceeded the maximum call rate limit". */
    private const CODE_RATE_LIMIT = 45;

    /** Consecutive non-rate-limit transient failures (5xx, timeouts) that trip the breaker. */
    private const TRANSIENT_TRIP_THRESHOLD = 5;

    /** Calls made by this process, enforcing the per-run ceiling. */
    private int $runCalls = 0;

    /** True when this process currently holds the half-open probe token. */
    private bool $holdsProbe = false;

    public function enabled(): bool
    {
        return (bool) config('services.zoho.inventory.sync_enabled', true);
    }

    /**
     * Claim permission to make exactly one Zoho API call.
     *
     * Returns null when the call may proceed, or a human-readable reason when it
     * must not. Deliberately combines checking with consuming — separating them
     * would open a race where two callers both see budget remaining and both spend
     * it. The daily counter is incremented here because by the time this returns
     * null the caller is about to put a request on the wire.
     */
    public function acquire(): ?string
    {
        if (! $this->enabled()) {
            return 'Zoho Inventory sync is disabled (ZOHO_INVENTORY_SYNC_ENABLED=false).';
        }

        if ($reason = $this->breakerBlock()) {
            return $reason;
        }

        if ($reason = $this->perRunBudgetBlock()) {
            return $reason;
        }

        if ($reason = $this->paceBlock()) {
            return $reason;
        }

        if ($reason = $this->claimDailyBudget()) {
            return $reason;
        }

        $this->runCalls++;

        return null;
    }

    // ---------------------------------------------------------------- breaker

    /**
     * Half-open is the piece the original cooldown was missing. Previously, when the
     * cooldown lapsed the *entire* backlog became eligible at once and instantly
     * re-exhausted the quota — the exact loop that was observed. Now a single probe
     * call decides whether Zoho has actually recovered; everything else stays parked
     * until that probe reports back.
     */
    private function breakerBlock(): ?string
    {
        $breaker = $this->breaker();

        if ($breaker['state'] === self::STATE_OPEN) {
            $until = Carbon::parse($breaker['until']);

            if ($until->isFuture()) {
                return "circuit breaker open until {$until->toDateTimeString()} (level {$breaker['level']})";
            }

            $this->putBreaker(self::STATE_HALF_OPEN, $breaker['until'], $breaker['level']);
            $breaker['state'] = self::STATE_HALF_OPEN;
        }

        if ($breaker['state'] === self::STATE_HALF_OPEN) {
            if ($this->holdsProbe) {
                return null;
            }

            // Cache::add is atomic, so exactly one caller across all workers wins
            // the probe even under concurrency.
            if (! Cache::add(self::PROBE_KEY, true, now()->addMinutes(5))) {
                return 'circuit breaker half-open — a probe call is already in flight';
            }

            $this->holdsProbe = true;
        }

        return null;
    }

    /** Record the outcome of a gated call so the breaker can open, close or escalate. */
    public function record(ZohoResult $result): void
    {
        if ($result->blocked) {
            return;
        }

        if ($result->ok()) {
            $this->onSuccess();

            return;
        }

        if ($result->isPermanent()) {
            // Zoho answered and rejected the content, so the service is healthy —
            // a data problem must not open the breaker.
            Cache::forget(self::TRANSIENT_STREAK_KEY);
            $this->releaseProbe(successful: true);

            return;
        }

        $this->onTransientFailure($result);
    }

    private function onSuccess(): void
    {
        Cache::forget(self::TRANSIENT_STREAK_KEY);

        if ($this->breaker()['state'] !== self::STATE_CLOSED) {
            Log::info('Zoho Inventory circuit breaker closing — probe call succeeded.');
            $this->putBreaker(self::STATE_CLOSED, null, 0);
        }

        $this->releaseProbe(successful: true);
    }

    private function onTransientFailure(ZohoResult $result): void
    {
        $isRateLimit = $this->looksRateLimited($result->status, $result->body, $result->message);

        // A failed probe means Zoho has not recovered: reopen immediately with the
        // next (longer) cooldown rather than letting the backlog through.
        if ($this->holdsProbe) {
            $this->trip($result, escalate: true);
            $this->releaseProbe(successful: false);

            return;
        }

        if ($isRateLimit) {
            $this->trip($result, escalate: true);

            return;
        }

        $streak = (int) Cache::get(self::TRANSIENT_STREAK_KEY, 0) + 1;
        Cache::put(self::TRANSIENT_STREAK_KEY, $streak, now()->addHour());

        if ($streak >= self::TRANSIENT_TRIP_THRESHOLD) {
            $this->trip($result, escalate: true);
            Cache::forget(self::TRANSIENT_STREAK_KEY);
        }
    }

    private function trip(ZohoResult $result, bool $escalate): void
    {
        $breaker = $this->breaker();
        $level = $escalate ? $breaker['level'] + 1 : $breaker['level'];

        $ladder = $this->ladder();
        $minutes = $ladder[min(max(0, $level - 1), count($ladder) - 1)];
        $until = now()->addMinutes($minutes);

        $this->putBreaker(self::STATE_OPEN, $until->toDateTimeString(), $level);

        Log::error('Zoho Inventory circuit breaker opened', [
            'until' => $until->toDateTimeString(),
            'cooldown_minutes' => $minutes,
            'level' => $level,
            'status' => $result->status,
            'reason' => $result->message,
        ]);
    }

    /**
     * @return list<int>
     */
    private function ladder(): array
    {
        $ladder = array_values(array_filter(
            (array) config('services.zoho.inventory.breaker.cooldown_ladder', [30, 60, 180, 360]),
            fn ($minutes) => (int) $minutes > 0,
        ));

        return $ladder !== []
            ? array_map('intval', $ladder)
            : [(int) config('services.zoho.inventory.rate_limit_cooldown_minutes', 180)];
    }

    private function releaseProbe(bool $successful): void
    {
        if (! $this->holdsProbe) {
            return;
        }

        $this->holdsProbe = false;

        // Drop the token either way: on success the breaker is closed so it is moot,
        // and on failure the breaker has already reopened with a fresh cooldown, so
        // the next probe should be free to run when that lapses.
        Cache::forget(self::PROBE_KEY);
    }

    /**
     * @return array{state: string, until: ?string, level: int}
     */
    private function breaker(): array
    {
        $stored = Cache::get(self::BREAKER_KEY);

        if (! is_array($stored) || ! isset($stored['state'])) {
            return ['state' => self::STATE_CLOSED, 'until' => null, 'level' => 0];
        }

        return [
            'state' => (string) $stored['state'],
            'until' => $stored['until'] ?? null,
            'level' => (int) ($stored['level'] ?? 0),
        ];
    }

    private function putBreaker(string $state, ?string $until, int $level): void
    {
        Cache::put(
            self::BREAKER_KEY,
            ['state' => $state, 'until' => $until, 'level' => $level],
            now()->addDays(2),
        );
    }

    // ----------------------------------------------------------------- budget

    private function perRunBudgetBlock(): ?string
    {
        $perRun = (int) config('services.zoho.inventory.rate_limit.per_run', 120);

        if ($perRun > 0 && $this->runCalls >= $perRun) {
            return "per-run API budget spent ({$perRun} calls) — remaining work resumes next run";
        }

        return null;
    }

    /**
     * Atomically check and consume one daily call. The previous check followed by
     * a separate increment allowed concurrent queue workers to all observe the
     * same remaining slot and overshoot the supposedly hard daily ceiling.
     */
    private function claimDailyBudget(): ?string
    {
        $lock = Cache::lock(self::PREFIX.'daily_budget_lock', 10);

        // This guard is the hard quota boundary, so contention must fail closed.
        if (! $lock->get()) {
            return 'daily API budget is being claimed by another worker — retry later';
        }

        try {
            $perDay = (int) config('services.zoho.inventory.rate_limit.per_day', 800);
            $used = $this->dailyUsage();

            if ($perDay > 0 && $used >= $perDay) {
                return "daily API budget spent ({$used}/{$perDay}) — resets at midnight ".$this->quotaTimezone();
            }

            $this->incrementDailyUsage();

            return null;
        } finally {
            $lock->release();
        }
    }

    public function dailyUsage(): int
    {
        return (int) Cache::get($this->dailyKey(), 0);
    }

    public function dailyRemaining(): int
    {
        $perDay = (int) config('services.zoho.inventory.rate_limit.per_day', 800);

        return max(0, $perDay - $this->dailyUsage());
    }

    private function incrementDailyUsage(): void
    {
        $key = $this->dailyKey();

        // Seed first: increment() is a no-op on a missing key in several stores.
        Cache::add($key, 0, $this->endOfQuotaDay());
        Cache::increment($key);
    }

    private function dailyKey(): string
    {
        return self::PREFIX.'calls:'.now($this->quotaTimezone())->toDateString();
    }

    private function endOfQuotaDay(): Carbon
    {
        return now($this->quotaTimezone())->endOfDay()->addHour();
    }

    private function quotaTimezone(): string
    {
        return (string) config('services.zoho.inventory.rate_limit.day_timezone', 'Asia/Kolkata');
    }

    // ------------------------------------------------------------------ pacing

    /**
     * GCRA (Generic Cell Rate Algorithm) rather than a plain token bucket. A bucket
     * permits its whole capacity to drain in one burst, which is precisely what trips
     * a short-window limit; GCRA instead tracks a "theoretical arrival time" and
     * spaces calls evenly, allowing only a bounded burst. It is also cheap — one
     * float in the cache, no sliding-window bookkeeping.
     */
    private function paceBlock(): ?string
    {
        $perMinute = (int) config('services.zoho.inventory.rate_limit.per_minute', 60);

        if ($perMinute <= 0) {
            return null;
        }

        $emissionInterval = 60 / $perMinute;
        $burst = max(1, (int) config('services.zoho.inventory.rate_limit.burst', 10));
        $tolerance = $burst * $emissionInterval;

        $lock = Cache::lock(self::PREFIX.'gcra_lock', 5);

        try {
            // Fail open on lock contention: the daily budget is the hard ceiling, and
            // blocking a queue worker on a pacing lock would be worse than a brief
            // overshoot of the per-minute rate.
            if (! $lock->get()) {
                return null;
            }

            $now = microtime(true);
            $tat = max((float) Cache::get(self::TAT_KEY, $now), $now);
            $allowAt = $tat - $tolerance;

            if ($now < $allowAt) {
                $wait = round($allowAt - $now, 2);

                return "rate pacing — next call allowed in {$wait}s ({$perMinute}/min)";
            }

            Cache::put(self::TAT_KEY, $tat + $emissionInterval, now()->addMinutes(5));

            return null;
        } finally {
            $lock->release();
        }
    }

    // -------------------------------------------------------- classification

    /**
     * Structural classification, not string matching. The old check was
     * `str_contains($body, '"code":45')`, which silently missed `"code": 45` with a
     * space and every per-minute wording variant. Read the parsed code and the HTTP
     * status instead.
     */
    public function classify(int $status, array $body, string $raw): ZohoOutcome
    {
        $code = array_key_exists('code', $body) ? (int) $body['code'] : null;

        if ($this->looksRateLimited($status, $body, $raw)) {
            return ZohoOutcome::Transient;
        }

        // 5xx and 401 are worth retrying — the latter usually means a stale access
        // token, which the next attempt will refresh.
        if ($status >= 500 || $status === 401 || $status === 408) {
            return ZohoOutcome::Transient;
        }

        if ($status >= 200 && $status < 300 && ($code === 0 || $code === null)) {
            return ZohoOutcome::Success;
        }

        // Anything else is Zoho rejecting this record's content and it will keep
        // rejecting it: validation errors, invalid gst_no (code 2), missing fields.
        return ZohoOutcome::Permanent;
    }

    public function looksRateLimited(?int $status, array $body, ?string $raw): bool
    {
        if ($status === 429) {
            return true;
        }

        if (array_key_exists('code', $body) && (int) $body['code'] === self::CODE_RATE_LIMIT) {
            return true;
        }

        $text = strtolower((string) $raw);

        return $text !== '' && (
            str_contains($text, 'call rate limit')
            || str_contains($text, 'too many requests')
        );
    }

    // ------------------------------------------------------------ diagnostics

    /**
     * @return array{state: string, until: ?string, level: int, daily_used: int, daily_limit: int, run_calls: int, enabled: bool}
     */
    public function snapshot(): array
    {
        $breaker = $this->breaker();

        return [
            'state' => $breaker['state'],
            'until' => $breaker['until'],
            'level' => $breaker['level'],
            'daily_used' => $this->dailyUsage(),
            'daily_limit' => (int) config('services.zoho.inventory.rate_limit.per_day', 800),
            'run_calls' => $this->runCalls,
            'enabled' => $this->enabled(),
        ];
    }

    /** Test/ops helper — clears breaker and pacing state (not the daily counter). */
    public function reset(): void
    {
        Cache::forget(self::BREAKER_KEY);
        Cache::forget(self::PROBE_KEY);
        Cache::forget(self::TAT_KEY);
        Cache::forget(self::TRANSIENT_STREAK_KEY);
        $this->runCalls = 0;
        $this->holdsProbe = false;
    }
}
