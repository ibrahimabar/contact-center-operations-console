<?php

namespace App\Exceptions;

use App\Http\Resources\ContactResource;
use App\Models\Contact;

/**
 * Thrown when a PATCH/assign's `version` doesn't match the row's current
 * version — see DECISIONS.md #3. Carries the current server copy so the
 * client can show a "this changed, reload?" prompt without a second request.
 */
class VersionConflictException extends ApiException
{
    public function __construct(private readonly Contact $current)
    {
        parent::__construct('The contact was modified by someone else since you last loaded it.');
    }

    public function statusCode(): int
    {
        return 409;
    }

    public function errorCode(): string
    {
        return 'version_conflict';
    }

    public function context(): array
    {
        return [
            'current' => new ContactResource($this->current),
        ];
    }
}
