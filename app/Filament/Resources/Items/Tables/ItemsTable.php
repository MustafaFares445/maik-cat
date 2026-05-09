<?php

namespace App\Filament\Resources\Items\Tables;

use App\Models\CarGroup;
use App\Models\Item;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ItemsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('image')
                    ->label('Image')
                    ->circular()
                    ->size(48)
                    ->getStateUsing(fn (Item $record): string => $record->getFirstMediaUrl('images', 'thumb')),
                TextColumn::make('serial_code')
                    ->label('Serial')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                TextColumn::make('model')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('carGroup.name')
                    ->label('Car group')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('weight_kg')
                    ->label('KG')
                    ->numeric(3)
                    ->sortable(),
                TextColumn::make('pt_ppm')
                    ->label('PT')
                    ->numeric(4)
                    ->sortable(),
                TextColumn::make('pd_ppm')
                    ->label('PD')
                    ->numeric(4)
                    ->sortable(),
                TextColumn::make('rh_ppm')
                    ->label('RH')
                    ->numeric(4)
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->label('Updated')
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('car_group_id')
                    ->label('Car group')
                    ->options(fn (): array => CarGroup::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable(),
                TernaryFilter::make('has_image')
                    ->label('Has image')
                    ->queries(
                        true: fn ($query) => $query->whereHas('media', fn ($mediaQuery) => $mediaQuery->where('collection_name', 'images')),
                        false: fn ($query) => $query->whereDoesntHave('media', fn ($mediaQuery) => $mediaQuery->where('collection_name', 'images')),
                        blank: fn ($query) => $query,
                    ),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('updated_at', 'desc');
    }
}
