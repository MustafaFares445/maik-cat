<?php

namespace App\Filament\Resources\NotificationAudiences;

use App\Filament\Resources\NotificationAudiences\Pages\CreateNotificationAudience;
use App\Filament\Resources\NotificationAudiences\Pages\EditNotificationAudience;
use App\Filament\Resources\NotificationAudiences\Pages\ListNotificationAudiences;
use App\Filament\Resources\NotificationAudiences\Schemas\NotificationAudienceForm;
use App\Filament\Resources\NotificationAudiences\Tables\NotificationAudiencesTable;
use App\Models\NotificationAudience;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class NotificationAudienceResource extends Resource
{
    protected static ?string $model = NotificationAudience::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $navigationLabel = 'Audience Groups';

    protected static string|\UnitEnum|null $navigationGroup = 'Communication';

    public static function form(Schema $schema): Schema
    {
        return NotificationAudienceForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return NotificationAudiencesTable::configure($table);
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
            'index' => ListNotificationAudiences::route('/'),
            'create' => CreateNotificationAudience::route('/create'),
            'edit' => EditNotificationAudience::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withCount('users');
    }

    public static function canViewAny(): bool
    {
        return self::canManageAudiences();
    }

    public static function canCreate(): bool
    {
        return self::canManageAudiences();
    }

    public static function canEdit($record): bool
    {
        return self::canManageAudiences();
    }

    public static function canDelete($record): bool
    {
        return self::canManageAudiences();
    }

    public static function canDeleteAny(): bool
    {
        return self::canManageAudiences();
    }

    private static function canManageAudiences(): bool
    {
        $user = auth()->user();

        return $user?->can('manage_notification_audiences') ?? false;
    }
}
