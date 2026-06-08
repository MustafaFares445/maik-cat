<?php

namespace App\Data;

final readonly class ExcelItemRowData
{
    public function __construct(
        public string $sheetName,
        public int $rowIndex,
        public string $groupName,
        public ?string $model,
        public string $serialCode,
        public string $normalizedSerial,
        public ?string $details,
        public ?float $weightKg,
        public ?float $ptPpm,
        public ?float $pdPpm,
        public ?float $rhPpm,
        public ?string $shapeCode,
    ) {}

    public function hasEnrichmentValues(): bool
    {
        return $this->weightKg !== null
            || $this->ptPpm !== null
            || $this->pdPpm !== null
            || $this->rhPpm !== null
            || filled($this->shapeCode);
    }

    public function duplicateSignature(): string
    {
        return implode('|', [
            $this->groupName,
            $this->normalizedSerial,
            $this->decimal($this->weightKg, 3),
            $this->decimal($this->ptPpm, 4),
            $this->decimal($this->pdPpm, 4),
            $this->decimal($this->rhPpm, 4),
            mb_strtoupper(trim((string) $this->shapeCode)),
        ]);
    }

    /**
     * @return array{
     *   weight_kg: ?float,
     *   pt_ppm: ?float,
     *   pd_ppm: ?float,
     *   rh_ppm: ?float,
     *   shape_code: ?string
     * }
     */
    public function enrichmentPayload(): array
    {
        return [
            'weight_kg' => $this->weightKg,
            'pt_ppm' => $this->ptPpm,
            'pd_ppm' => $this->pdPpm,
            'rh_ppm' => $this->rhPpm,
            'shape_code' => $this->shapeCode,
        ];
    }

    private function decimal(?float $value, int $precision): string
    {
        if ($value === null) {
            return 'null';
        }

        return number_format($value, $precision, '.', '');
    }
}
