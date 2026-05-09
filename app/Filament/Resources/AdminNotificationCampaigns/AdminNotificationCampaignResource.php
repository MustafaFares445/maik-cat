<?php

namespace App\Filament\Resources\AdminNotificationCampaigns;

use App\Filament\Resources\AdminNotificationCampaigns\Pages\ListAdminNotificationCampaigns;
use App\Filament\Resources\AdminNotificationCampaigns\Schemas\AdminNotificationCampaignForm;
use App\Filament\Resources\AdminNotificationCampaigns\Tables\AdminNotificationCampaignsTable;
use App\Models\AdminNotificationCampaign;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AdminNotificationCampaignResource extends Resource
{
    protected static ?string $model = AdminNotificationCampaign::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBellAlert;

    protected static ?string $navigationLabel = 'Notification History';

    protected static string|\UnitEnum|null $navigationGroup = 'Communication';

    public static function form(Schema $schema): Schema
    {
        return AdminNotificationCampaignForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AdminNotificationCampaignsTable::configure($table);
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
            'index' => ListAdminNotificationCampaigns::route('/'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['sender', 'audience'])
            ->withCount('recipients');
    }

    public static function canViewAny(): bool
    {
        return self::canViewHistory();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    private static function canViewHistory(): bool
    {
        $user = auth()->user();

        return $user?->can('view_notification_history') ?? false;
    }
}
