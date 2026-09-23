<?php

namespace App\Services;

use App\Exceptions\IdempotencyKeyReusedException;
use App\Exceptions\RequestInProgressException;
use App\Models\Contact;
use App\Models\Interaction;
use App\Models\User;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class InteractionService
{
    public function __construct(private readonly IdempotencyLockService $locks) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return CursorPaginator<int, Interaction>
     */
    public function list(Contact $contact, array $filters): CursorPaginator
    {
        $perPage = min((int) ($filters['per_page'] ?? 20), 100);

        return $contact->interactions()
            ->with('creator')
            ->orderBy('timestamp', 'desc')
            ->orderBy('id', 'desc')
            ->cursorPaginate($perPage)
            ->withQueryString();
    }

    /**
     * Two-layer idempotent create — see DECISIONS.md #2:
     *  1. A best-effort Redis lock avoids wasted contention between two
     *     near-simultaneous retries (fails open if Redis is unavailable).
     *  2. The DB unique constraint on (created_by, idempotency_key) is the
     *     actual correctness guarantee: on a collision we load the original
     *     row and either replay it (identical payload) or reject it
     *     (different payload reusing the same key).
     *
     * @param  array<string, mixed>  $validated
     * @return array{interaction: Interaction, replayed: bool}
     */
    public function create(User $user, Contact $contact, array $validated): array
    {
        $idempotencyKey = $validated['idempotency_key'];
        $lockKey = "{$user->id}:{$idempotencyKey}";

        return $this->locks->attempt($lockKey, function () use ($user, $contact, $validated, $idempotencyKey) {
            $requestHash = $this->fingerprint($contact->id, $validated);

            try {
                return DB::transaction(function () use ($user, $contact, $validated, $idempotencyKey, $requestHash) {
                    $timestamp = isset($validated['timestamp']) ? Carbon::parse($validated['timestamp']) : now();

                    $interaction = Interaction::create([
                        'contact_id' => $contact->id,
                        'type' => $validated['type'],
                        'timestamp' => $timestamp,
                        'note' => $validated['note'],
                        'created_by' => $user->id,
                        'idempotency_key' => $idempotencyKey,
                        'request_hash' => $requestHash,
                    ]);

                    // Deliberately raw DB::update, not the Eloquent model:
                    // this must NOT touch contacts.version/updated_at, or
                    // every new interaction would spuriously invalidate
                    // whichever agent is mid-edit on that contact (see
                    // DECISIONS.md #3 and #4). GREATEST() makes a backdated
                    // interaction a correct no-op instead of moving the
                    // timestamp backwards.
                    DB::update(
                        'update contacts set last_activity_at = GREATEST(last_activity_at, ?) where id = ? and deleted_at is null',
                        [$timestamp, $contact->id]
                    );

                    return ['interaction' => $interaction->load('creator'), 'replayed' => false];
                });
            } catch (QueryException $e) {
                if (! $this->isIdempotencyKeyCollision($e)) {
                    throw $e;
                }

                return $this->resolveCollision($user, $idempotencyKey, $requestHash);
            }
        });
    }

    /**
     * @return array{interaction: Interaction, replayed: bool}
     */
    private function resolveCollision(User $user, string $idempotencyKey, string $requestHash): array
    {
        $existing = Interaction::where('created_by', $user->id)
            ->where('idempotency_key', $idempotencyKey)
            ->with('creator')
            ->first();

        if ($existing === null) {
            // The row that caused the unique-constraint hit isn't visible to
            // us yet (its transaction hasn't committed). It will be in a
            // moment — tell the client to retry rather than erroring.
            throw new RequestInProgressException;
        }

        if ($existing->request_hash !== $requestHash) {
            throw new IdempotencyKeyReusedException;
        }

        return ['interaction' => $existing, 'replayed' => true];
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function fingerprint(int $contactId, array $validated): string
    {
        return hash('sha256', json_encode([
            'contact_id' => $contactId,
            'type' => $validated['type'],
            'timestamp' => $validated['timestamp'] ?? null,
            'note' => $validated['note'],
        ]));
    }

    private function isIdempotencyKeyCollision(QueryException $e): bool
    {
        return str_contains($e->getMessage(), 'interactions_creator_idempotency_key_unique');
    }
}
