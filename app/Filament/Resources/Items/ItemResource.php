<?php

namespace App\Filament\Resources\Items;

use App\Filament\Resources\Items\Pages\CreateItem;
use App\Filament\Resources\Items\Pages\EditItem;
use App\Filament\Resources\Items\Pages\ListItems;
use App\Filament\Resources\Items\Schemas\ItemForm;
use App\Filament\Resources\Items\Tables\ItemsTable;
use App\Models\Item;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ItemResource extends Resource
{
    protected static ?string $model = Item::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhoto;

    protected static ?string $navigationLabel = 'Items';

    protected static string|\UnitEnum|null $navigationGroup = 'Catalog Management';

    public static function form(Schema $schema): Schema
    {
        return ItemForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ItemsTable::configure($table);
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
            'index' => ListItems::route('/'),
            'create' => CreateItem::route('/create'),
            'edit' => EditItem::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['carGroup', 'extraCodes', 'media']);
    }

    public static function canViewAny(): bool
    {
        return self::canManageItems();
    }

    public static function canCreate(): bool
    {
        return self::canManageItems();
    }

    public static function canEdit($record): bool
    {
        return self::canManageItems();
    }

    public static function canDelete($record): bool
    {
        return self::canManageItems();
    }

    public static function canDeleteAny(): bool
    {
        return self::canManageItems();
    }

    private static function canManageItems(): bool
    {
        $user = auth()->user();

        return $user?->can('manage_items') ?? false;
    }
}
