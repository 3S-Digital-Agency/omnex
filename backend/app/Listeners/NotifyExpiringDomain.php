<?php

namespace App\Listeners;

use App\Events\DomainExpiring;
use App\Models\Membership;
use App\Support\Notifications\NotificationService;

class NotifyExpiringDomain
{
    public function handle(DomainExpiring $event): void
    {
        $domain = $event->domain;

        $ownerIds = Membership::withoutTenancy()
            ->where('organization_id', $domain->organization_id)
            ->where('status', 'active')
            ->whereHas('role', fn ($query) => $query->whereIn('key', ['owner', 'admin']))
            ->pluck('user_id');

        foreach ($ownerIds as $userId) {
            NotificationService::send(
                $userId,
                'domain.expiring',
                "Domain expiring: {$domain->name}",
                "{$domain->name} expires in {$event->days} days.",
                ['domain_id' => $domain->id, 'expires_at' => $domain->expires_at?->toIso8601String(), 'route' => '/domains/'.$domain->id],
                $domain->organization_id,
                'warning',
            );
        }
    }
}
