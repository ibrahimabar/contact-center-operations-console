<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Cache;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // A real browser always sends Origin/Referer; Laravel's test client
        // doesn't by default. Without one, Sanctum's
        // EnsureFrontendRequestsAreStateful can't tell this is a "stateful"
        // frontend request, so it skips the session middleware entirely and
        // $request->session() blows up — this mirrors the frontend's actual
        // origin (see DECISIONS.md "Auth") so every test exercises the same
        // cookie-session path the real SPA uses.
        $this->withHeader('Referer', 'http://localhost:5173');

        // Rate limiting and the idempotency lock live in Redis, which
        // RefreshDatabase does not touch — without this, a request count
        // left over from one test (e.g. the login-throttle test) leaks into
        // the next one that happens to reuse the same email/IP or
        // idempotency key. The dedicated test Redis logical DB
        // (REDIS_CACHE_DB=2, see phpunit.xml) makes this safe to flush
        // unconditionally.
        Cache::flush();
    }
}
