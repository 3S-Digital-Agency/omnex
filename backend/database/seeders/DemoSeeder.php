<?php

namespace Database\Seeders;

use App\Models\AuditLog;
use App\Models\DnsRecord;
use App\Models\DnsZone;
use App\Models\Domain;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Support\Notifications\NotificationService;
use Illuminate\Database\Seeder;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $owner = User::firstOrCreate(
            ['email' => 'demo@omnex.dev'],
            ['name' => 'Demo Owner', 'password' => 'password', 'email_verified_at' => now(), 'status' => 'active']
        );

        $developer = User::firstOrCreate(
            ['email' => 'dev@omnex.dev'],
            ['name' => 'Dev User', 'password' => 'password', 'email_verified_at' => now(), 'status' => 'active']
        );

        $organization = Organization::firstOrCreate(
            ['slug' => 'omnex-hq'],
            ['name' => 'OMNEX HQ', 'plan_tier' => 'free', 'status' => 'active']
        );

        $ownerRole = Role::where('key', 'owner')->firstOrFail();
        $developerRole = Role::where('key', 'developer')->firstOrFail();

        Membership::firstOrCreate(
            ['organization_id' => $organization->id, 'user_id' => $owner->id],
            ['role_id' => $ownerRole->id, 'status' => 'active', 'joined_at' => now()]
        );

        Membership::firstOrCreate(
            ['organization_id' => $organization->id, 'user_id' => $developer->id],
            ['role_id' => $developerRole->id, 'status' => 'active', 'joined_at' => now()]
        );

        AuditLog::firstOrCreate(
            ['action' => 'organization.created', 'resource_id' => $organization->id],
            [
                'organization_id' => $organization->id,
                'user_id' => $owner->id,
                'resource_type' => 'organization',
                'after' => ['name' => 'OMNEX HQ'],
                'result' => 'success',
            ]
        );

        NotificationService::send(
            $owner->id,
            'welcome',
            'Welcome to OMNEX',
            'Your OMNEX Cloud OS organization is ready.',
            ['organization_id' => $organization->id],
            $organization->id
        );

        $this->seedDemoDomains($organization);
    }

    private function seedDemoDomains(Organization $organization): void
    {
        $this->seedDomain($organization, 'omnex.dev', [
            ['type' => 'A', 'name' => '@', 'content' => '192.0.2.10', 'ttl' => 3600],
            ['type' => 'CNAME', 'name' => 'www', 'content' => '@', 'ttl' => 3600],
            ['type' => 'TXT', 'name' => '@', 'content' => 'v=spf1 include:spf.omnex.io ~all', 'ttl' => 3600],
        ]);

        $this->seedDomain($organization, 'omnex.io', [
            ['type' => 'A', 'name' => '@', 'content' => '198.51.100.7', 'ttl' => 3600],
            ['type' => 'MX', 'name' => '@', 'content' => 'mail', 'priority' => 10, 'ttl' => 3600],
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $records
     */
    private function seedDomain(Organization $organization, string $name, array $records): void
    {
        $domain = Domain::firstOrCreate(
            ['name' => $name],
            [
                'organization_id' => $organization->id,
                'status' => 'active',
                'provider' => 'sandbox',
                'registered_at' => now()->subDays(120),
                'expires_at' => now()->addDays(245),
                'auto_renew' => true,
                'privacy_protection' => true,
                'transfer_lock' => true,
                'nameservers' => ['ns1.omnex.io', 'ns2.omnex.io'],
            ]
        );

        $zone = DnsZone::firstOrCreate(
            ['domain_id' => $domain->id],
            [
                'organization_id' => $organization->id,
                'provider' => 'sandbox',
                'status' => 'active',
            ]
        );

        foreach ($records as $record) {
            DnsRecord::firstOrCreate(
                [
                    'zone_id' => $zone->id,
                    'type' => $record['type'],
                    'name' => $record['name'],
                    'content' => $record['content'],
                ],
                [
                    'organization_id' => $organization->id,
                    'ttl' => $record['ttl'] ?? 3600,
                    'priority' => $record['priority'] ?? null,
                ]
            );
        }
    }
}
