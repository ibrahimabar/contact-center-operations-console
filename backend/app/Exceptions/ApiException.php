<?php

namespace App\Exceptions;

use Exception;

/**
 * Base for every domain-level exception that should reach the client as the
 * app's standard JSON error envelope (see DECISIONS.md "API conventions"):
 * { "message": ..., "code": "snake_case_code", ...extra }
 */
abstract class ApiException extends Exception
{
    abstract public function statusCode(): int;

    abstract public function errorCode(): string;

    /**
     * Extra fields merged into the JSON body alongside message/code, e.g.
     * the current server copy of a resource on a 409 conflict.
     *
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return [];
    }
}
