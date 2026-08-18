<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SiteDeploymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'site_id' => $this->site_id,
            'number' => $this->number,
            'commit_sha' => $this->commit_sha,
            'status' => $this->status,
            'url' => $this->url,
            'preview_url' => $this->preview_url,
            'logs' => $this->logs,
            'deployed_at' => $this->deployed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
