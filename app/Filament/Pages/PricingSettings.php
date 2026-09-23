<?php

namespace App\Filament\Pages;

use App\Models\Item;
use App\Services\Mobile\ItemPriceService;
use App\Services\Mobile\ItemPriceSettingsService;
use App\Services\Pricing\FilterPriceCorrectionService;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

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
            Section::make('How pricing works')
                ->description('These settings control the price shown to customers. They do not change the stored item weight or metal values.')
                ->components([
                    Placeholder::make('pricing_formula_overview')
                        ->label('')
                        ->content(new HtmlString(
                            '<div class="space-y-2 text-sm">'
                            .'<div><strong>1.</strong> The system calculates the value of Platinum, Palladium, and Rhodium using the item weight, metal values, and current market prices.</div>'
                            .'<div><strong>2.</strong> If metal deductions are enabled, the selected percentage is removed from each metal value.</div>'
                            .'<div><strong>3.</strong> If a filter correction is enabled and approved for the item, the corrected weight and/or metal values are used.</div>'
                            .'<div><strong>4.</strong> The final <strong>Price rate</strong> is applied to produce the customer price.</div>'
                            .'</div>',
                        )),
                ]),
            Section::make('General item price rate')
                ->description('Choose what percentage of the calculated metal value becomes the final customer price.')
                ->components([
                    $this->percentInput('rate_percent', 'Price rate', 'Default: 80%.')
                        ->live(),
                    Placeholder::make('general_rate_formula')
                        ->label('Formula')
                        ->content(fn (): string => 'Final price = calculated metal value × '.number_format($this->previewPercent('rate_percent', $this->savedRatePercent), 2).'%.'),
                ]),
            Section::make('Metal deductions')
                ->description('Use this only when you want to reduce the value of each metal separately before applying the general price rate.')
                ->components([
                    Toggle::make('metal_deductions_enabled')
                        ->label('Apply metal-specific deductions')
                        ->helperText('Safe default is OFF. Enable only when the client confirms this formula.')
                        ->live(),
                    $this->percentInput('platinum_deduction_percent', 'Platinum deduction', 'Default: 2%.')
                        ->disabled(fn (): bool => ! (bool) ($this->data['metal_deductions_enabled'] ?? false))
                        ->dehydrated(),
                    $this->percentInput('palladium_deduction_percent', 'Palladium deduction', 'Default: 2%.')
                        ->disabled(fn (): bool => ! (bool) ($this->data['metal_deductions_enabled'] ?? false))
                        ->dehydrated(),
                    $this->percentInput('rhodium_deduction_percent', 'Rhodium deduction', 'Default: 10%.')
                        ->disabled(fn (): bool => ! (bool) ($this->data['metal_deductions_enabled'] ?? false))
                        ->dehydrated(),
                    Placeholder::make('metal_deduction_formula')
                        ->label('How this changes the formula')
                        ->content(function (): HtmlString {
                            $enabled = (bool) ($this->data['metal_deductions_enabled'] ?? false);
                            $rate = number_format($this->previewPercent('rate_percent', $this->savedRatePercent), 2);
                            $pt = number_format($this->previewPercent('platinum_deduction_percent', $this->savedPlatinumDeductionPercent), 2);
                            $pd = number_format($this->previewPercent('palladium_deduction_percent', $this->savedPalladiumDeductionPercent), 2);
                            $rh = number_format($this->previewPercent('rhodium_deduction_percent', $this->savedRhodiumDeductionPercent), 2);

                            if (! $enabled) {
                                return new HtmlString(
                                    '<div class="text-sm"><strong>OFF:</strong> Final price = (PT value + PD value + RH value) × '.$rate.'%.</div>'
                                );
                            }

                            return new HtmlString(
                                '<div class="space-y-1 text-sm">'
                                .'<div><strong>ON:</strong> each metal is reduced first, then the general price rate is applied.</div>'
                                .'<div>Final price = [PT value × (100% − '.$pt.'%) + PD value × (100% − '.$pd.'%) + RH value × (100% − '.$rh.'%)] × '.$rate.'%.</div>'
                                .'</div>'
                            );
                        })
                        ->columnSpanFull(),
                ])
                ->columns(3),
            Section::make('Filter price correction')
                ->description('Choose how an approved filter or DPF part should be removed from the item before the customer price is calculated. The original stored item data is never changed.')
                ->components([
                    Placeholder::make('filter_mode_explanation')
                        ->label('Correction modes')
                        ->content(new HtmlString(
                            '<div class="space-y-2 text-sm">'
                            .'<div><strong>Disabled:</strong> use the original stored weight and metal values exactly as they are.</div>'
                            .'<div><strong>Weight only:</strong> subtract the approved filter/DPF weight from the item weight, but keep the original PT, PD, and RH values.</div>'
                            .'<div><strong>Weight + metals:</strong> subtract the approved filter/DPF weight and also remove its estimated metal contribution before calculating the price. If reliable filter metal data is not available, the system safely falls back to Weight only.</div>'
                            .'</div>',
                        ))
                        ->columnSpanFull(),
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
                    Placeholder::make('filter_formula')
                        ->label('How the selected mode changes the calculation')
                        ->content(function (): HtmlString {
                            $mode = $this->previewMode((string) ($this->data['filter_correction_mode'] ?? $this->savedFilterCorrectionMode));

                            return new HtmlString(match ($mode) {
                                FilterPriceCorrectionService::MODE_WEIGHT_ONLY =>
                                    '<div class="text-sm"><strong>Weight only:</strong> Effective weight = stored item weight − approved filter weight. PT, PD, and RH values stay the same. The normal pricing formula then uses this corrected weight.</div>',
                                FilterPriceCorrectionService::MODE_WEIGHT_AND_METALS =>
                                    '<div class="text-sm"><strong>Weight + metals:</strong> Effective weight = stored item weight − approved filter weight. The filter metal contribution is also removed, then the normal pricing formula uses the corrected weight and corrected metal values.</div>',
                                default =>
                                    '<div class="text-sm"><strong>Disabled:</strong> the normal pricing formula uses the original stored item weight and PT, PD, RH values with no filter correction.</div>',
                            });
                        })
                        ->columnSpanFull(),
                    Placeholder::make('candidate_threshold_help')
                        ->label('About the candidate thresholds')
                        ->content('The price and weight thresholds only help identify items that may need review. They never change an item price by themselves.')
                        ->columnSpanFull(),
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
