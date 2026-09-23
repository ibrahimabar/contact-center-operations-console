<?php

namespace App\Http\Resources;

use App\Models\Contact;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Contact */
class ContactResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'company' => $this->company,
            'status' => $this->status,
            'assigned_agent_id' => $this->assigned_agent_id,
            'assigned_agent' => new UserResource($this->whenLoaded('assignedAgent')),
            'last_activity_at' => $this->last_activity_at->toIso8601String(),
            // Optimistic-locking token — the client must send this back on
            // PATCH/assign. See DECISIONS.md #3.
            'version' => $this->version,
            'archived_at' => $this->deleted_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'recent_interactions' => InteractionResource::collection($this->whenLoaded('interactions')),
        ];
    }
}
