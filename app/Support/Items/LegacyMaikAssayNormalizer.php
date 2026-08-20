<?php

namespace App\Support\Items;

use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

final class LegacyMaikAssayNormalizer
{
    private const float PPM_PER_GRAM_PER_KILOGRAM = 1000.0;

    /**
     * The historical Maik workbook labels these columns "ppm", but its
     * price formula uses them as grams per kilogram. Match the complete
     * workbook signature rather than inferring a unit from a small value.
     *
     * @param  array<string, int|float>  $layout
     */
    public static function assayMultiplier(Worksheet $sheet, array $layout): float
    {
        $headerRow = max(1, (int) ($layout['start_row'] ?? 1) - 1);
        $weightColumn = (int) ($layout['weight'] ?? 0);
        $ptColumn = (int) ($layout['pt'] ?? 0);
        $pdColumn = (int) ($layout['pd'] ?? 0);
        $rhColumn = (int) ($layout['rh'] ?? 0);

        if (min($weightColumn, $ptColumn, $pdColumn, $rhColumn) <= 0) {
            return 1.0;
        }

        $priceColumn = $rhColumn + 2;
        $ptFormula = strtolower(trim((string) $sheet->getCellByColumnAndRow($ptColumn, $headerRow)->getValue()));

        if (
            self::normalized($sheet->getCellByColumnAndRow($weightColumn, $headerRow)->getValue()) !== 'piecekg'
            || ! str_starts_with($ptFormula, '=sum(kitko!')
            || self::normalized($sheet->getCellByColumnAndRow($ptColumn, $headerRow + 1)->getValue()) !== 'pt'
            || self::normalized($sheet->getCellByColumnAndRow($pdColumn, $headerRow + 1)->getValue()) !== 'pd'
            || self::normalized($sheet->getCellByColumnAndRow($rhColumn, $headerRow + 1)->getValue()) !== 'rh'
            || self::normalized($sheet->getCellByColumnAndRow($ptColumn, $headerRow + 2)->getValue()) !== 'ppm'
            || self::normalized($sheet->getCellByColumnAndRow($pdColumn, $headerRow + 2)->getValue()) !== 'ppm'
            || self::normalized($sheet->getCellByColumnAndRow($rhColumn, $headerRow + 2)->getValue()) !== 'ppm'
            || self::normalized($sheet->getCellByColumnAndRow($priceColumn, $headerRow + 2)->getValue()) !== 'priceuspiece'
        ) {
            return 1.0;
        }

        return self::PPM_PER_GRAM_PER_KILOGRAM;
    }

    public static function toPpm(?float $assay, float $multiplier): ?float
    {
        return $assay === null ? null : $assay * $multiplier;
    }

    private static function normalized(mixed $value): string
    {
        return preg_replace('/[^a-z0-9]+/', '', strtolower(trim((string) $value))) ?? '';
    }
}
