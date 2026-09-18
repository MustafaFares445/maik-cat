<?php

use App\Filament\Resources\AppUsers\Pages\ListAppUsers;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Permission::query()->firstOrCreate(['name' => 'manage_app_users', 'guard_name' => 'web']);
    Role::query()->firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

    $this->admin = User::factory()->create(['is_active' => true]);
    $this->admin->assignRole('admin');
    $this->admin->givePermissionTo('manage_app_users');
});

test('admin can reset an app users authorized device from the table', function (): void {
    $appUser = User::factory()->create([
        'email' => 'bound-user@example.com',
        'authorized_device_token' => 'installation-token-a',
        'device_bound_at' => now()->subHour(),
        'fcm_token' => 'installation-token-a',
    ]);
    $appUser->createToken('mobile-api-token');

    $action = TestAction::make('resetAuthorizedDevice')->table($appUser);

    Livewire::actingAs($this->admin)
        ->test(ListAppUsers::class)
        ->assertActionVisible($action)
        ->callAction($action)
        ->assertNotified('Authorized device reset');

    $appUser->refresh();

    expect($appUser->authorized_device_token)->toBeNull()
        ->and($appUser->device_bound_at)->toBeNull()
        ->and($appUser->fcm_token)->toBeNull()
        ->and($appUser->tokens()->count())->toBe(0);
});

test('reset device action is hidden until the account is bound', function (): void {
    $appUser = User::factory()->create([
        'authorized_device_token' => null,
        'device_bound_at' => null,
    ]);

    Livewire::actingAs($this->admin)
        ->test(ListAppUsers::class)
        ->assertActionHidden(TestAction::make('resetAuthorizedDevice')->table($appUser));
});
