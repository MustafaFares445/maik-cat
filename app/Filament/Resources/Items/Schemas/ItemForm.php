<?php

namespace App\Filament\Resources\Items\Schemas;

use App\Models\Item;
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
                    ->columns(2)
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
                        Textarea::make('details')
                            ->rows(3)
                            ->columnSpanFull(),
                        Placeholder::make('current_item_image')
                            ->label('Current image')
                            ->content(function (?Item $record): HtmlString {
                                $url = $record?->getFirstMediaUrl('images', 'card')
                                    ?: $record?->getFirstMediaUrl('images');

                                return new HtmlString(
                                    sprintf(
                                        '<img src="%s" alt="Current item image" class="w-full max-w-sm rounded-lg border border-gray-200 bg-white p-2 object-contain" loading="lazy" />',
                                        e($url),
                                    ),
                                );
                            })
                            ->hidden(fn (?Item $record): bool => blank($record?->getFirstMediaUrl('images')))
                            ->columnSpanFull(),
                        FileUpload::make('item_image')
                            ->label('Item image')
                            ->image()
                            ->imageEditor()
                            ->disk('public')
                            ->directory('filament/items')
                            ->visibility('public')
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/avif'])
                            ->maxSize(4096)
                            ->helperText('Image uploads are converted to WebP thumb/card/detail variants for mobile usage.')
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
