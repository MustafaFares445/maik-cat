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
                    ->description('Organize item categories used across search and browsing.')
                    ->columns(2)
                    ->components([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('region')
                            ->maxLength(255),
                    ]),
            ]);
    }
}
