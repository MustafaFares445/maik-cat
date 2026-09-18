<?php

namespace App\Filament\Resources\AppUsers\Schemas;

use App\Enums\PreferredLanguage;
use App\Models\User;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\HtmlString;

class AppUserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('App user account')
                    ->description('Manage end-user accounts, status, and notification language preference.')
                    ->columns([
                        'default' => 1,
                        'md' => 2,
                    ])
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
                            ->disabled()
                            ->dehydrated(false)
                            ->columnSpanFull()
                            ->helperText('Read-only. Use Reset authorized device when moving the account to another phone or app installation.'),
                    ]),
                Section::make('Authorized mobile device')
                    ->description('The first successful mobile login binds this account to one app installation. Signing out does not remove this binding.')
                    ->columns(2)
                    ->components([
                        Placeholder::make('device_binding_status')
                            ->label('Device status')
                            ->content(fn (?User $record): HtmlString => new HtmlString(
                                $record?->hasAuthorizedDevice()
                                    ? '<strong style="color:#15803d">Bound to one device</strong>'
                                    : '<strong style="color:#b45309">Not bound yet</strong>',
                            )),
                        Placeholder::make('device_bound_at_display')
                            ->label('Bound since')
                            ->content(fn (?User $record): string => $record?->device_bound_at?->format('Y-m-d H:i:s') ?? '—'),
                        Placeholder::make('device_binding_help')
                            ->label('How it works')
                            ->content('If the user changes phones or reinstalls the app, use Reset authorized device from the App Users table. The next successful login will claim the account for the new installation.')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
