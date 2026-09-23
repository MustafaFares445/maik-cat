<?php

namespace App\Filament\Resources\CarGroups\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CarGroupForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Car group details')
                    ->description('Manage the category name used for catalog items.')
                    ->components([
                        TextInput::make('name')
                            ->placeholder('Enter car group name')
                            ->required()
                            ->maxLength(255),
                    ]),
            ]);
    }
}
