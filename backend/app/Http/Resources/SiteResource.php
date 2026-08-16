<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SiteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'framework' => $this->framework,
            'git_url' => $this->git_url,
            'git_branch' => $this->git_branch,
            'provider' => $this->provider,
            'status' => $this->status,
            'url' => $this->url,
            'current_deployment_id' => $this->current_deployment_id,
            // Values never leave the API — only the key names are exposed.
            'environment_variable_keys' => $this->environmentKeys(),
            'deployments_count' => $this->whenCounted('deployments'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
