<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class AgentController extends Controller
{
    /**
     * Active agents, for the team-lead assign dropdown and the "filter by
     * agent" control. Team-lead only — see UserPolicy.
     */
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        $agents = User::query()
            ->where('role', UserRole::Agent)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return UserResource::collection($agents)->response();
    }
}
