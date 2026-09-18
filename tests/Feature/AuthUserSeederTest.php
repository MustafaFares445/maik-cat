<?php

use App\Models\User;
use Database\Seeders\AuthUserSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('seeded test account has unlimited device access while admin remains restricted', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(AuthUserSeeder::class);

    $testUser = User::query()->where('email', 'test@example.com')->firstOrFail();
    $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();

    expect($testUser->unlimited_devices)->toBeTrue()
        ->and($admin->unlimited_devices)->toBeFalse();
});
