<?php

namespace App\Filament\Resources\AdminNotificationCampaigns\Tables;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AdminNotificationCampaignsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title_en')
                    ->label('English title')
                    ->searchable()
                    ->limit(50),
                TextColumn::make('audience_mode')
                    ->label('Audience mode')
                    ->badge()
                    ->sortable(),
                TextColumn::make('audience.name')
                    ->label('Audience group')
                    ->placeholder('N/A')
                    ->toggleable(),
                TextColumn::make('type')
                    ->badge()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('total_recipients')
                    ->label('Total')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('delivered_count')
                    ->label('Delivered')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('failed_count')
                    ->label('Failed')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('sender.email')
                    ->label('Sent by')
                    ->toggleable(),
                TextColumn::make('sent_at')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('audience_mode')
                    ->options([
                        'specific' => 'specific',
                        'audience' => 'audience',
                        'all' => 'all',
                    ]),
                SelectFilter::make('status')
                    ->options([
                        'draft' => 'draft',
                        'sending' => 'sending',
                        'sent' => 'sent',
                    ]),
            ])
            ->recordActions([])
            ->toolbarActions([])
            ->defaultSort('sent_at', 'desc');
    }
}
