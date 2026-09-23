<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['preferred_language'] = 'en';

        return $data;
    }

    protected function afterCreate(): void
    {
        /** @var User $record */
        $record = $this->getRecord();

        if (! $record->hasRole('admin')) {
            $record->assignRole('admin');
        }
    }
}
