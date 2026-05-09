<?php

namespace App\Filament\Resources\CarGroups;

use App\Filament\Resources\CarGroups\Pages\CreateCarGroup;
use App\Filament\Resources\CarGroups\Pages\EditCarGroup;
use App\Filament\Resources\CarGroups\Pages\ListCarGroups;
use App\Filament\Resources\CarGroups\Schemas\CarGroupForm;
use App\Filament\Resources\CarGroups\Tables\CarGroupsTable;
use App\Models\CarGroup;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CarGroupResource extends Resource
{
    protected static ?string $model = CarGroup::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?string $navigationLabel = 'Car Groups';

    protected static string|\UnitEnum|null $navigationGroup = 'Catalog Management';

    public static function form(Schema $schema): Schema
    {
        return CarGroupForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CarGroupsTable::configure($table);
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
            'index' => ListCarGroups::route('/'),
            'create' => CreateCarGroup::route('/create'),
            'edit' => EditCarGroup::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withCount(['children', 'items']);
    }

    public static function canViewAny(): bool
    {
        return self::canManageCarGroups();
    }

    public static function canCreate(): bool
    {
        return self::canManageCarGroups();
    }

    public static function canEdit($record): bool
    {
        return self::canManageCarGroups();
    }

    public static function canDelete($record): bool
    {
        return self::canManageCarGroups();
    }

    public static function canDeleteAny(): bool
    {
        return self::canManageCarGroups();
    }

    private static function canManageCarGroups(): bool
    {
        $user = auth()->user();

        return $user?->can('manage_car_groups') ?? false;
    }
}
