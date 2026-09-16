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

    public float $savedPlatinumDeductionPercent = ItemPriceSettingsService::DEFAULT_PLATINUM_DEDUCTION_PERCENT;

    public float $savedPalladiumDeductionPercent = ItemPriceSettingsService::DEFAULT_PALLADIUM_DEDUCTION_PERCENT;

    public float $savedRhodiumDeductionPercent = ItemPriceSettingsService::DEFAULT_RHODIUM_DEDUCTION_PERCENT;

    public function mount(): void
    {
        $settings = app(ItemPriceSettingsService::class)->pricingConfiguration();

        $this->savedRatePercent = $settings['rate_percent'];
        $this->savedPlatinumDeductionPercent = $settings['platinum_deduction_percent'];
        $this->savedPalladiumDeductionPercent = $settings['palladium_deduction_percent'];
        $this->savedRhodiumDeductionPercent = $settings['rhodium_deduction_percent'];

        $this->form->fill($settings);
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasAnyRole(['super_admin', 'admin']) ?? false;
    }

    public function getSubheading(): ?string
    {
        return 'Control the general item price rate and the factory deduction applied to each metal contribution.';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('General item price rate')
                    ->description('The default is 80%. This rate is applied after all metal-specific deductions.')
                    ->components([
                        $this->percentInput('rate_percent', 'Price rate', 'Default: 80%.'),
                    ]),
                Section::make('Metal deductions')
                    ->description('Each deduction is applied only to that metal contribution before the three metal values are combined.')
                    ->columns(3)
                    ->components([
                        $this->percentInput(
                            'platinum_deduction_percent',
                            'Platinum deduction',
                            'Default: 2%.',
                        ),
                        $this->percentInput(
                            'palladium_deduction_percent',
                            'Palladium deduction',
                            'Default: 2%.',
                        ),
                        $this->percentInput(
                            'rhodium_deduction_percent',
                            'Rhodium deduction',
                            'Default: 10%.',
                        ),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $state = $this->form->getState();

        $settings = app(ItemPriceSettingsService::class)->updatePricingConfiguration(
            (float) $state['rate_percent'],
            (float) $state['platinum_deduction_percent'],
            (float) $state['palladium_deduction_percent'],
            (float) $state['rhodium_deduction_percent'],
        );

        $this->savedRatePercent = $settings['rate_percent'];
        $this->savedPlatinumDeductionPercent = $settings['platinum_deduction_percent'];
        $this->savedPalladiumDeductionPercent = $settings['palladium_deduction_percent'];
        $this->savedRhodiumDeductionPercent = $settings['rhodium_deduction_percent'];

        $this->form->fill($settings);

        Notification::make()
            ->title('Pricing settings updated')
            ->body('The general price rate and metal deductions are now applied to API item prices.')
            ->success()
            ->send();
    }

    /**
     * @return array{
     *     rate_percent: float,
     *     platinum_deduction_percent: float,
     *     palladium_deduction_percent: float,
     *     rhodium_deduction_percent: float
     * }
     */
    public function getPreviewConfiguration(): array
    {
        return [
            'rate_percent' => $this->previewPercent('rate_percent', $this->savedRatePercent),
            'platinum_deduction_percent' => $this->previewPercent(
                'platinum_deduction_percent',
                $this->savedPlatinumDeductionPercent,
            ),
            'palladium_deduction_percent' => $this->previewPercent(
                'palladium_deduction_percent',
                $this->savedPalladiumDeductionPercent,
            ),
            'rhodium_deduction_percent' => $this->previewPercent(
                'rhodium_deduction_percent',
                $this->savedRhodiumDeductionPercent,
            ),
        ];
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
        $preview = $this->getPreviewConfiguration();
        $priceService = app(ItemPriceService::class);

        return Item::query()
            ->apiVisible()
            ->with('carGroup')
            ->orderBy('serial_code')
            ->limit(5)
            ->get()
            ->map(function (Item $item) use ($preview, $priceService): array {
                $currentPrice = $priceService->priceForConfiguration(
                    $item,
                    $this->savedRatePercent,
                    $this->savedPlatinumDeductionPercent,
                    $this->savedPalladiumDeductionPercent,
                    $this->savedRhodiumDeductionPercent,
                    'USD',
                );
                $previewPrice = $priceService->priceForConfiguration(
                    $item,
                    $preview['rate_percent'],
                    $preview['platinum_deduction_percent'],
                    $preview['palladium_deduction_percent'],
                    $preview['rhodium_deduction_percent'],
                    'USD',
                );
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

    private function percentInput(string $name, string $label, string $helperText): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->numeric()
            ->minValue(0)
            ->maxValue(100)
            ->step(0.01)
            ->suffix('%')
            ->required()
            ->live()
            ->helperText($helperText);
    }

    private function previewPercent(string $key, float $fallback): float
    {
        $value = $this->data[$key] ?? $fallback;

        if (! is_numeric($value)) {
            return $fallback;
        }

        return min(max((float) $value, 0.0), 100.0);
    }
}
