<?php

namespace App\Listeners;

use App\Events\DnsRecordChanged;
use App\Events\DnssecChanged;
use App\Events\DomainExpiring;
use App\Events\DomainRegistered;
use App\Events\DomainRenewed;
use App\Events\DomainTransferred;
use App\Events\DomainUpdated;
use App\Models\AuditLog;

/**
 * Domain/DNS modules are event-driven: services dispatch events and this
 * listener is the single place that turns them into the immutable audit
 * stream the Command Center / Activity feed reads from.
 */
class DomainEventAuditor
{
    public function handle(object $event): void
    {
        $entry = $this->entryFor($event);

        if ($entry === null) {
            return;
        }

        AuditLog::create($entry);
    }

    /**
     * @return array{organization_id: ?string, user_id: ?string, action: string, resource_type: ?string, resource_id: ?string, before: ?array, after: ?array, ip_address: ?string, user_agent: ?string, result: string}|null
     */
    private function entryFor(object $event): ?array
    {
        if ($event instanceof DomainRegistered) {
            return $this->entry(
                $event->domain->organization_id,
                'domain.registered',
                'domain',
                $event->domain->id,
                null,
                ['name' => $event->domain->name],
            );
        }

        if ($event instanceof DomainRenewed) {
            return $this->entry(
                $event->domain->organization_id,
                'domain.renewed',
                'domain',
                $event->domain->id,
                null,
                ['name' => $event->domain->name, 'years' => $event->years],
            );
        }

        if ($event instanceof DomainTransferred) {
            return $this->entry(
                $event->domain->organization_id,
                'domain.transferred',
                'domain',
                $event->domain->id,
                null,
                ['name' => $event->domain->name],
            );
        }

        if ($event instanceof DomainUpdated) {
            return $this->entry(
                $event->domain->organization_id,
                'domain.updated',
                'domain',
                $event->domain->id,
                $event->before,
                $event->after,
            );
        }

        if ($event instanceof DomainExpiring) {
            return $this->entry(
                $event->domain->organization_id,
                'domain.expiring',
                'domain',
                $event->domain->id,
                null,
                ['name' => $event->domain->name, 'days' => $event->days],
            );
        }

        if ($event instanceof DnsRecordChanged) {
            return $this->entry(
                $event->zone->organization_id,
                'dns.record_'.$event->action,
                'dns_record',
                $event->record?->id,
                $event->before,
                $event->after,
            );
        }

        if ($event instanceof DnssecChanged) {
            return $this->entry(
                $event->zone->organization_id,
                'dns.dnssec_'.$event->action,
                'dns_zone',
                $event->zone->id,
                $event->before,
                $event->after,
            );
        }

        return null;
    }

    /**
     * @return array{organization_id: ?string, user_id: ?string, action: string, resource_type: ?string, resource_id: ?string, before: ?array, after: ?array, ip_address: ?string, user_agent: ?string, result: string}
     */
    private function entry(
        ?string $organizationId,
        string $action,
        ?string $resourceType,
        ?string $resourceId,
        ?array $before,
        ?array $after,
    ): array {
        $request = app('request');

        return [
            'organization_id' => $organizationId,
            'user_id' => $request?->user()?->id,
            'action' => $action,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'before' => $before,
            'after' => $after,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'result' => 'success',
        ];
    }
}
