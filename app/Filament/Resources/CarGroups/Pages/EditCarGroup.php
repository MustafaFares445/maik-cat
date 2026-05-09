<?php

namespace App\Filament\Resources\CarGroups\Pages;

use App\Filament\Resources\CarGroups\CarGroupResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCarGroup extends EditRecord
{
    protected static string $resource = CarGroupResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['excel_sheet_name'] = $data['name'];

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
