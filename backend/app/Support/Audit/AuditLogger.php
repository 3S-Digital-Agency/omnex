<?php

namespace App\Support\Audit;

use App\Models\AuditLog;
use App\Support\Activity\ActivityPresenter;
use App\Support\Streams\StreamBroker;
use App\Support\Streams\StreamChannels;
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

        $log = AuditLog::create([
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

        // Broadcast to any open SSE stream for this tenant (real-time activity).
        if ($log->organization_id !== null) {
            app(StreamBroker::class)->publish(
                StreamChannels::activity($log->organization_id),
                ActivityPresenter::toArray($log),
            );
        }

        return $log;
    }
}
