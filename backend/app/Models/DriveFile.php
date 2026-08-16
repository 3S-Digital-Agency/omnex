<?php

namespace App\Models;

use App\Support\Tenancy\HasTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DriveFile extends Model
{
    use HasFactory, HasUuids, HasTenant;

    protected $fillable = [
        'organization_id',
        'folder_id',
        'name',
        'storage_key',
        'mime_type',
        'size',
        'checksum',
        'version',
        'status',
        'trashed_at',
    ];

    protected $casts = [
        'size' => 'integer',
        'version' => 'integer',
        'trashed_at' => 'datetime',
    ];

    public function folder(): BelongsTo
    {
        return $this->belongsTo(DriveFolder::class, 'folder_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(DriveVersion::class, 'file_id')->orderByDesc('version');
    }
}
