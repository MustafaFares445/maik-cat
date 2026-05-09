<?php

namespace App\Services;

use App\Models\CarGroup;
use Illuminate\Support\Str;

class ImportSheetGroupResolver
{
    public function resolve(string $sheetName, bool $createIfMissing = true): ?CarGroup
    {
        $normalized = $this->normalizeSheetName($sheetName);
        $canonical = $this->canonicalSheetName($normalized);

        $group = CarGroup::query()
            ->whereRaw('UPPER(excel_sheet_name) = ?', [$canonical])
            ->orWhereRaw('UPPER(name) = ?', [$canonical])
            ->first();

        if ($group !== null) {
            return $group;
        }

        if (! $createIfMissing) {
            return null;
        }

        return CarGroup::query()->create([
            'id' => (string) Str::uuid(),
            'name' => $canonical,
            'excel_sheet_name' => $canonical,
            'region' => null,
        ]);
    }

    public function normalizeSheetName(string $sheetName): string
    {
        $value = preg_replace('/^\s*new\s+/iu', '', trim($sheetName)) ?? trim($sheetName);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return Str::upper($value);
    }

    public function canonicalSheetName(string $sheetName): string
    {
        $aliases = collect((array) config('imports.sheet_aliases', []))
            ->mapWithKeys(fn (string $target, string $source): array => [Str::upper(trim($source)) => Str::upper(trim($target))])
            ->all();

        return $aliases[$sheetName] ?? $sheetName;
    }
}
