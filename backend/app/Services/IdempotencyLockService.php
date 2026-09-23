<?php

namespace App\Services;

use App\Exceptions\RequestInProgressException;
use Closure;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * A short-lived Redis lock that stops two near-simultaneous retries of the
 * same idempotent request from both racing the database — see DECISIONS.md
 * #2. This is defense-in-depth, not the correctness mechanism: the database
 * unique constraint on (created_by, idempotency_key) is what actually
 * guarantees no duplicate is ever persisted. Accordingly, if Redis itself is
 * unavailable, this fails *open* (proceeds without a lock, logs a warning)
 * rather than blocking the request — a Redis outage must degrade rate
 * limiting and lock contention, never correctness.
 */
class IdempotencyLockService
{
    private const LOCK_SECONDS = 10;

    public function attempt(string $key, Closure $callback): mixed
    {
        $lock = null;
        $acquired = true;

        try {
            $lock = Cache::lock('idempotency:'.$key, self::LOCK_SECONDS);
            $acquired = $lock->get();
        } catch (Throwable $e) {
            report($e);
            $lock = null;
            $acquired = true;
        }

        if (! $acquired) {
            throw new RequestInProgressException;
        }

        try {
            return $callback();
        } finally {
            $this->safeRelease($lock);
        }
    }

    private function safeRelease(?Lock $lock): void
    {
        if ($lock === null) {
            return;
        }

        try {
            $lock->release();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
