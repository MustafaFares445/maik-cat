<?php

namespace App\Filament\Resources\AppUsers\Pages;

use App\Filament\Resources\AppUsers\AppUserResource;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;

class CreateAppUser extends CreateRecord
{
    protected static string $resource = AppUserResource::class;

    protected function afterCreate(): void
    {
        /** @var User $record */
        $record = $this->getRecord();

        if (! $record->hasRole('app_user')) {
            $record->assignRole('app_user');
        }
    }
}
