<?php

namespace App\Imports;

use App\Models\CarGroup;
use App\Models\ImportBatch;
use App\Models\Item;
use App\Services\ItemSiblingMediaCopier;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\OnEachRow;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithStartRow;
use Maatwebsite\Excel\Row;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use Throwable;

class PetraSheetImport implements OnEachRow, WithChunkReading, WithStartRow
{
    private int $inserted = 0;

    private int $skipped = 0;

    private int $invalid = 0;

    private int $flagged = 0;

    /** @var array<string, true> */
    private array $seenSignatures = [];

    /** @var array<string, CarGroup> */
    private array $groupCache = [];

    public function __construct(
        private readonly ImportBatch $batch,
        private readonly string $sheetName,
        private readonly bool $dryRun = false,
        private readonly int $chunkSize = 250,
    ) {}

    public function startRow(): int
    {
        return 2;
    }

    public function chunkSize(): int
    {
        return $this->chunkSize;
    }

    public function onRow(Row $row): void
    {
        $mapped = $this->mapRow($row->toArray());

        if (! $this->isValidRow($mapped)) {
            $this->invalid++;
            return;
        }

        $group = $this->resolveCarGroup($mapped['model']);
        $normalizedSerial = Item::normalizeSerialValue($mapped['serial_code']);
        $signature = $this->signature($mapped, $group->id, $normalizedSerial);

        if (isset($this->seenSignatures[$signature])) {
            $this->skipped++;
            return;
        }

        $this->seenSignatures[$signature] = true;

        $sameSerialWithinGroup = Item::query()
            ->where('car_group_id', $group->id)
            ->where(function ($query) use ($normalizedSerial): void {
                $query->where('normalized_serial', $normalizedSerial)
                    ->orWhereRaw($this->normalizedSerialSql().' = ?', [$normalizedSerial]);
            })
            ->orderByDesc('created_at')
            ->get();

        if ($this->hasExactMatchInGroup($sameSerialWithinGroup, $mapped)) {
            $this->skipped++;
            return;
        }

        if (! $this->dryRun) {
            $this->insertItem($mapped, $group->id);
        }

        $this->inserted++;
    }

    public function report(): array
    {
        return [
            'rows_inserted' => $this->inserted,
            'rows_skipped' => $this->skipped,
            'rows_invalid' => $this->invalid,
            'rows_flagged' => $this->flagged,
        ];
    }

    private function mapRow(array $row): array
    {
        return [
            'serial_code' => $this->cleanString($this->valueAt($row, 0)),
            'details' => $this->cleanString($this->valueAt($row, 1)),
            'model' => $this->cleanString($this->valueAt($row, 2)),
            'weight_kg' => $this->toFloat($this->valueAt($row, 3)),
            'pt_ppm' => $this->toFloat($this->valueAt($row, 4)),
            'pd_ppm' => $this->toFloat($this->valueAt($row, 5)),
            'rh_ppm' => $this->toFloat($this->valueAt($row, 6)),
        ];
    }

    private function valueAt(array $row, int $index): mixed
    {
        if (array_key_exists($index, $row)) {
            return $row[$index];
        }

        $oneBased = $index + 1;
        if (array_key_exists($oneBased, $row)) {
            return $row[$oneBased];
        }

        $column = Coordinate::stringFromColumnIndex($index + 1);

        return $row[$column] ?? null;
    }

    private function isValidRow(array $data): bool
    {
        if (blank($data['serial_code']) || blank($data['model'])) {
            return false;
        }

        $serial = trim((string) $data['serial_code']);

        if (
            preg_match('/^[\?\.\-\_\/\\]+$/u', $serial) === 1
            || str_contains(Str::upper($serial), 'KONTROLINIS')
            || in_array(Str::upper($serial), ['UNKNOWN', 'N/A', 'NA', 'NONE', 'NULL'], true)
        ) {
            return false;
        }

        if ($data['weight_kg'] === null || (float) $data['weight_kg'] <= 0.0) {
            return false;
        }

        return (float) ($data['pt_ppm'] ?? 0) > 0.0
            || (float) ($data['pd_ppm'] ?? 0) > 0.0
            || (float) ($data['rh_ppm'] ?? 0) > 0.0;
    }

