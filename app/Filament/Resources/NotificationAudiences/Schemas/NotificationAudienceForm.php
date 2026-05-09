<?php

namespace App\Filament\Resources\NotificationAudiences\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class NotificationAudienceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Audience group')
                    ->description('Create reusable user groups for targeted messaging.')
                    ->columns(2)
                    ->components([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true),
                        Textarea::make('description')
                            ->rows(3)
                            ->columnSpanFull(),
                        Select::make('users')
                            ->label('Group users')
                            ->relationship(
                                name: 'users',
                                titleAttribute: 'email',
                                modifyQueryUsing: fn (Builder $query) => $query
                                    ->role('app_user')
                                    ->where('is_active', true)
                                    ->orderBy('email'),
                            )
                            ->multiple()
                            ->preload()
                            ->searchable()
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
