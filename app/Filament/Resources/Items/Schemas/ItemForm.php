<?php

namespace App\Filament\Resources\Items\Schemas;

use App\Models\Item;
use App\Models\ItemFilterMapping;
use App\Services\Pricing\PricingReviewService;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class ItemForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Item details')
                    ->description('Manage converter specs, category mapping, and app-ready media.')
                    ->columns([
                        'default' => 1,
                        'md' => 2,
                    ])
                    ->columnSpanFull()
                    ->components([
                        Select::make('car_group_id')
                            ->label('Car group')
                            ->relationship('carGroup', 'name')
                            ->required()
                            ->searchable()
                            ->preload(),
                        TextInput::make('model')
                            ->maxLength(255)
                            ->required(),
                        TextInput::make('serial_code')
                            ->label('Serial code')
                            ->maxLength(255)
                            ->required(),
                        TextInput::make('shape_code')
                            ->label('Shape code')
                            ->maxLength(20),
                        TextInput::make('weight_kg')
                            ->label('Weight (kg)')
                            ->numeric()
                            ->step(0.001)
                            ->minValue(0),
                        TextInput::make('pt_ppm')
                            ->label('PT PPM')
                            ->numeric()
                            ->step(0.0001)
                            ->minValue(0),
                        TextInput::make('pd_ppm')
                            ->label('PD PPM')
                            ->numeric()
                            ->step(0.0001)
                            ->minValue(0),
                        TextInput::make('rh_ppm')
                            ->label('RH PPM')
                            ->numeric()
                            ->step(0.0001)
                            ->minValue(0),
                        Placeholder::make('review_warning')
                            ->label('Review warning')
                            ->visible(fn (?Item $record): bool => $record?->filterMapping?->status === ItemFilterMapping::STATUS_NEEDS_REVIEW)
                            ->content(function (?Item $record): HtmlString {
                                $mapping = $record?->filterMapping;

                                if (! $mapping instanceof ItemFilterMapping) {
                                    return new HtmlString('');
                                }

                                $issue = app(PricingReviewService::class)->issue($mapping);
                                $reason = e($issue['label']);
                                $note = e($issue['instruction']);

                                return new HtmlString(
                                    '<div class="rounded-xl border border-warning-500/40 bg-warning-500/10 p-4 text-sm">'
                                    .'<div class="font-semibold text-warning-600 dark:text-warning-400">This item needs review and is not currently shown in the app.</div>'
                                    .'<div class="mt-2"><strong>Reason:</strong> '.$reason.'</div>'
                                    .'<div class="mt-1"><strong>Review note:</strong> '.$note.'</div>'
                                    .'</div>',
                                );
                            })
                            ->columnSpanFull(),
                        Textarea::make('details')
                            ->rows(3)
                            ->columnSpanFull(),
                        FileUpload::make('item_image')
                            ->label('Item image')
                            ->image()
                            ->imageEditor()
                            ->downloadable()
                            ->openable()
                            ->disk('public')
                            ->directory('filament/items')
                            ->visibility('public')
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/avif'])
                            ->maxSize(4096)
                            ->afterStateHydrated(function (FileUpload $component, mixed $state, ?Item $record): void {
                                if (filled($state) || ! $record instanceof Item) {
                                    return;
                                }

                                $media = $record->getFirstMedia('images');

                                if ($media !== null) {
                                    $component->state($media->getPathRelativeToRoot());
                                }
                            })
                            ->helperText('Edit, replace, open, or download the current image here. New uploads automatically receive the Maik Cat watermark and mobile image sizes.')
                            ->columnSpanFull(),
                        Repeater::make('extraCodes')
                            ->relationship()
                            ->label('Extra codes')
                            ->schema([
                                TextInput::make('code')
                                    ->label('Code')
                                    ->required()
                                    ->maxLength(100),
                            ])
                            ->defaultItems(0)
                            ->columns(1)
                            ->columnSpanFull()
                            ->addActionLabel('Add extra code')
                            ->reorderable(false)
                            ->collapsible(),
                    ]),
            ]);
    }
}
