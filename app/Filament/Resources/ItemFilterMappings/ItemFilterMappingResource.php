<?php

namespace App\Filament\Resources\ItemFilterMappings;

use App\Filament\Resources\ItemFilterMappings\Pages\CreateItemFilterMapping;
use App\Filament\Resources\ItemFilterMappings\Pages\EditItemFilterMapping;
use App\Filament\Resources\ItemFilterMappings\Pages\ListItemFilterMappings;
use App\Filament\Resources\ItemFilterMappings\Schemas\ItemFilterMappingForm;
use App\Filament\Resources\ItemFilterMappings\Tables\ItemFilterMappingsTable;
use App\Models\ItemFilterMapping;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ItemFilterMappingResource extends Resource
{
    protected static ?string $model = ItemFilterMapping::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static ?string $navigationLabel = 'Filter Pricing Review';

    protected static ?string $modelLabel = 'filter pricing mapping';

    protected static ?string $pluralModelLabel = 'Filter Pricing Review';

    protected static string|\UnitEnum|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 15;

    public static function form(Schema $schema): Schema
    {
        return ItemFilterMappingForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ItemFilterMappingsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListItemFilterMappings::route('/'),
            'create' => CreateItemFilterMapping::route('/create'),
            'edit' => EditItemFilterMapping::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['item.carGroup', 'filterItem']);
    }

    public static function getNavigationBadge(): ?string
    {
        $count = ItemFilterMapping::query()
            ->where('status', ItemFilterMapping::STATUS_NEEDS_REVIEW)
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string|array|null
    {
        return 'warning';
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->hasAnyRole(['super_admin', 'admin']) ?? false;
    }

    public static function canCreate(): bool
    {
        return self::canViewAny();
    }

    public static function canEdit($record): bool
    {
        return self::canViewAny();
    }

    public static function canDelete($record): bool
    {
        return self::canViewAny();
    }
}
