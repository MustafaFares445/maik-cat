<?php

namespace App\Filament\Resources\AppUsers;

use App\Filament\Resources\AppUsers\Pages\CreateAppUser;
use App\Filament\Resources\AppUsers\Pages\EditAppUser;
use App\Filament\Resources\AppUsers\Pages\ListAppUsers;
use App\Filament\Resources\AppUsers\Schemas\AppUserForm;
use App\Filament\Resources\AppUsers\Tables\AppUsersTable;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AppUserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDevicePhoneMobile;

    protected static ?string $navigationLabel = 'App Users';

    protected static string|\UnitEnum|null $navigationGroup = 'Access Management';

    public static function form(Schema $schema): Schema
    {
        return AppUserForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AppUsersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAppUsers::route('/'),
            'create' => CreateAppUser::route('/create'),
            'edit' => EditAppUser::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with('roles')
            ->where(function (Builder $query): void {
                $query
                    ->whereHas('roles', fn (Builder $roleQuery) => $roleQuery->where('name', 'app_user'))
                    ->orWhereDoesntHave('roles');
            })
            ->whereDoesntHave('roles', fn (Builder $roleQuery) => $roleQuery->whereIn('name', ['super_admin', 'admin', 'content_manager']));
    }

    public static function canViewAny(): bool
    {
        return self::canManageAppUsers();
    }

    public static function canCreate(): bool
    {
        return self::canManageAppUsers();
    }

    public static function canEdit($record): bool
    {
        return self::canManageAppUsers();
    }

    public static function canDelete($record): bool
    {
        return self::canManageAppUsers();
    }

    public static function canDeleteAny(): bool
    {
        return self::canManageAppUsers();
    }

    private static function canManageAppUsers(): bool
    {
        $user = auth()->user();

        return $user?->can('manage_app_users') ?? false;
    }
}
