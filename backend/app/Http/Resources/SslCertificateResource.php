<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SslCertificateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'domain_id' => $this->domain_id,
            'domain' => $this->whenLoaded('domain', fn () => $this->domain?->name),
            'provider' => $this->provider,
            'external_id' => $this->external_id,
            'status' => $this->status,
            'issuer' => $this->issuer,
            'issued_at' => $this->issued_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'auto_renew' => $this->auto_renew,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
