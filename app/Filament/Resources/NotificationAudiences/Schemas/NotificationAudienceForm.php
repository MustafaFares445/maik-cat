<?php

namespace App\Filament\Resources\NotificationAudiences\Schemas;

use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class NotificationAudienceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Audience group')
                    ->description('Create reusable user groups for targeted messaging.')
                    ->columns([
                        'default' => 1,
                        'md' => 2,
                    ])
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
                                titleAttribute: 'name',
                                modifyQueryUsing: fn (Builder $query): Builder => $query
                                    ->appUsers()
                                    ->where('is_active', true)
                                    ->orderBy('name')
                                    ->orderBy('email'),
                            )
                            ->getOptionLabelFromRecordUsing(
                                fn (Model $record): string => self::userOptionLabel($record)
                            )
                            ->multiple()
                            ->searchable(['name', 'email'])
                            ->preload()
                            ->searchPrompt('Search users by name or email')
                            ->noOptionsMessage('No active app users are available.')
                            ->noSearchResultsMessage('No users match your search.')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    private static function userOptionLabel(Model $record): string
    {
        if (! $record instanceof User) {
            return (string) $record->getKey();
        }

        $name = trim((string) $record->name);
        $email = trim((string) $record->email);

        if ($name !== '' && $email !== '') {
            return "{$name} — {$email}";
        }

        return $name !== '' ? $name : $email;
    }
}
