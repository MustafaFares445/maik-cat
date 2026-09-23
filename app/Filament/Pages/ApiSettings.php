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

    protected static ?string $title = 'Customer App Item Display';

    protected static ?string $navigationLabel = 'App Item Display';

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
        return 'Choose how product items are shown to customers in the app when several records share the same serial code. This changes only what customers see; the stored product data is not changed.';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Items shown to customers')
                    ->description('Decide whether customers should see every stored item record or a single combined item for each repeated serial code. Product weights, metal values, and stored records remain unchanged.')
                    ->components([
                        Toggle::make('unique_serial_items')
                            ->label('Show one item for each unique serial code')
                            ->helperText('When enabled, items with the same serial code are shown to customers as one item instead of several duplicates. The displayed price is calculated from the valid prices of the matching records, while the original stored item data stays unchanged.')
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
            ->title('Customer item display updated')
            ->body($enabled
                ? 'Customers will now see one item for each unique serial code, with the displayed price based on the matching item records.'
                : 'Customers will now see every available item record, including records that share the same serial code.')
            ->success()
            ->send();
    }
}
