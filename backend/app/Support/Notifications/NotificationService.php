<?php

namespace App\Support\Notifications;

use App\Models\Notification;
use App\Support\Tenancy\TenantContext;

final class NotificationService
{
    public static function send(
        string $userId,
        string $type,
        string $title,
        ?string $body = null,
        array $data = [],
        ?string $organizationId = null,
    ): Notification {
        return Notification::create([
            'organization_id' => $organizationId ?? app(TenantContext::class)->id(),
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'data' => $data,
        ]);
    }
}
