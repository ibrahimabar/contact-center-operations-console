<?php

namespace App\Exceptions;

/**
 * The same Idempotency-Key was sent with a materially different payload than
 * the original request — see DECISIONS.md #2. Unlike a true retry (which
 * replays the original response), this is a client bug and must not be
 * silently treated as a duplicate.
 */
class IdempotencyKeyReusedException extends ApiException
{
    public function __construct()
    {
        parent::__construct('This idempotency key was already used for a different request.');
    }

    public function statusCode(): int
    {
        return 422;
    }

    public function errorCode(): string
    {
        return 'idempotency_key_reused';
    }
}
