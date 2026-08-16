<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DomainResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'status' => $this->status,
            'provider' => $this->provider,
            'registered_at' => $this->registered_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'auto_renew' => $this->auto_renew,
            'privacy_protection' => $this->privacy_protection,
            'transfer_lock' => $this->transfer_lock,
            'nameservers' => $this->nameservers,
            'contacts' => $this->contacts,
            'created_at' => $this->created_at?->toIso8601String(),
            'zone_id' => $this->whenLoaded('zone', fn () => $this->zone?->id),
        ];
    }
}
