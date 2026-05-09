<?php

namespace App\Filament\Resources\AppUsers\Pages;

use App\Filament\Resources\AppUsers\AppUserResource;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditAppUser extends EditRecord
{
    protected static string $resource = AppUserResource::class;

    protected function afterSave(): void
    {
        /** @var User $record */
        $record = $this->getRecord();

        if (! $record->hasRole('app_user')) {
            $record->assignRole('app_user');
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
