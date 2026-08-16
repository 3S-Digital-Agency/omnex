<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ServerMetricSampleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'server_id' => $this->server_id,
            'cpu' => $this->cpu,
            'memory_used' => $this->memory_used,
            'memory_total' => $this->memory_total,
            'disk_used' => $this->disk_used,
            'disk_total' => $this->disk_total,
            'sampled_at' => $this->sampled_at?->toIso8601String(),
        ];
    }
}
