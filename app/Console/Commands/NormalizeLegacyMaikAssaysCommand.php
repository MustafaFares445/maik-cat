<?php

namespace App\Console\Commands;

use App\Models\CarGroup;
use App\Models\Item;
use App\Support\Items\ItemAssayFingerprint;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

class NormalizeLegacyMaikAssaysCommand extends Command
{
    private const string LEGACY_SOURCE = 'legacy_maik_g_per_kg';

    protected $signature = 'items:normalize-legacy-maik-assays
        {--path= : Override the codebase legacy Maik workbook path}
        {--dry-run : Report exact source matches without changing items}';

    protected $description = 'Convert source-verified legacy Maik gram-per-kilogram assays to ppm';

    public function handle(): int
    {
        $path = filled($this->option('path'))
            ? (string) $this->option('path')
            : base_path('excel/maik.xlsx');

        if (! is_file($path)) {
            $this->error('Workbook not found: '.$path);

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $report = [
            'source_rows' => 0,
            'unique_source_assays' => 0,
            'matched_items' => 0,
            'updated_items' => 0,
            'skipped_existing_unit' => 0,
            'skipped_not_found' => 0,
            'duplicate_fingerprint_cleared' => 0,
            'verified_existing_items' => 0,
            'unverified_existing_items' => 0,
        ];

        $workbook = IOFactory::load($path);

        try {
            $groupIds = CarGroup::query()->pluck('id', 'excel_sheet_name');
            $seenSourceAssays = [];
            $sourcePpmFingerprints = [];
            $unverifiedExistingItems = [];

            foreach ($workbook->getWorksheetIterator() as $sheet) {
                if (strtolower(trim($sheet->getTitle())) === 'kitko') {
                    continue;
                }

                $groupId = $groupIds->get($sheet->getTitle());

                if (! is_string($groupId)) {
                    continue;
                }

                foreach ($this->sourceRows($sheet, $groupId) as $source) {
                    $report['source_rows']++;

                    if (isset($seenSourceAssays[$source['raw_fingerprint']])) {
                        continue;
                    }

                    $seenSourceAssays[$source['raw_fingerprint']] = true;
                    $sourcePpmFingerprints[$source['ppm_fingerprint']] = true;
                    $report['unique_source_assays']++;

                    $item = Item::query()
                        ->where('source', '!=', self::LEGACY_SOURCE)
                        ->where('assay_fingerprint', $source['raw_fingerprint'])
                        ->first();

                    if (! $item instanceof Item) {
                        $report['skipped_not_found']++;

                        continue;
                    }

                    $report['matched_items']++;

                    $collisionExists = Item::query()
                        ->where('assay_fingerprint', $source['ppm_fingerprint'])
                        ->whereKeyNot($item->getKey())
                        ->exists();

                    if ($collisionExists) {
                        if (! $dryRun) {
                            DB::table('items')->where('id', $item->getKey())->update([
                                'pt_ppm' => $source['pt_ppm'],
                                'pd_ppm' => $source['pd_ppm'],
                                'rh_ppm' => $source['rh_ppm'],
                                'assay_fingerprint' => null,
                                'source' => self::LEGACY_SOURCE,
                                'updated_at' => now(),
                            ]);
                        }

                        $report['duplicate_fingerprint_cleared']++;
                    } elseif (! $dryRun) {
                        $item->forceFill([
                            'pt_ppm' => $source['pt_ppm'],
                            'pd_ppm' => $source['pd_ppm'],
                            'rh_ppm' => $source['rh_ppm'],
                            'source' => self::LEGACY_SOURCE,
                        ])->save();
                    }

                    $report['updated_items']++;
                }
            }

            Item::query()
                ->where('source', self::LEGACY_SOURCE)
                ->select(['id', 'car_group_id', 'serial_code', 'normalized_serial', 'weight_kg', 'pt_ppm', 'pd_ppm', 'rh_ppm'])
                ->chunkById(250, function ($items) use (&$report, &$unverifiedExistingItems, $sourcePpmFingerprints): void {
                    foreach ($items as $item) {
                        $fingerprint = ItemAssayFingerprint::make(
                            $item->car_group_id,
                            $item->normalized_serial,
                            $item->weight_kg,
                            $item->pt_ppm,
                            $item->pd_ppm,
                            $item->rh_ppm,
                        );

                        if ($fingerprint !== null && isset($sourcePpmFingerprints[$fingerprint])) {
                            $report['verified_existing_items']++;
                            $report['skipped_existing_unit']++;
                        } else {
                            $report['unverified_existing_items']++;
                            $unverifiedExistingItems[] = [
                                'id' => $item->getKey(),
                                'serial_code' => $item->serial_code,
                                'weight_kg' => $item->weight_kg,
                                'pt_ppm' => $item->pt_ppm,
                                'pd_ppm' => $item->pd_ppm,
                                'rh_ppm' => $item->rh_ppm,
                            ];
                        }
                    }
                });
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Legacy Maik assay normalization failed: '.$exception->getMessage());

            return self::FAILURE;
        } finally {
            $workbook->disconnectWorksheets();
        }

        foreach ($report as $key => $value) {
            $this->line($key.': '.$value);
        }

        if ($unverifiedExistingItems !== []) {
            $this->newLine();
            $this->warn('Normalized items not found in the supplied source workbook:');
            $this->table(
                ['id', 'serial_code', 'weight_kg', 'pt_ppm', 'pd_ppm', 'rh_ppm'],
                $unverifiedExistingItems,
            );
        }

        $this->comment($dryRun
            ? 'Dry run completed. No items were changed.'
            : 'Legacy Maik assay normalization completed.');

        return self::SUCCESS;
    }

    /**
     * @return iterable<array{raw_fingerprint:string,ppm_fingerprint:string,pt_ppm:?float,pd_ppm:?float,rh_ppm:?float}>
     */
    private function sourceRows(Worksheet $sheet, string $groupId): iterable
    {
        for ($row = 4; $row <= $sheet->getHighestDataRow(); $row++) {
            $serial = Item::normalizeSerialValue($sheet->getCellByColumnAndRow(2, $row)->getFormattedValue());
            $weight = $sheet->getCellByColumnAndRow(3, $row)->getValue();
            $pt = $this->number($sheet->getCellByColumnAndRow(4, $row)->getValue());
            $pd = $this->number($sheet->getCellByColumnAndRow(6, $row)->getValue());
            $rh = $this->number($sheet->getCellByColumnAndRow(8, $row)->getValue());

            if ($serial === '' || ! is_numeric($weight) || (float) $weight <= 0 || ! $this->hasMetal($pt, $pd, $rh)) {
                continue;
            }

            $rawFingerprint = ItemAssayFingerprint::make($groupId, $serial, $weight, $pt, $pd, $rh);
            $ptPpm = $this->toPpm($pt);
            $pdPpm = $this->toPpm($pd);
            $rhPpm = $this->toPpm($rh);
            $ppmFingerprint = ItemAssayFingerprint::make($groupId, $serial, $weight, $ptPpm, $pdPpm, $rhPpm);

            if ($rawFingerprint === null || $ppmFingerprint === null) {
                continue;
            }

            yield [
                'raw_fingerprint' => $rawFingerprint,
                'ppm_fingerprint' => $ppmFingerprint,
                'pt_ppm' => $ptPpm,
                'pd_ppm' => $pdPpm,
                'rh_ppm' => $rhPpm,
            ];
        }
    }

    private function number(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function hasMetal(?float ...$assays): bool
    {
        foreach ($assays as $assay) {
            if ($assay !== null && $assay > 0) {
                return true;
            }
        }

        return false;
    }

    private function toPpm(?float $assay): ?float
    {
        return $assay === null ? null : $assay * 1000;
    }
}
