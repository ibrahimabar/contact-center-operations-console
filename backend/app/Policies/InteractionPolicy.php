<?php

namespace App\Policies;

use App\Models\Contact;
use App\Models\User;

/**
 * Interactions are always authorized through their parent contact — an
 * agent may add/view interactions only on contacts assigned to them, a team
 * lead on any contact. Called as, e.g.,
 * `$this->authorize('create', [Interaction::class, $contact])`.
 */
class InteractionPolicy
{
    public function viewAny(User $user, Contact $contact): bool
    {
        return $user->isTeamLead() || $contact->assigned_agent_id === $user->id;
    }

    public function create(User $user, Contact $contact): bool
    {
        return $user->isTeamLead() || $contact->assigned_agent_id === $user->id;
    }
}
