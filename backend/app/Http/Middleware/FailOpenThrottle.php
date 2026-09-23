<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use RedisException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Wraps Laravel's real throttle middleware (limiter resolution, 429
 * responses, headers — all unchanged) and only intervenes when the
 * *rate-limit check itself* fails because Redis is unreachable. In that
 * case the request is allowed through rather than surfacing a 500 — a
 * Redis outage should degrade rate limiting, not take down login or
 * interaction creation. See DECISIONS.md "Redis usage".
 */
class FailOpenThrottle
{
    public function __construct(private readonly ThrottleRequests $inner) {}

    public function handle(Request $request, Closure $next, mixed ...$args): Response
    {
        try {
            return $this->inner->handle($request, $next, ...$args);
        } catch (Throwable $e) {
            if (! $this->isConnectivityFailure($e)) {
                throw $e;
            }

            report($e);

            return $next($request);
        }
    }

    private function isConnectivityFailure(Throwable $e): bool
    {
        // REDIS_CLIENT is phpredis (see .env.example) throughout this app,
        // so RedisException covers real outages; the string fallback is a
        // safety net for wrapped/decorated exceptions from other layers.
        return $e instanceof RedisException
            || str_contains(strtolower($e->getMessage()), 'redis');
    }
}
