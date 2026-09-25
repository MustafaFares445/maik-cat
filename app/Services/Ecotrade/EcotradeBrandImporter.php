<?php

namespace App\Services\Ecotrade;

use App\Data\EcotradeProductData;
use App\Models\CarGroup;
use App\Services\ImportSheetGroupResolver;
use Illuminate\Support\Str;
use RuntimeException;

class EcotradeBrandImporter
{
    /** @var array<string, CarGroup> */
    private array $cache = [];

    public function __construct(
        private readonly ImportSheetGroupResolver $groupResolver,
    ) {}

    public function import(EcotradeProductData $data): CarGroup
    {
        $slug = $this->normalizeSlug($data->brandSlug);

        if (isset($this->cache[$slug])) {
            return $this->cache[$slug];
        }

        $canonicalName = $this->targetGroupName($slug);
        $group = $this->groupResolver->resolve($canonicalName, true);

        if (! $group instanceof CarGroup) {
            throw new RuntimeException('Unable to resolve canonical car group: '.$canonicalName);
        }

        $group->fill([
            'name' => $canonicalName,
            'slug' => Str::slug($canonicalName)
                ?: Str::lower(preg_replace('/\s+/u', '-', $canonicalName) ?: $canonicalName),
            'excel_sheet_name' => $canonicalName,
            'region' => null,
            'parent_id' => null,
        ]);

        if ($group->isDirty()) {
            $group->save();
        }

        return $this->cache[$slug] = $group->refresh();
    }

    private function targetGroupName(string $slug): string
    {
        $configured = (array) config('imports.ecotrade_brand_groups', []);
        $default = (string) config('imports.ecotrade_default_group', 'RAZNI');
        $requested = (string) ($configured[$slug] ?? $default);

        $canonical = $this->groupResolver->canonicalSheetName(
            $this->groupResolver->normalizeSheetName($requested),
        );

        $allowed = array_map(
            fn (string $name): string => $this->groupResolver->canonicalSheetName(
                $this->groupResolver->normalizeSheetName($name),
            ),
            (array) config('imports.canonical_car_groups', []),
        );

        if (in_array($canonical, $allowed, true)) {
            return $canonical;
        }

        return $this->groupResolver->canonicalSheetName(
            $this->groupResolver->normalizeSheetName($default),
        );
    }

    private function normalizeSlug(string $slug): string
    {
        return Str::of($slug)
            ->trim()
            ->lower()
            ->replace('_', '-')
            ->replace(' ', '-')
            ->toString();
    }
}
