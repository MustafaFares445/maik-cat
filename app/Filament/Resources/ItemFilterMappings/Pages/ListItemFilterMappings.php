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

        return "{$count} item(s) are temporarily hidden from customers because some pricing information needs confirmation. Review the issue, check the proposed price, then approve the item so it can be shown again.";
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('scanCandidates')
                ->label('Recheck items')
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
            CreateAction::make()->label('Add filter reference'),
        ];
    }
}
