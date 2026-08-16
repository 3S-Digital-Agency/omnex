<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ServerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'region' => $this->region,
            'plan' => $this->plan,
            'image' => $this->image,
            'provider' => $this->provider,
            'status' => $this->status,
            'ipv4' => $this->ipv4,
            'ipv6' => $this->ipv6,
            'ssh_key' => $this->ssh_key,
            'ssh_key_id' => $this->ssh_key_id,
            'tags' => $this->tags ?? [],
            'snapshot_frequency' => $this->snapshot_frequency ?? 'disabled',
            'snapshot_retention_days' => (int) ($this->snapshot_retention_days ?? 7),
            'last_snapshot_at' => $this->last_snapshot_at?->toIso8601String(),
            'operations_count' => $this->whenCounted('operations'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
