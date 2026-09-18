<?php

namespace App\Filament\Resources\AppUsers\Tables;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class AppUsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                TextColumn::make('email')
                    ->searchable()
                    ->sortable()
                    ->copyable(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
                TextColumn::make('preferred_language')
                    ->label('Language')
                    ->badge(),
                TextColumn::make('device_status')
                    ->label('Authorized device')
                    ->getStateUsing(fn (User $record): string => $record->hasAuthorizedDevice() ? 'Bound' : 'Not bound')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Bound' ? 'success' : 'warning'),
                TextColumn::make('device_bound_at')
                    ->label('Bound since')
                    ->dateTime('Y-m-d H:i')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Active')
                    ->boolean(),
                SelectFilter::make('preferred_language')
                    ->label('Language')
                    ->options([
                        'en' => 'English',
                        'ar' => 'Arabic',
                        'hu' => 'Hungarian',
                    ]),
                TernaryFilter::make('authorized_device')
                    ->label('Authorized device')
                    ->queries(
                        true: fn ($query) => $query->whereNotNull('authorized_device_token'),
                        false: fn ($query) => $query->whereNull('authorized_device_token'),
                        blank: fn ($query) => $query,
                    ),
            ])
            ->recordActions([
                Action::make('resetAuthorizedDevice')
                    ->label('Reset authorized device')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->visible(fn (User $record): bool => $record->hasAuthorizedDevice())
                    ->requiresConfirmation()
                    ->modalHeading('Reset authorized mobile device?')
                    ->modalDescription('This revokes all current mobile sessions, clears the old notification token, and allows the next successful login to claim this account from a new app installation.')
                    ->modalSubmitActionLabel('Reset device')
                    ->action(function (User $record): void {
                        $record->resetAuthorizedDevice();

                        Notification::make()
                            ->title('Authorized device reset')
                            ->body('All mobile sessions were revoked. The next successful login will bind the account to the new installation.')
                            ->success()
                            ->send();
                    }),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
