<?php

namespace App\Filament\Pages;

use App\Models\Item;
use App\Services\Mobile\ItemPriceService;
use App\Services\Mobile\ItemPriceSettingsService;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;

class PricingSettings extends Page implements HasSchemas
{
    use InteractsWithSchemas;

    protected static ?string $title = 'Pricing Settings';

    protected static ?string $navigationLabel = 'Pricing Settings';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static string|\UnitEnum|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 10;

    protected static string $routePath = 'pricing-settings';

    protected string $view = 'filament.pages.pricing-settings';

    public ?array $data = [];

    public float $savedRatePercent = ItemPriceSettingsService::DEFAULT_RATE_PERCENT;

    public function mount(): void
    {
        $this->savedRatePercent = app(ItemPriceSettingsService::class)->ratePercent();

        $this->form->fill([
            'rate_percent' => $this->savedRatePercent,
        ]);
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasAnyRole(['super_admin', 'admin']) ?? false;
    }

    public function getSubheading(): ?string
    {
        return 'Control the percentage applied to every calculated item price and preview the result before saving.';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Item price rate')
                    ->description('The Excel-compatible default is 80%. The saved percentage is applied immediately to all item prices returned by the API.')
                    ->components([
                        TextInput::make('rate_percent')
                            ->label('Price rate')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->step(0.01)
                            ->suffix('%')
                            ->required()
                            ->live()
                            ->helperText('Enter a value from 0 to 100. The preview below updates before the setting is saved.'),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $state = $this->form->getState();

        $this->savedRatePercent = app(ItemPriceSettingsService::class)->updateRatePercent(
            (float) $state['rate_percent'],
        );

        $this->form->fill([
            'rate_percent' => $this->savedRatePercent,
        ]);

        Notification::make()
            ->title('Pricing settings updated')
            ->body('New item prices now use the saved rate percentage.')
            ->success()
            ->send();
    }

    public function getPreviewRatePercent(): float
    {
        $ratePercent = $this->data['rate_percent'] ?? $this->savedRatePercent;

        if (! is_numeric($ratePercent)) {
            return $this->savedRatePercent;
        }

        return min(max((float) $ratePercent, 0.0), 100.0);
    }

    /**
     * @return array<int, array{
     *     serial_code: string,
     *     model: string,
     *     group: string,
     *     current_price: float,
     *     preview_price: float,
     *     difference: float,
     *     change_percent: float
     * }>
     */
    public function getPricePreviewRows(): array
    {
        $previewRatePercent = $this->getPreviewRatePercent();
        $priceService = app(ItemPriceService::class);

        return Item::query()
            ->apiVisible()
            ->with('carGroup')
            ->orderBy('serial_code')
            ->limit(5)
            ->get()
            ->map(function (Item $item) use ($previewRatePercent, $priceService): array {
                $currentPrice = $priceService->priceForRate($item, $this->savedRatePercent, 'USD');
                $previewPrice = $priceService->priceForRate($item, $previewRatePercent, 'USD');
                $difference = round($previewPrice - $currentPrice, 2);

                return [
                    'serial_code' => (string) $item->serial_code,
                    'model' => (string) $item->model,
                    'group' => (string) ($item->carGroup?->name ?? '—'),
                    'current_price' => $currentPrice,
                    'preview_price' => $previewPrice,
                    'difference' => $difference,
                    'change_percent' => $currentPrice > 0
                        ? round(($difference / $currentPrice) * 100, 2)
                        : 0.0,
                ];
            })
            ->all();
    }
}
