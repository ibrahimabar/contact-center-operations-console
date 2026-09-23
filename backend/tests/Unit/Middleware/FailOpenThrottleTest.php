<?php

use App\Http\Middleware\FailOpenThrottle;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;

it('lets the request through when the throttle check itself fails because Redis is down', function () {
    $inner = Mockery::mock(ThrottleRequests::class);
    $inner->shouldReceive('handle')->once()->andThrow(new RedisException('Connection refused'));

    $middleware = new FailOpenThrottle($inner);
    $request = Request::create('/api/v1/contacts');

    $response = $middleware->handle($request, fn () => response('passed through'), 'api');

    expect($response->getContent())->toBe('passed through');
});

it('still enforces a normal 429 when the throttle check succeeds', function () {
    $inner = Mockery::mock(ThrottleRequests::class);
    $inner->shouldReceive('handle')->once()->andReturn(response('Too Many Attempts.', 429));

    $middleware = new FailOpenThrottle($inner);
    $request = Request::create('/api/v1/contacts');

    $response = $middleware->handle($request, fn () => response('should not reach here'), 'api');

    expect($response->getStatusCode())->toBe(429);
});

it('does not swallow an unrelated exception from the inner throttle middleware', function () {
    $inner = Mockery::mock(ThrottleRequests::class);
    $inner->shouldReceive('handle')->once()->andThrow(new RuntimeException('something unrelated broke'));

    $middleware = new FailOpenThrottle($inner);
    $request = Request::create('/api/v1/contacts');

    expect(fn () => $middleware->handle($request, fn () => response('unreachable'), 'api'))
        ->toThrow(RuntimeException::class, 'something unrelated broke');
});
