<?php

use App\Exceptions\RequestInProgressException;
use App\Services\IdempotencyLockService;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;

it('fails open and still runs the callback when Redis is unavailable', function () {
    Cache::shouldReceive('lock')
        ->once()
        ->andThrow(new RedisException('Connection refused'));

    $service = new IdempotencyLockService;

    $result = $service->attempt('some-key', fn () => 'the work happened exactly once');

    expect($result)->toBe('the work happened exactly once');
});

it('runs the callback exactly once and releases the lock when Redis is healthy', function () {
    $lock = Mockery::mock(Lock::class);
    $lock->shouldReceive('get')->once()->andReturn(true);
    $lock->shouldReceive('release')->once();

    Cache::shouldReceive('lock')->once()->andReturn($lock);

    $calls = 0;
    $service = new IdempotencyLockService;
    $service->attempt('some-key', function () use (&$calls) {
        $calls++;

        return 'ok';
    });

    expect($calls)->toBe(1);
});

it('throws RequestInProgressException without running the callback when the lock is already held', function () {
    $lock = Mockery::mock(Lock::class);
    $lock->shouldReceive('get')->once()->andReturn(false);

    Cache::shouldReceive('lock')->once()->andReturn($lock);

    $calls = 0;
    $service = new IdempotencyLockService;

    expect(fn () => $service->attempt('some-key', function () use (&$calls) {
        $calls++;
    }))->toThrow(RequestInProgressException::class);

    expect($calls)->toBe(0);
});
