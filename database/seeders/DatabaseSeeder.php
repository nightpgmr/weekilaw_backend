<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Role;
use App\Models\Permission;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        $roles = [
            ['name' => 'Super Admin', 'slug' => 'super_admin'],
            ['name' => 'Admin', 'slug' => 'admin'],
            ['name' => 'Client', 'slug' => 'client'],
        ];

        foreach ($roles as $roleData) {
            Role::firstOrCreate(['slug' => $roleData['slug']], $roleData);
        }

        $permissions = [
            ['name' => 'Manage Users', 'slug' => 'manage_users'],
            ['name' => 'Manage Roles', 'slug' => 'manage_roles'],
            ['name' => 'Manage Permissions', 'slug' => 'manage_permissions'],
            ['name' => 'Access Admin', 'slug' => 'access_admin'],
            ['name' => 'Access Client', 'slug' => 'access_client'],
        ];

        foreach ($permissions as $permData) {
            Permission::firstOrCreate(['slug' => $permData['slug']], $permData);
        }

        $superAdminRole = Role::where('slug', 'super_admin')->first();
        $adminRole = Role::where('slug', 'admin')->first();
        $clientRole = Role::where('slug', 'client')->first();

        // Attach permissions
        if ($superAdminRole) {
            $superAdminRole->permissions()->sync(
                Permission::pluck('id')->all()
            );
        }
        if ($adminRole) {
            $adminRole->permissions()->sync(
                Permission::whereIn('slug', ['access_admin'])->pluck('id')->all()
            );
        }
        if ($clientRole) {
            $clientRole->permissions()->sync(
                Permission::whereIn('slug', ['access_client'])->pluck('id')->all()
            );
        }

        $super = User::firstOrCreate(
            ['email' => 'test@example.com'],
            [
                'name' => 'Test User',
                'role' => 'super_admin',
                'password' => 'password',
                'email_verified_at' => now(),
            ]
        );

        if ($superAdminRole && $super) {
            $super->roles()->sync([$superAdminRole->id]);
        }
    }
}
