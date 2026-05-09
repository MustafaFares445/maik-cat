<?php

namespace App\Filament\Resources\AppUsers\Pages;

use App\Filament\Resources\AppUsers\AppUserResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAppUsers extends ListRecords
{
    protected static string $resource = AppUserResource::class;

    public function getSubheading(): ?string
    {
        return 'Manage end-user accounts, status, and notification language preference.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
