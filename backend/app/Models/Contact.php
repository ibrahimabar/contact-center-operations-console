<?php

namespace App\Models;

use App\Enums\ContactStatus;
use App\Enums\UserRole;
use Database\Factories\ContactFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property ContactStatus $status
 * @property Carbon $last_activity_at
 * @property int $version
 */
#[Fillable(['name', 'email', 'phone', 'company', 'assigned_agent_id', 'status', 'last_activity_at'])]
class Contact extends Model
{
    /** @use HasFactory<ContactFactory> */
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'status' => ContactStatus::class,
            'last_activity_at' => 'datetime',
            'version' => 'integer',
        ];
    }

    /**
     * Route-model-binding must be able to resolve an archived contact too
     * (a team lead views/restores archived contacts, and a versioned
     * update on one needs to reach ContactService::applyVersionedUpdate()
     * to produce the right 404 vs 409) — the default binding would 404
     * before the policy/service ever runs.
     */
    public function resolveRouteBinding($value, $field = null): self
    {
        return $this->withTrashed()->where($field ?? $this->getRouteKeyName(), $value)->firstOrFail();
    }

    protected static function booted(): void
    {
        static::creating(function (Contact $contact) {
            // NOT NULL column, no DB-level default (see the migration) —
            // a contact with no interactions yet is "active" as of creation.
            $contact->last_activity_at ??= $contact->freshTimestamp();

            // The `version` column does have a DB-level default (1), but
            // Eloquent doesn't re-fetch after an insert, so a model created
            // without explicitly setting `version` would otherwise report
            // it as null in the very API response that just created it.
            $contact->version ??= 1;
        });
    }

    /** @return BelongsTo<User, $this> */
    public function assignedAgent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_agent_id');
    }

    /** @return HasMany<Interaction, $this> */
    public function interactions(): HasMany
    {
        return $this->hasMany(Interaction::class);
    }

    /**
     * Scopes the query to what $user is allowed to see: an agent only ever
     * sees their own assigned contacts, a team lead sees everything. This is
     * the single place that rule is applied, so no endpoint can forget it
     * (see DECISIONS.md "Roles").
     *
     * @param  Builder<Contact>  $query
     * @return Builder<Contact>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->role === UserRole::Agent) {
            return $query->where('assigned_agent_id', $user->id);
        }

        return $query;
    }

    /**
     * @param  Builder<Contact>  $query
     * @return Builder<Contact>
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        // Boolean-mode, word-prefix search — see DECISIONS.md #6. Operator
        // characters are stripped so user input can't inject FULLTEXT
        // boolean-mode syntax (+/-/"/*/etc.), then every remaining word gets
        // a required-prefix match.
        $sanitized = preg_replace('/[+\-><()~*"@]+/', ' ', $term);
        $words = array_filter(preg_split('/\s+/', trim($sanitized)));

        if ($words === []) {
            return $query;
        }

        $booleanQuery = implode(' ', array_map(fn (string $word) => '+'.$word.'*', $words));

        return $query->whereFullText(
            ['name', 'email', 'phone', 'company'],
            $booleanQuery,
            ['mode' => 'boolean']
        );
    }
}
