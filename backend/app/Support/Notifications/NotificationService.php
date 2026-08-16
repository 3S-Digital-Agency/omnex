<?php

namespace App\Support\Notifications;

use App\Http\Resources\NotificationResource;
use App\Models\Notification;
use App\Support\Streams\StreamBroker;
use App\Support\Streams\StreamChannels;
use App\Support\Tenancy\TenantContext;

final class NotificationService
{
    /**
     * @param  array<string, mixed>  $data  extra payload; a `route` key makes
     *                                      the notification clickable in the UI
     */
    public static function send(
        string $userId,
        string $type,
        string $title,
        ?string $body = null,
        array $data = [],
        ?string $organizationId = null,
        string $severity = 'info',
    ): Notification {
        $notification = Notification::create([
            'organization_id' => $organizationId ?? app(TenantContext::class)->id(),
            'user_id' => $userId,
            'type' => $type,
            'severity' => $severity,
            'title' => $title,
            'body' => $body,
            'data' => $data,
        ]);

        // Broadcast to any open SSE stream for this user (real-time delivery).
        app(StreamBroker::class)->publish(
            StreamChannels::notifications($userId),
            (new NotificationResource($notification))->resolve(),
        );

        return $notification;
    }
}
