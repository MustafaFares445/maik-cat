<?php

namespace App\Filament\Resources\CarGroups\Schemas;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;
use App\Models\CarGroup;

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
                        Placeholder::make('current_image')
                            ->label('Current image')
                            ->content(function (?CarGroup $record): HtmlString {
                                $url = $record?->getFirstMediaUrl('images');

                                return new HtmlString(
                                    sprintf(
                                        '<img src="%s" alt="Current car group image" class="w-full max-w-sm rounded-lg border border-gray-200 bg-white p-2 object-contain" loading="lazy" />',
                                        e($url),
                                    ),
                                );
                            })
                            ->hidden(fn (?CarGroup $record): bool => blank($record?->getFirstMediaUrl('images')))
                            ->columnSpanFull(),
                        FileUpload::make('car_group_image')
                            ->label('Image')
                            ->image()
                            ->disk('public')
                            ->directory('filament/car-groups')
                            ->visibility('public')
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/avif'])
                            ->maxSize(4096)
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
