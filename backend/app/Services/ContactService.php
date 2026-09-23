<?php

namespace App\Services;

use App\Enums\ContactStatus;
use App\Exceptions\VersionConflictException;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Support\Facades\DB;

class ContactService
{
    /**
     * @param  array<string, mixed>  $filters
     * @return CursorPaginator<int, Contact>
     */
    public function list(User $user, array $filters): CursorPaginator
    {
        $perPage = min((int) ($filters['per_page'] ?? 25), 100);

        $sort = in_array($filters['sort'] ?? null, ['created_at', 'last_activity_at'], true)
            ? $filters['sort']
            : 'created_at';

        $direction = ($filters['direction'] ?? null) === 'asc' ? 'asc' : 'desc';

        $query = Contact::query()->visibleTo($user)->with('assignedAgent');

        if ($user->isTeamLead()) {
            match ($filters['archived'] ?? 'without') {
                'only' => $query->onlyTrashed(),
                'with' => $query->withTrashed(),
                default => null, // default scope already excludes archived rows
            };
        }

        $query->search($filters['q'] ?? null);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['assigned_agent_id'])) {
            $query->where('assigned_agent_id', $filters['assigned_agent_id']);
        } elseif (($filters['unassigned'] ?? false) && $user->isTeamLead()) {
            $query->whereNull('assigned_agent_id');
        }

        // An explicit `id` tiebreaker keeps the keyset stable when the sort
        // column has duplicate values (e.g. two contacts created in the
        // same second) — without it, cursorPaginate can skip or repeat rows.
        return $query->orderBy($sort, $direction)
            ->orderBy('id', $direction)
            ->cursorPaginate($perPage)
            ->withQueryString();
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function create(User $user, array $validated): Contact
    {
        // An agent can only ever create contacts assigned to themself — any
        // assigned_agent_id they submitted is discarded here, not rejected,
        // per DECISIONS.md "Domain rules".
        $validated['assigned_agent_id'] = $user->isAgent()
            ? $user->id
            : ($validated['assigned_agent_id'] ?? null);

        $validated['status'] ??= ContactStatus::New;

        return Contact::create($validated);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function update(Contact $contact, array $validated): Contact
    {
        $expectedVersion = $validated['version'];
        unset($validated['version']);

        return $this->applyVersionedUpdate($contact, $expectedVersion, $validated);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function assign(Contact $contact, array $validated): Contact
    {
        $expectedVersion = $validated['version'];

        return $this->applyVersionedUpdate($contact, $expectedVersion, [
            'assigned_agent_id' => $validated['assigned_agent_id'] ?? null,
        ]);
    }

    public function archive(Contact $contact): void
    {
        $contact->delete();
    }

    public function restore(Contact $contact): void
    {
        $contact->restore();
    }

    /**
     * A single atomic conditional UPDATE, not read-compare-write: the WHERE
     * clause itself enforces the optimistic-lock check, so two concurrent
     * requests can never both believe they won. See DECISIONS.md #3.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function applyVersionedUpdate(Contact $contact, int $expectedVersion, array $attributes): Contact
    {
        $affected = Contact::where('id', $contact->id)
            ->where('version', $expectedVersion)
            ->update(array_merge($attributes, [
                'version' => DB::raw('version + 1'),
            ]));

        if ($affected === 0) {
            // Contact::where()->update() excludes archived rows via the
            // default SoftDeletes scope, so a 0-row result means either a
            // version mismatch or the contact was archived/removed
            // concurrently — re-check (including trashed) to tell them apart.
            $current = Contact::withTrashed()->find($contact->id);

            if ($current === null || $current->trashed()) {
                abort(404);
            }

            throw new VersionConflictException($current->loadMissing('assignedAgent'));
        }

        return $contact->refresh()->loadMissing('assignedAgent');
    }
}
