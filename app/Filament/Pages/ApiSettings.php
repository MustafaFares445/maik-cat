<?php

namespace App\Filament\Pages;

use App\Services\Mobile\ItemApiSettingsService;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;

class ApiSettings extends Page implements HasSchemas
{
    use InteractsWithSchemas;

    protected static ?string $title = 'API Settings';

    protected static ?string $navigationLabel = 'API Settings';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static string|\UnitEnum|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 20;

    protected static string $routePath = 'api-settings';

    protected string $view = 'filament.pages.api-settings';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'unique_serial_items' => app(ItemApiSettingsService::class)->uniqueSerialItemsEnabled(),
        ]);
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasAnyRole(['super_admin', 'admin']) ?? false;
    }

    public function getSubheading(): ?string
    {
        return 'Control how item records are exposed by the public API without changing stored item data.';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Item response mode')
                    ->description('This setting only changes API output. Existing item rows, weights, and metal values remain untouched in the database.')
                    ->components([
                        Toggle::make('unique_serial_items')
                            ->label('Return one item per unique serial code')
                            ->helperText('When enabled, duplicate serial codes are collapsed in item API responses. Weight, Pt, Pd, and Rh values are returned as the arithmetic average of all database items with the same normalized serial code.')
                            ->default(ItemApiSettingsService::DEFAULT_UNIQUE_SERIAL_ITEMS),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $state = $this->form->getState();
        $enabled = (bool) ($state['unique_serial_items'] ?? false);

        app(ItemApiSettingsService::class)->updateUniqueSerialItems($enabled);

        $this->form->fill([
            'unique_serial_items' => $enabled,
        ]);

        Notification::make()
            ->title('API settings updated')
            ->body($enabled
                ? 'Item API responses now return one averaged item per unique serial code.'
                : 'Item API responses now return the original item rows.')
            ->success()
            ->send();
    }
}
