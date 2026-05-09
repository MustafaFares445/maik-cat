<?php

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Schemas\UserForm;
use App\Filament\Resources\Users\Tables\UsersTable;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?string $navigationLabel = 'Admins';

    protected static string|\UnitEnum|null $navigationGroup = 'Access Management';

    public static function form(Schema $schema): Schema
    {
        return UserForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return UsersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with('roles')
            ->whereHas('roles', fn (Builder $query) => $query->whereIn('name', ['super_admin', 'admin', 'content_manager']));
    }

    public static function canViewAny(): bool
    {
        return self::canManageAdmins();
    }

    public static function canCreate(): bool
    {
        return self::canManageAdmins();
    }

    public static function canEdit($record): bool
    {
        return self::canManageAdmins();
    }

    public static function canDelete($record): bool
    {
        return self::canManageAdmins();
    }

    public static function canDeleteAny(): bool
    {
        return self::canManageAdmins();
    }

    private static function canManageAdmins(): bool
    {
        $user = auth()->user();

        return $user?->can('manage_admins') ?? false;
    }
}
