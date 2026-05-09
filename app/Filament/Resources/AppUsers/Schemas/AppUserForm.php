<?php

namespace App\Filament\Resources\AppUsers\Schemas;

use App\Enums\PreferredLanguage;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Hash;

class AppUserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('App user account')
                    ->description('Manage end-user accounts, status, and notification language preference.')
                    ->columns(2)
                    ->components([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('email')
                            ->required()
                            ->email()
                            ->unique(ignoreRecord: true)
                            ->maxLength(255),
                        TextInput::make('password')
                            ->password()
                            ->revealable()
                            ->dehydrated(fn ($state): bool => filled($state))
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->dehydrateStateUsing(fn (string $state): string => Hash::make($state))
                            ->maxLength(255),
                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true),
                        Select::make('preferred_language')
                            ->label('Preferred language')
                            ->options([
                                PreferredLanguage::EN->value => 'English',
                                PreferredLanguage::AR->value => 'Arabic',
                                PreferredLanguage::HU->value => 'Hungarian',
                            ])
                            ->default(PreferredLanguage::EN->value)
                            ->required(),
                        Textarea::make('fcm_token')
                            ->label('FCM token')
                            ->rows(3)
                            ->columnSpanFull()
                            ->helperText('Visible for delivery troubleshooting.'),
                    ]),
            ]);
    }
}
