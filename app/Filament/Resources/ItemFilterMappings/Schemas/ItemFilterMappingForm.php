<?php

namespace App\Filament\Resources\ItemFilterMappings\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ItemFilterMappingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Filter mapping')
                ->description('Map the catalyst item to its filter reference without changing the original item assay.')
                ->columns(2)
                ->components([
                    Select::make('item_id')
                        ->relationship('item', 'serial_code')
                        ->searchable()
                        ->preload(false)
                        ->required()
                        ->label('Product item'),
                    Select::make('filter_item_id')
                        ->relationship('filterItem', 'serial_code')
                        ->searchable()
                        ->preload(false)
                        ->nullable()
                        ->label('Filter item'),
                    TextInput::make('filter_serial')
                        ->maxLength(255)
                        ->helperText('Reference serial used to build the median filter profile.'),
                    TextInput::make('filter_weight_override')
                        ->numeric()
                        ->minValue(0)
                        ->step(0.001)
                        ->suffix('kg')
                        ->nullable()
                        ->helperText('Optional manual override. Leave empty to use the median filter weight.'),
                    Select::make('confidence')
                        ->options([
                            'high' => 'High',
                            'medium' => 'Medium',
                            'low' => 'Low',
                            'manual' => 'Manual',
                        ])
                        ->default('manual')
                        ->required(),
                    TextInput::make('detection_method')
                        ->default('manual')
                        ->required()
                        ->maxLength(60),
                    Textarea::make('notes')->columnSpanFull(),
                ]),
        ]);
    }
}
