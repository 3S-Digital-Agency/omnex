<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CouponRedemptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'organization_name' => $this->whenLoaded('organization', fn () => $this->organization?->name),
            'discount_amount' => $this->discount_amount,
            'currency' => $this->currency,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
