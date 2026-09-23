<?php

namespace App\Filament\Resources\ItemFilterMappings\Pages;

use App\Filament\Resources\ItemFilterMappings\ItemFilterMappingResource;
use App\Models\ItemFilterMapping;
use App\Services\Pricing\FilterCandidateDetectionService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListItemFilterMappings extends ListRecords
{
    protected static string $resource = ItemFilterMappingResource::class;

    public function getSubheading(): ?string
    {
        $count = ItemFilterMapping::query()
            ->where('status', ItemFilterMapping::STATUS_NEEDS_REVIEW)
            ->count();

        return "{$count} item(s) currently need pricing review and are blocked from being shown in the app. Check the reason, verify the data, preview the price change, then approve the item group so it can be shown again.";
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('scanCandidates')
                ->label('Scan candidates')
                ->icon('heroicon-o-magnifying-glass')
                ->action(function (): void {
                    $summary = app(FilterCandidateDetectionService::class)->scan();

                    Notification::make()
                        ->title('Filter candidate scan completed')
                        ->body(sprintf(
                            '%d scanned, %d candidates, %d matched, %d need review.',
                            $summary['scanned'],
                            $summary['candidates'],
                            $summary['matched'],
                            $summary['needs_review'],
                        ))
                        ->success()
                        ->send();
                }),
            CreateAction::make()->label('Add manual mapping'),
        ];
    }
}