    private function resolveCarGroup(string $manufacturer): CarGroup
    {
        $normalized = $this->normalizeGroupName($manufacturer);

        if (isset($this->groupCache[$normalized])) {
            return $this->groupCache[$normalized];
        }

        $group = CarGroup::query()
            ->whereRaw('UPPER(name) = ?', [$normalized])
            ->orWhereRaw('UPPER(excel_sheet_name) = ?', [$normalized])
            ->first();

        if ($group === null) {
            $group = CarGroup::query()->firstOrCreate(
                ['excel_sheet_name' => $normalized],
                [
                    'name' => $normalized,
                    'region' => null,
                ]
            );
        }

        return $this->groupCache[$normalized] = $group;
    }

    private function insertItem(array $data, string $groupId): void
    {
        $item = Item::query()->create([
            'id' => (string) Str::uuid(),
            'car_group_id' => $groupId,
            'model' => $data['model'],
            'serial_code' => $data['serial_code'],
            'normalized_serial' => Item::normalizeSerialValue($data['serial_code']),
            'weight_kg' => $data['weight_kg'],
            'pt_ppm' => $data['pt_ppm'],
            'pd_ppm' => $data['pd_ppm'],
            'rh_ppm' => $data['rh_ppm'],
            'details' => $data['details'],
            'shape_code' => null,
            'source' => 'excel_import',
        ]);

        try {
            app(ItemSiblingMediaCopier::class)->copyFirstImageTo($item);
        } catch (Throwable $exception) {
            report($exception);
            $this->flagged++;
        }
    }

    /** @param Collection<int, Item> $items */
    private function hasExactMatchInGroup(Collection $items, array $data): bool
    {
        $target = $this->assayTuple($data);

        foreach ($items as $item) {
            if ($this->assayTuple([
                'weight_kg' => $item->weight_kg,
                'pt_ppm' => $item->pt_ppm,
                'pd_ppm' => $item->pd_ppm,
                'rh_ppm' => $item->rh_ppm,
            ]) === $target) {
                return true;
            }
        }

        return false;
    }

    private function assayTuple(array $data): array
    {
        return [
            $this->decimal($data['weight_kg'] ?? null, 3),
            $this->decimal($data['pt_ppm'] ?? null, 4),
            $this->decimal($data['pd_ppm'] ?? null, 4),
            $this->decimal($data['rh_ppm'] ?? null, 4),
        ];
    }

    private function signature(array $data, string $groupId, string $normalizedSerial): string
    {
        return implode('|', [$groupId, $normalizedSerial, ...$this->assayTuple($data)]);
    }

    private function decimal(mixed $value, int $precision): string
    {
        return $value === null ? 'null' : number_format((float) $value, $precision, '.', '');
    }

    private function cleanString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);

        if ($string === '' || str_starts_with($string, '=') || str_starts_with($string, '#')) {
            return null;
        }

        return preg_replace('/\s+/u', ' ', $string) ?: $string;
    }

    private function toFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $raw = trim((string) $value);

        if ($raw === '' || str_starts_with($raw, '=') || str_starts_with($raw, '#')) {
            return null;
        }

        $cleaned = str_replace([' ', "'", ','], ['', '', '.'], $raw);

        return is_numeric($cleaned) ? (float) $cleaned : null;
    }

    private function normalizeGroupName(string $value): string
    {
        $clean = preg_replace('/\s+/u', ' ', trim($value)) ?? '';

        return Str::upper($clean);
    }

    private function normalizedSerialSql(): string
    {
        return "UPPER(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(serial_code, ''), ' ', ''), '-', ''), '/', ''), '.', ''))";
    }
}
