<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            'owner' => [
                'name' => 'Owner',
                'description' => 'Full control over the organization.',
                'permissions' => '*',
            ],
            'admin' => [
                'name' => 'Admin',
                'description' => 'Manage members, settings and services (cannot delete the organization).',
                'permissions' => [
                    'organizations.read',
                    'organizations.invite',
                    'members.manage',
                    'audit.read',
                    'notifications.read',
                    'domains.read',
                    'domains.manage',
                    'dns.read',
                    'dns.manage',
                    'storage.read',
                    'storage.manage',
                ],
            ],
            'developer' => [
                'name' => 'Developer',
                'description' => 'Read access to the organization, services and audit log.',
                'permissions' => [
                    'organizations.read',
                    'audit.read',
                    'notifications.read',
                    'domains.read',
                    'dns.read',
                    'storage.read',
                ],
            ],
            'viewer' => [
                'name' => 'Viewer',
                'description' => 'Read-only access.',
                'permissions' => [
                    'organizations.read',
                    'notifications.read',
                    'domains.read',
                    'dns.read',
                    'storage.read',
                ],
            ],
        ];

        foreach ($roles as $key => $definition) {
            /** @var Role $role */
            $role = Role::firstOrCreate(
                ['key' => $key],
                [
                    'name' => $definition['name'],
                    'description' => $definition['description'],
                    'is_system' => true,
                ]
            );

            $permissions = $definition['permissions'] === '*'
                ? Permission::all()
                : Permission::whereIn('key', $definition['permissions'])->get();

            $role->permissions()->sync($permissions->pluck('id'));
        }
    }
}
