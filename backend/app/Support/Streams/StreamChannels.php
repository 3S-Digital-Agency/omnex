<?php

namespace App\Support\Streams;

/**
 * Canonical channel names shared by producers and the SSE endpoints so the
 * two never drift apart.
 */
final class StreamChannels
{
    public static function notifications(string $userId): string
    {
        return "notifications:{$userId}";
    }

    public static function activity(string $organizationId): string
    {
        return "activity:{$organizationId}";
    }
}
