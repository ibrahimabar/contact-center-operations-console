<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    /**
     * Gates GET /agents (the team-lead assignment dropdown / agent filter).
     */
    public function viewAny(User $user): bool
    {
        return $user->isTeamLead();
    }
}
