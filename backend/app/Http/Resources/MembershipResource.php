<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MembershipResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'joined_at' => $this->joined_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'role' => $this->whenLoaded('role', fn () => new RoleResource($this->role)),
            'user' => $this->whenLoaded('user', fn () => new UserResource($this->user)),
            'organization' => $this->whenLoaded('organization', fn () => new OrganizationResource($this->organization)),
        ];
    }
}
