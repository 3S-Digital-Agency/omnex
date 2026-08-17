<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class LandingPage extends Model
{
    use HasUuids;

    public const TYPE_OFFER = 'offer';

    public const TYPE_PROMO = 'promo';

    public const TYPE_COMPARISON = 'comparison';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const TYPES = [self::TYPE_OFFER, self::TYPE_PROMO, self::TYPE_COMPARISON];

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_PUBLISHED];

    protected $fillable = [
        'slug',
        'type',
        'status',
        'content_en',
        'content_fr',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'content_en' => 'array',
            'content_fr' => 'array',
            'published_at' => 'datetime',
        ];
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PUBLISHED)
            ->whereNotNull('published_at');
    }

    public function scopeOfType(Builder $query, ?string $type): Builder
    {
        return $type ? $query->where('type', $type) : $query;
    }
}
