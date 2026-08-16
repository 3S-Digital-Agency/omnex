<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DriveVersionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'file_id' => $this->file_id,
            'version' => $this->version,
            'size' => $this->size,
            'checksum' => $this->checksum,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
