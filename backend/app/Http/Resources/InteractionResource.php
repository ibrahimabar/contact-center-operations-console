<?php

namespace App\Http\Resources;

use App\Models\Interaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Interaction */
class InteractionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'contact_id' => $this->contact_id,
            'type' => $this->type,
            'timestamp' => $this->timestamp->toIso8601String(),
            'note' => $this->note,
            'created_by' => new UserResource($this->whenLoaded('creator')),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
