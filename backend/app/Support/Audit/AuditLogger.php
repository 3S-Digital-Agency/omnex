<?php

namespace App\Support\Audit;

use App\Models\AuditLog;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;

final class AuditLogger
{
    public static function record(
        string $action,
        ?string $resourceType = null,
        ?string $resourceId = null,
        ?array $before = null,
        ?array $after = null,
        string $result = 'success',
    ): AuditLog {
        /** @var Request|null $request */
        $request = app('request');

        return AuditLog::create([
            'organization_id' => app(TenantContext::class)->id(),
            'user_id' => $request?->user()?->id,
            'action' => $action,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'before' => $before,
            'after' => $after,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'result' => $result,
        ]);
    }
}
