<?php

namespace App\Filament\Resources\CarGroups\Pages;

use App\Filament\Resources\CarGroups\CarGroupResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCarGroup extends CreateRecord
{
    protected static string $resource = CarGroupResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['excel_sheet_name'] = $data['name'];

        return $data;
    }
}
