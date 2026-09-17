<?php

namespace App\Console\Commands;

use App\Models\Item;
use App\Models\ItemFilterMapping;
use App\Services\Mobile\ItemPriceService;
use App\Services\Pricing\FilterPriceCorrectionService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

class ExportEcotradePricingComparisonCommand extends Command
{
    private const array TARGET_GROUPS = ['BMW', 'MERCEDES', 'PSA'];

    protected $signature = 'pricing:export-ecotrade-comparison
        {--output= : Output XLSX path; defaults to storage/app/reports/filter-pricing-comparison-TIMESTAMP.xlsx}';

    protected $description = 'Export BMW, Mercedes and PSA pricing scenarios for manual EcoTrade price comparison.';

    public function handle(
        ItemPriceService $priceService,
        FilterPriceCorrectionService $correctionService,
    ): int {
        $path = $this->outputPath();
        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create report directory: '.$directory);
        }

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Pricing Comparison');

        $headers = [
            'Group',
            'Serial',
            'Mapping Status',
            'Original Weight kg',
            'Filter Serial',
            'Filter Weight kg',
            'Net Weight kg',
            'Current Price',
            'Weight-only Price',
            'Weight+Metals Price',
            'Selected Price',
            'EcoTrade Price',
            'Difference $',
            'Difference %',
            'Review Bucket',
            'Source URL',
        ];
        $sheet->fromArray($headers, null, 'A1');
        $sheet->getStyle('A1:P1')->getFont()->setBold(true);
        $sheet->freezePane('A2');
        $sheet->setAutoFilter('A1:P1');

        $row = 2;
        $count = 0;

        Item::query()
            ->calculablePrice()
            ->with(['carGroup', 'filterMapping'])
            ->whereHas('carGroup', fn (Builder $query) => $query->whereIn('name', self::TARGET_GROUPS))
            ->orderBy('car_group_id')
            ->orderBy('serial_code')
            ->orderBy('id')
            ->get()
            ->each(function (Item $item) use (
                &$row,
                &$count,
                $sheet,
                $priceService,
                $correctionService,
            ): void {
                $mapping = $item->filterMapping;
                $currentPrice = $priceService->priceForFilterMode(
                    $item,
                    FilterPriceCorrectionService::MODE_DISABLED,
                    'USD',
                );
                $weightOnlyPrice = $currentPrice;
                $weightMetalsPrice = $currentPrice;
                $filterWeight = null;
                $netWeight = null;

                if ($mapping instanceof ItemFilterMapping) {
                    $weightOnlyPrice = $priceService->priceForMappingPreview(
                        $item,
                        $mapping,
                        FilterPriceCorrectionService::MODE_WEIGHT_ONLY,
                        'USD',
                    );
                    $weightMetalsPrice = $priceService->priceForMappingPreview(
                        $item,
                        $mapping,
                        FilterPriceCorrectionService::MODE_WEIGHT_AND_METALS,
                        'USD',
                    );
                    $weightAssay = $correctionService->effectiveAssayForMapping(
                        $item,
                        $mapping,
                        FilterPriceCorrectionService::MODE_WEIGHT_ONLY,
                    );
                    $filterWeight = $weightAssay['filter_weight_kg'];
                    $netWeight = $weightAssay['applied'] ? $weightAssay['weight_kg'] : null;
                }

                $sheet->fromArray([
                    (string) ($item->carGroup?->name ?? ''),
                    (string) $item->serial_code,
                    $mapping?->status ?? '',
                    (float) $item->weight_kg,
                    $mapping?->filter_serial ?? '',
                    $filterWeight,
                    $netWeight,
                    $currentPrice,
                    $weightOnlyPrice,
                    $weightMetalsPrice,
                    $priceService->priceFor($item, 'USD'),
                    null,
                ], null, 'A'.$row);

                $sheet->setCellValue("M{$row}", "=IF(L{$row}=\"\",\"\",K{$row}-L{$row})");
                $sheet->setCellValue("N{$row}", "=IF(OR(L{$row}=\"\",L{$row}=0),\"\",M{$row}/L{$row})");
                $sheet->setCellValue(
                    "O{$row}",
                    "=IF(N{$row}=\"\",\"\",IF(ABS(N{$row})<=0.05,\"Within ±5%\",IF(ABS(N{$row})<=0.1,\"Within ±10%\",\">10% difference\")))",
                );
                $sheet->setCellValue("P{$row}", (string) ($item->source_url ?? ''));

                $row++;
                $count++;
            });

        if ($count > 0) {
            $lastRow = $row - 1;
            $sheet->getStyle("D2:G{$lastRow}")->getNumberFormat()->setFormatCode('0.000');
            $sheet->getStyle("H2:M{$lastRow}")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_CURRENCY_USD_SIMPLE);
            $sheet->getStyle("N2:N{$lastRow}")->getNumberFormat()->setFormatCode('0.00%');
        }

        for ($column = 1; $column <= count($headers); $column++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($column))->setAutoSize(true);
        }

        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        $this->info("Exported {$count} pricing rows.");
        $this->line('Report: '.$path);
        $this->line('Enter EcoTrade prices in column L; difference columns recalculate automatically.');

        return self::SUCCESS;
    }

    private function outputPath(): string
    {
        $option = trim((string) $this->option('output'));
        if ($option === '') {
            return storage_path('app/reports/filter-pricing-comparison-'.now()->format('Ymd-His').'.xlsx');
        }

        if (preg_match('/^[A-Za-z]:[\\\\\/]/', $option) === 1 || str_starts_with($option, DIRECTORY_SEPARATOR)) {
            return $option;
        }

        return base_path($option);
    }
}
