<?php

namespace App\Imports;

use App\Models\CarGroup;
use App\Models\Item;
use App\Models\DuplicateReview;
use App\Models\ExtraCode;
use App\Models\ImportBatch;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithStartRow;
use Maatwebsite\Excel\Events\AfterSheet;

class ItemSheetImport implements ToCollection, WithStartRow, WithChunkReading, WithEvents
{
    private int $inserted = 0;
    private int $skipped = 0;
    private int $flagged = 0;
    private int $invalid = 0;
    private array $importedSignatures = [];

    public function __construct(
        private readonly ImportBatch $batch,
        private readonly CarGroup $carGroup,
        private readonly int $chunkSize = 200,
    ) {}

    public function startRow(): int
    {
        return 4;
    }

    public function chunkSize(): int
    {
        return $this->chunkSize;
    }

    public function collection(Collection $rows): void
    {
        $rows = $this->dedupeWithinChunk($rows);

        foreach ($rows as $index => $row) {
            $data = $this->mapRow($row);

            if (! $this->isValidRow($data)) {
                $this->invalid++;
                continue;
            }

            $this->processRow($data, $index);
        }
    }

    private function dedupeWithinChunk(Collection $rows): Collection
    {
        return $rows->unique(function ($row) {
            return implode('|', [
                $row[1] ?? '',
                $row[2] ?? '',
                $row[3] ?? '',
                $row[5] ?? '',
                $row[7] ?? '',
            ]);
        })->values();
    }

    private function processRow(array $data, int $rowIndex): void
    {
        $existing = $this->findExisting($data);

        if ($existing === null) {
            $this->insertConverter($data);
            return;
        }

        if ($this->isImportedSignature($data)) {
            $this->skipped++;
            return;
        }

        $this->flagForReview($data, $existing, $rowIndex);
    }

    private function findExisting(array $data): ?Item
    {
        return Item::query()
            ->when(
                $data['serial_code'] !== null,
                fn($q) => $q->where('serial_code', $data['serial_code'])
                    ->where('weight_kg', $data['weight_kg'])
                    ->where('pt_ppm', $data['pt_ppm'])
                    ->where('pd_ppm', $data['pd_ppm'])
                    ->where('rh_ppm', $data['rh_ppm']),
                fn($q) => $q->whereRaw('1 = 0'),
            )
            ->first();
    }

    private function insertConverter(array $data): void
    {
        $converter = Item::create([
            'id' => Str::uuid(),
            'car_group_id' => $this->carGroup->id,
            'model' => $data['model'],
            'serial_code' => $data['serial_code'],
            'weight_kg' => $data['weight_kg'],
            'pt_ppm' => $data['pt_ppm'],
            'pd_ppm' => $data['pd_ppm'],
            'rh_ppm' => $data['rh_ppm'],
            'details' => $data['details'],
            'shape_code' => $data['shape_code'],
        ]);

        $this->insertExtraCodes($converter, $data['extra_codes']);
        $this->markImportedSignature($data);
        $this->inserted++;
    }

    private function isImportedSignature(array $data): bool
    {
        return in_array($this->signature($data), $this->importedSignatures, true);
    }

    private function markImportedSignature(array $data): void
    {
        $this->importedSignatures[] = $this->signature($data);
    }

    private function signature(array $data): string
    {
        return implode('|', [
            $data['serial_code'] ?? '',
            $data['weight_kg'] ?? '',
            $data['pt_ppm'] ?? '',
            $data['pd_ppm'] ?? '',
            $data['rh_ppm'] ?? '',
        ]);
    }

    private function insertExtraCodes(Item $converter, ?string $raw): void
    {
        if (blank($raw)) {
            return;
        }

        collect(explode('/', $raw))
            ->map(fn(string $code) => trim($code))
            ->filter()
            ->each(fn(string $code) => ExtraCode::create([
                'id' => Str::uuid(),
                'item_id' => $converter->id,
                'code' => $code,
                'source' => 'excel_import',
            ]));
    }

    private function flagForReview(array $data, Item $existing, int $rowIndex): void
    {
        DuplicateReview::create([
            'id' => Str::uuid(),
            'batch_id' => $this->batch->id,
            'excel_row' => $this->startRow() + $rowIndex,
            'excel_sheet' => $this->carGroup->excel_sheet_name,
            'payload' => $data,
            'existing_item_id' => $existing->id,
            'status' => 'pending',
        ]);

        $this->flagged++;
    }

    private function mapRow(Collection|array $row): array
    {
        $row = $row instanceof Collection ? $row->toArray() : $row;

        return [
            'model' => $this->clean($row[0] ?? null),
            'serial_code' => $this->clean($row[1] ?? null),
            'weight_kg' => $this->toFloat($row[2] ?? null),
            'pt_ppm' => $this->toFloat($row[3] ?? null),
            'pd_ppm' => $this->toFloat($row[5] ?? null),
            'rh_ppm' => $this->toFloat($row[7] ?? null),
            'extra_codes' => $this->clean($row[10] ?? null),
            'details' => $this->clean($row[12] ?? null),
            'shape_code' => $this->clean($row[16] ?? null),
        ];
    }

    private function isValidRow(array $data): bool
    {
        if (blank($data['model'])) {
            return false;
        }

        return filled($data['pt_ppm'])
            || filled($data['pd_ppm'])
            || filled($data['rh_ppm']);
    }

    private function clean(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $string = trim((string) $value);

        if (str_starts_with($string, '=')) {
            return null;
        }

        return $string;
    }

    private function toFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value) && str_starts_with($value, '=')) {
            return null;
        }

        $cleaned = str_replace(',', '.', (string) $value);

        return is_numeric($cleaned) ? (float) $cleaned : null;
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function () {
                $this->batch->increment('rows_inserted', $this->inserted);
                $this->batch->increment('rows_skipped', $this->skipped);
                $this->batch->increment('rows_flagged', $this->flagged);
                $this->batch->increment('rows_invalid', $this->invalid);
            },
        ];
    }
}
