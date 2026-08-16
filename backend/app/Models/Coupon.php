<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Coupon extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'code',
        'name',
        'description',
        'discount_type',
        'discount_value',
        'currency',
        'stripe_coupon_id',
        'max_redemptions',
        'times_redeemed',
        'active',
        'expires_at',
    ];

    protected $casts = [
        'discount_value' => 'integer',
        'max_redemptions' => 'integer',
        'times_redeemed' => 'integer',
        'active' => 'boolean',
        'expires_at' => 'datetime',
    ];

    public function redemptions(): HasMany
    {
        return $this->hasMany(CouponRedemption::class, 'coupon_id');
    }

    /** Whether the coupon can still be redeemed right now. */
    public function isValid(): bool
    {
        if (! $this->active) {
            return false;
        }

        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return false;
        }

        return $this->max_redemptions === null || $this->times_redeemed < $this->max_redemptions;
    }

    /**
     * Discount (in cents, capped at the amount) this coupon applies to a
     * given list price.
     */
    public function discountFor(int $amountCents): int
    {
        if (! $this->isValid() || $amountCents <= 0) {
            return 0;
        }

        $discount = $this->discount_type === 'percent'
            ? (int) round($amountCents * $this->discount_value / 100)
            : $this->discount_value;

        return min($discount, $amountCents);
    }
}
