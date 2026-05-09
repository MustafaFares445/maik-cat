<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = [
            'view_statistics',
            'manage_items',
            'manage_car_groups',
            'manage_admins',
            'manage_app_users',
            'manage_notification_audiences',
            'send_notifications',
            'view_notification_history',
        ];

        foreach ($permissions as $permission) {
            Permission::query()->firstOrCreate([
                'name' => $permission,
                'guard_name' => 'web',
            ]);
        }

        $superAdmin = Role::query()->firstOrCreate([
            'name' => 'super_admin',
            'guard_name' => 'web',
        ]);

        $admin = Role::query()->firstOrCreate([
            'name' => 'admin',
            'guard_name' => 'web',
        ]);

        $contentManager = Role::query()->firstOrCreate([
            'name' => 'content_manager',
            'guard_name' => 'web',
        ]);

        Role::query()->firstOrCreate([
            'name' => 'app_user',
            'guard_name' => 'web',
        ]);

        $superAdmin->syncPermissions($permissions);

        $admin->syncPermissions([
            'view_statistics',
            'manage_items',
            'manage_car_groups',
            'manage_app_users',
            'manage_notification_audiences',
            'send_notifications',
            'view_notification_history',
        ]);

        $contentManager->syncPermissions([
            'view_statistics',
            'manage_items',
            'manage_car_groups',
            'manage_notification_audiences',
            'send_notifications',
            'view_notification_history',
        ]);
    }
}
