<?php

namespace App\Imports;

use App\Models\CarGroup;
use App\Models\DuplicateReview;
use App\Models\ImportBatch;
use App\Models\Item;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\OnEachRow;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithStartRow;
use Maatwebsite\Excel\Row;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

class PetraSheetImport implements OnEachRow, WithStartRow, WithChunkReading
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
        $signature = $this->signature($mapped, $group->id);

        if (isset($this->seenSignatures[$signature])) {
            $this->skipped++;
            return;
        }

        $exactGlobalMatch = $this->findExactAssayMatch($mapped);
        if ($exactGlobalMatch !== null) {
            $this->seenSignatures[$signature] = true;
            $this->skipped++;
            return;
        }

        $sameSerialWithinGroup = Item::query()
            ->where('car_group_id', $group->id)
            ->where('serial_code', $mapped['serial_code'])
            ->orderByDesc('created_at')
            ->get();

        if ($sameSerialWithinGroup->isEmpty()) {
            $this->insertItem($mapped, $group->id);
            $this->seenSignatures[$signature] = true;
            $this->inserted++;

            return;
        }

        if ($this->hasExactMatchInGroup($sameSerialWithinGroup, $mapped)) {
            $this->seenSignatures[$signature] = true;
            $this->skipped++;

            return;
        }

        DuplicateReview::query()->create([
            'batch_id' => $this->batch->id,
            'excel_row' => $row->getIndex(),
            'excel_sheet' => $this->sheetName,
            'payload' => [
                'model' => $mapped['model'],
                'serial_code' => $mapped['serial_code'],
                'weight_kg' => $mapped['weight_kg'],
                'pt_ppm' => $mapped['pt_ppm'],
                'pd_ppm' => $mapped['pd_ppm'],
                'rh_ppm' => $mapped['rh_ppm'],
                'extra_codes' => null,
                'details' => $mapped['details'],
                'shape_code' => null,
            ],
            'existing_item_id' => $sameSerialWithinGroup->first()->id,
            'status' => 'pending',
        ]);

        $this->seenSignatures[$signature] = true;
        $this->flagged++;
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
        if (array_key_exists($column, $row)) {
            return $row[$column];
        }

        return null;
    }

    private function isValidRow(array $data): bool
    {
        if (blank($data['serial_code']) || blank($data['model'])) {
            return false;
        }

        return filled($data['pt_ppm'])
            || filled($data['pd_ppm'])
            || filled($data['rh_ppm']);
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

        $this->groupCache[$normalized] = $group;

        return $group;
    }

    private function insertItem(array $data, string $groupId): void
    {
        Item::query()->create([
            'id' => Str::uuid(),
            'car_group_id' => $groupId,
            'model' => $data['model'],
            'serial_code' => $data['serial_code'],
            'weight_kg' => $data['weight_kg'],
            'pt_ppm' => $data['pt_ppm'],
            'pd_ppm' => $data['pd_ppm'],
            'rh_ppm' => $data['rh_ppm'],
            'details' => $data['details'],
            'shape_code' => null,
        ]);
    }

    private function findExactAssayMatch(array $data): ?Item
    {
        return Item::query()
            ->where('serial_code', $data['serial_code'])
            ->where('weight_kg', $data['weight_kg'])
            ->where('pt_ppm', $data['pt_ppm'])
            ->where('pd_ppm', $data['pd_ppm'])
            ->where('rh_ppm', $data['rh_ppm'])
            ->first();
    }

    private function hasExactMatchInGroup($items, array $data): bool
    {
        $target = [
            $this->decimal($data['weight_kg'], 3),
            $this->decimal($data['pt_ppm'], 4),
            $this->decimal($data['pd_ppm'], 4),
            $this->decimal($data['rh_ppm'], 4),
        ];

        foreach ($items as $item) {
            $current = [
                $this->decimal($item->weight_kg, 3),
                $this->decimal($item->pt_ppm, 4),
                $this->decimal($item->pd_ppm, 4),
                $this->decimal($item->rh_ppm, 4),
            ];

            if ($target === $current) {
                return true;
            }
        }

        return false;
    }

    private function signature(array $data, string $groupId): string
    {
        return implode('|', [
            $groupId,
            $data['serial_code'] ?? '',
            $this->decimal($data['weight_kg'], 3),
            $this->decimal($data['pt_ppm'], 4),
            $this->decimal($data['pd_ppm'], 4),
            $this->decimal($data['rh_ppm'], 4),
        ]);
    }

    private function decimal(?float $value, int $precision): string
    {
        if ($value === null) {
            return 'null';
        }

        return number_format($value, $precision, '.', '');
    }

    private function cleanString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);
        if ($string === '') {
            return null;
        }

        $collapsed = preg_replace('/\s+/u', ' ', $string);
        if ($collapsed === null || $collapsed === '' || str_starts_with($collapsed, '=')) {
            return null;
        }

        return $collapsed;
    }

    private function toFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value) && str_starts_with($value, '=')) {
            return null;
        }

        $cleaned = preg_replace('/\s+/', '', (string) $value);
        if ($cleaned === null || $cleaned === '') {
            return null;
        }

        $cleaned = str_replace(',', '.', $cleaned);

        return is_numeric($cleaned) ? (float) $cleaned : null;
    }

    private function normalizeGroupName(string $value): string
    {
        $clean = preg_replace('/\s+/u', ' ', trim($value)) ?? '';
        return Str::upper($clean);
    }
}
