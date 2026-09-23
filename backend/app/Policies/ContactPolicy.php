<?php

namespace App\Policies;

use App\Models\Contact;
use App\Models\User;

/**
 * Backend-enforced authorization for contacts — see DECISIONS.md "Roles".
 * `view`/`update` re-check ownership even though `Contact::scopeVisibleTo()`
 * already filters list queries, because a direct GET/PATCH /contacts/{id}
 * bypasses that scope and must not leak another agent's contact by ID guess.
 */
class ContactPolicy
{
    public function viewAny(User $user): bool
    {
        return true; // scoped by Contact::scopeVisibleTo() in the query itself
    }

    public function view(User $user, Contact $contact): bool
    {
        return $user->isTeamLead() || $contact->assigned_agent_id === $user->id;
    }

    public function create(User $user): bool
    {
        return true; // both roles may create; see DECISIONS.md "Domain rules"
    }

    public function update(User $user, Contact $contact): bool
    {
        return $user->isTeamLead() || $contact->assigned_agent_id === $user->id;
    }

    public function delete(User $user, Contact $contact): bool
    {
        return $user->isTeamLead();
    }

    public function restore(User $user, Contact $contact): bool
    {
        return $user->isTeamLead();
    }

    public function assign(User $user, Contact $contact): bool
    {
        return $user->isTeamLead();
    }
}
