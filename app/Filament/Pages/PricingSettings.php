<?php

namespace App\Filament\Pages;

use App\Models\Item;
use App\Services\Mobile\ItemPriceService;
use App\Services\Mobile\ItemPriceSettingsService;
use App\Services\Pricing\FilterPriceCorrectionService;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
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
    public bool $savedMetalDeductionsEnabled = ItemPriceSettingsService::DEFAULT_METAL_DEDUCTIONS_ENABLED;
    public float $savedPlatinumDeductionPercent = ItemPriceSettingsService::DEFAULT_PLATINUM_DEDUCTION_PERCENT;
    public float $savedPalladiumDeductionPercent = ItemPriceSettingsService::DEFAULT_PALLADIUM_DEDUCTION_PERCENT;
    public float $savedRhodiumDeductionPercent = ItemPriceSettingsService::DEFAULT_RHODIUM_DEDUCTION_PERCENT;
    public string $savedFilterCorrectionMode = ItemPriceSettingsService::DEFAULT_FILTER_CORRECTION_MODE;
    public float $savedFilterCandidatePriceThreshold = ItemPriceSettingsService::DEFAULT_FILTER_CANDIDATE_PRICE_THRESHOLD;
    public float $savedFilterCandidateWeightThresholdKg = ItemPriceSettingsService::DEFAULT_FILTER_CANDIDATE_WEIGHT_THRESHOLD_KG;

    public function mount(): void
    {
        $settings = app(ItemPriceSettingsService::class)->pricingConfiguration();
        $this->applySavedSettings($settings);
        $this->form->fill($settings);
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasAnyRole(['super_admin', 'admin']) ?? false;
    }

    public function getSubheading(): ?string
    {
        return 'Test pricing behaviors safely. Stored item weights and assays are never modified by these settings.';
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('General item price rate')
                ->description('Applied after metal valuation. Default: 80%.')
                ->components([
                    $this->percentInput('rate_percent', 'Price rate', 'Default: 80%.'),
                ]),
            Section::make('Metal deductions')
                ->description('Optional deductions are applied to each metal contribution before the general rate. Keep disabled to preserve the legacy formula.')
                ->components([
                    Toggle::make('metal_deductions_enabled')
                        ->label('Apply metal-specific deductions')
                        ->helperText('Safe default is OFF. Enable only when the client confirms this formula.')
                        ->live(),
                    $this->percentInput('platinum_deduction_percent', 'Platinum deduction', 'Default: 2%.'),
                    $this->percentInput('palladium_deduction_percent', 'Palladium deduction', 'Default: 2%.'),
                    $this->percentInput('rhodium_deduction_percent', 'Rhodium deduction', 'Default: 10%.'),
                ])
                ->columns(3),
            Section::make('Filter price correction')
                ->description('Correction is virtual and only applies to approved filter mappings. Original item data remains unchanged.')
                ->components([
                    Select::make('filter_correction_mode')
                        ->label('Correction mode')
                        ->options([
                            FilterPriceCorrectionService::MODE_DISABLED => 'Disabled (original assay)',
                            FilterPriceCorrectionService::MODE_WEIGHT_ONLY => 'Weight only',
                            FilterPriceCorrectionService::MODE_WEIGHT_AND_METALS => 'Weight + metals',
                        ])
                        ->required()
                        ->live(),
                    TextInput::make('filter_candidate_price_threshold')
                        ->label('Candidate price threshold')
                        ->numeric()
                        ->minValue(0)
                        ->step(0.01)
                        ->prefix('$')
                        ->required()
                        ->helperText('Review signal only. Never changes a price automatically.'),
                    TextInput::make('filter_candidate_weight_threshold_kg')
                        ->label('Candidate weight threshold')
                        ->numeric()
                        ->minValue(0)
                        ->step(0.001)
                        ->suffix('kg')
                        ->required()
                        ->helperText('Review signal only. Default: 1.5 kg.'),
                ])
                ->columns(3),
        ])->statePath('data');
    }

    public function save(): void
    {
        $state = $this->form->getState();
        $settings = app(ItemPriceSettingsService::class)->updatePricingConfiguration(
            (float) $state['rate_percent'],
            (bool) ($state['metal_deductions_enabled'] ?? false),
            (float) $state['platinum_deduction_percent'],
            (float) $state['palladium_deduction_percent'],
            (float) $state['rhodium_deduction_percent'],
            (string) $state['filter_correction_mode'],
            (float) $state['filter_candidate_price_threshold'],
            (float) $state['filter_candidate_weight_threshold_kg'],
        );

        $this->applySavedSettings($settings);
        $this->form->fill($settings);

        Notification::make()
            ->title('Pricing settings updated')
            ->body('Pricing modes were updated. No stored item assay data was changed.')
            ->success()
            ->send();
    }

    /** @return array{rate_percent:float,metal_deductions_enabled:bool,platinum_deduction_percent:float,palladium_deduction_percent:float,rhodium_deduction_percent:float,filter_correction_mode:string,filter_candidate_price_threshold:float,filter_candidate_weight_threshold_kg:float} */
    public function getPreviewConfiguration(): array
    {
        return [
            'rate_percent' => $this->previewPercent('rate_percent', $this->savedRatePercent),
            'metal_deductions_enabled' => (bool) ($this->data['metal_deductions_enabled'] ?? $this->savedMetalDeductionsEnabled),
            'platinum_deduction_percent' => $this->previewPercent('platinum_deduction_percent', $this->savedPlatinumDeductionPercent),
            'palladium_deduction_percent' => $this->previewPercent('palladium_deduction_percent', $this->savedPalladiumDeductionPercent),
            'rhodium_deduction_percent' => $this->previewPercent('rhodium_deduction_percent', $this->savedRhodiumDeductionPercent),
            'filter_correction_mode' => $this->previewMode((string) ($this->data['filter_correction_mode'] ?? $this->savedFilterCorrectionMode)),
            'filter_candidate_price_threshold' => max((float) ($this->data['filter_candidate_price_threshold'] ?? $this->savedFilterCandidatePriceThreshold), 0),
            'filter_candidate_weight_threshold_kg' => max((float) ($this->data['filter_candidate_weight_threshold_kg'] ?? $this->savedFilterCandidateWeightThresholdKg), 0),
        ];
    }

    /** @return array<int,array<string,mixed>> */
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
                    $this->savedMetalDeductionsEnabled,
                    $this->savedFilterCorrectionMode,
                );
                $previewPrice = $priceService->priceForConfiguration(
                    $item,
                    $preview['rate_percent'],
                    $preview['platinum_deduction_percent'],
                    $preview['palladium_deduction_percent'],
                    $preview['rhodium_deduction_percent'],
                    'USD',
                    $preview['metal_deductions_enabled'],
                    $preview['filter_correction_mode'],
                );
                $difference = round($previewPrice - $currentPrice, 2);

                return [
                    'serial_code' => (string) $item->serial_code,
                    'model' => (string) $item->model,
                    'group' => (string) ($item->carGroup?->name ?? '-'),
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

    /** @param array<string,mixed> $settings */
    private function applySavedSettings(array $settings): void
    {
        $this->savedRatePercent = $settings['rate_percent'];
        $this->savedMetalDeductionsEnabled = $settings['metal_deductions_enabled'];
        $this->savedPlatinumDeductionPercent = $settings['platinum_deduction_percent'];
        $this->savedPalladiumDeductionPercent = $settings['palladium_deduction_percent'];
        $this->savedRhodiumDeductionPercent = $settings['rhodium_deduction_percent'];
        $this->savedFilterCorrectionMode = $settings['filter_correction_mode'];
        $this->savedFilterCandidatePriceThreshold = $settings['filter_candidate_price_threshold'];
        $this->savedFilterCandidateWeightThresholdKg = $settings['filter_candidate_weight_threshold_kg'];
    }

    private function previewPercent(string $key, float $fallback): float
    {
        $value = $this->data[$key] ?? $fallback;

        return is_numeric($value) ? min(max((float) $value, 0), 100) : $fallback;
    }

    private function previewMode(string $mode): string
    {
        return in_array($mode, [
            FilterPriceCorrectionService::MODE_DISABLED,
            FilterPriceCorrectionService::MODE_WEIGHT_ONLY,
            FilterPriceCorrectionService::MODE_WEIGHT_AND_METALS,
        ], true)
            ? $mode
            : FilterPriceCorrectionService::MODE_DISABLED;
    }
}
