<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            'organizations.read' => 'View organization details and members.',
            'organizations.manage' => 'Update organization settings and delete it.',
            'organizations.invite' => 'Invite new members to the organization.',
            'members.manage' => 'Change member roles and remove members.',
            'audit.read' => 'View the organization audit log.',
            'notifications.read' => 'View notifications.',
            'domains.read' => 'View domains and their DNS records.',
            'domains.manage' => 'Register, renew, transfer and configure domains.',
            'dns.read' => 'View DNS zones and records.',
            'dns.manage' => 'Create, update and roll back DNS records.',
            'storage.read' => 'List and download Drive folders and files.',
            'storage.manage' => 'Upload, version, trash and delete Drive files and folders.',
            'security.read' => 'View the security score and findings.',
            'security.manage' => 'Run security scans and dismiss or reopen findings.',
            'sites.read' => 'View sites and their deployments.',
            'sites.manage' => 'Create, deploy, roll back and delete sites.',
            'cloud.read' => 'View cloud servers and their operations.',
            'cloud.manage' => 'Provision, power and delete cloud servers.',
            'billing.read' => 'View plans, the current subscription and invoices.',
            'billing.manage' => 'Subscribe to plans and cancel the subscription.',
        ];

        foreach ($permissions as $key => $description) {
            Permission::firstOrCreate(['key' => $key], ['description' => $description]);
        }
    }
}
