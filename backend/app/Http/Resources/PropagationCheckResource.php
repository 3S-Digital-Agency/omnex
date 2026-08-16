<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PropagationCheckResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nameserver' => $this->nameserver,
            'record_type' => $this->record_type,
            'record_name' => $this->record_name,
            'status' => $this->status,
            'expected' => $this->expected,
            'observed' => $this->observed,
            'checked_at' => $this->checked_at?->toIso8601String(),
        ];
    }
}
