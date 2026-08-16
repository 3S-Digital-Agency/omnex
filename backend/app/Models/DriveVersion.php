<?php

namespace App\Models;

use App\Support\Tenancy\HasTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DriveVersion extends Model
{
    use HasFactory, HasUuids, HasTenant;

    protected $fillable = [
        'organization_id',
        'file_id',
        'version',
        'storage_key',
        'size',
        'checksum',
    ];

    protected $casts = [
        'version' => 'integer',
        'size' => 'integer',
    ];

    public function file(): BelongsTo
    {
        return $this->belongsTo(DriveFile::class);
    }
}
