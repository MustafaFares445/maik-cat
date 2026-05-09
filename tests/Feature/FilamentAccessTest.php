<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

test('active super admin can access filament panel', function () {
    Role::query()->firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);

    $user = User::factory()->create([
        'is_active' => true,
    ]);
    $user->assignRole('super_admin');

    $response = $this->actingAs($user)->get('/admin');

    $response->assertOk();
});

test('app users cannot access filament panel', function () {
    Role::query()->firstOrCreate(['name' => 'app_user', 'guard_name' => 'web']);

    $user = User::factory()->create([
        'is_active' => true,
    ]);
    $user->assignRole('app_user');

    $response = $this->actingAs($user)->get('/admin');

    $response->assertForbidden();
});

test('inactive admins cannot access filament panel', function () {
    Role::query()->firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

    $user = User::factory()->create([
        'is_active' => false,
    ]);
    $user->assignRole('admin');

    $response = $this->actingAs($user)->get('/admin');

    $response->assertForbidden();
});
