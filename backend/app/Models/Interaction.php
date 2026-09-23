<?php

namespace App\Models;

use App\Enums\InteractionType;
use Database\Factories\InteractionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property InteractionType $type
 * @property Carbon $timestamp
 */
#[Fillable(['contact_id', 'type', 'timestamp', 'note', 'created_by', 'idempotency_key', 'request_hash'])]
class Interaction extends Model
{
    /** @use HasFactory<InteractionFactory> */
    use HasFactory;

    // Append-only / immutable: no update endpoint, so no updated_at column.
    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'type' => InteractionType::class,
            'timestamp' => 'datetime',
        ];
    }

    /** @return BelongsTo<Contact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
