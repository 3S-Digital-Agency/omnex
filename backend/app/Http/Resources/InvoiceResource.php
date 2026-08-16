<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'amount' => $this->amount,
            'discount' => $this->discount,
            'credit_applied' => $this->credit_applied,
            'amount_due' => $this->amount_due,
            'currency' => $this->currency,
            'status' => $this->status,
            'provider' => $this->provider,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'period_start' => $this->period_start?->toIso8601String(),
            'period_end' => $this->period_end?->toIso8601String(),
            'plan' => $this->whenLoaded('subscription', fn () => $this->subscription->plan
                ? new PlanResource($this->subscription->plan)
                : null),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
