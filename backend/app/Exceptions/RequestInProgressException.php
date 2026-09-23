<?php

namespace App\Exceptions;

/**
 * A second copy of the same (user, Idempotency-Key) request arrived while
 * the first was still being processed — the Redis lock caught it before it
 * could race the DB unique constraint. Purely a concurrency-control signal:
 * the correct client behaviour is "wait a moment and retry", not "give up".
 * See DECISIONS.md #2.
 */
class RequestInProgressException extends ApiException
{
    public function __construct(private readonly int $retryAfterSeconds = 1)
    {
        parent::__construct('An identical request is already being processed.');
    }

    public function statusCode(): int
    {
        return 409;
    }

    public function errorCode(): string
    {
        return 'request_in_progress';
    }

    public function retryAfterSeconds(): int
    {
        return $this->retryAfterSeconds;
    }
}
