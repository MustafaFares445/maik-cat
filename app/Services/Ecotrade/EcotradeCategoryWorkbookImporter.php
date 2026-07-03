<?php

namespace App\Services\Ecotrade;

use App\Models\CarGroup;
use App\Models\Item;
use App\Services\ImportSheetGroupResolver;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;

class EcotradeCategoryWorkbookImporter
{
    public function __construct(
        private readonly ImportSheetGroupResolver $groupResolver,
    ) {}

    /**
     * @return array{
     *   path: string,
     *   rows_scanned: int,
     *   rows_invalid: int,
     *   groups_created: int,
     *   groups_updated: int,
     *   items_scanned: int,
     *   items_linked: int,
     *   rows_skipped_noop: int,
     *   rows_skipped_ambiguous: int,
     *   rows_skipped_not_found: int,
     *   matched_item_ids: array<string, true>
     * }
     */
    public function import(string $path, bool $dryRun = false): array
    {
        if (! is_file($path)) {
            throw new RuntimeException('Ecotrade import file not found: '.$path);
        }

        $report = $this->makeEmptyReport($path);
        $groupMatchers = [];

        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $sheetInfos = $reader->listWorksheetInfo($path);

        foreach ($sheetInfos as $sheetInfo) {
            $sheetName = $this->worksheetName($sheetInfo);

            if ($sheetName === null) {
                continue;
            }

            $group = $this->resolveGroup($sheetName, true);

            if ($group->wasRecentlyCreated) {
                $report['groups_created']++;
            } else {
                $report['groups_updated']++;
            }

            $groupMatchers[] = $this->buildMatcher($group, $sheetName);
        }

        $items = Item::query()
            ->where('source', 'ecotrade')
            ->orderBy('created_at')
            ->get();

        foreach ($items as $item) {
            $report['rows_scanned']++;
            $report['items_scanned']++;

            $brandKey = $this->extractBrandKeyFromSourceUrl($item->source_url);

            if ($brandKey === null) {
                $report['rows_invalid']++;

                continue;
            }

            $match = $this->findBestMatch($groupMatchers, $brandKey);

            if ($match === null) {
                $report['rows_skipped_not_found']++;

                continue;
            }

            $group = $match['group'];

            if (! $group instanceof CarGroup) {
                $report['rows_skipped_not_found']++;

                continue;
            }

            if ($item->car_group_id === $group->id) {
                $report['rows_skipped_noop']++;
                $report['matched_item_ids'][$item->id] = true;

                continue;
            }

            if (! $dryRun) {
                $item->forceFill(['car_group_id' => $group->id])->save();
            }

            $report['items_linked']++;
            $report['matched_item_ids'][$item->id] = true;
        }

        return $report;
    }

    /**
     * @return array<string, int|string>
     */
    private function makeEmptyReport(string $path): array
    {
        return [
            'path' => $path,
            'rows_scanned' => 0,
            'rows_invalid' => 0,
            'groups_created' => 0,
            'groups_updated' => 0,
            'items_scanned' => 0,
            'items_linked' => 0,
            'rows_skipped_noop' => 0,
            'rows_skipped_ambiguous' => 0,
            'rows_skipped_not_found' => 0,
            'matched_item_ids' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildMatcher(CarGroup $group, string $sheetName): array
    {
        $canonical = $this->groupResolver->canonicalSheetName($this->groupResolver->normalizeSheetName($sheetName));
        $keys = $this->matcherKeys($sheetName, $canonical, $group);

        return [
            'group' => $group,
            'keys' => array_fill_keys($keys, true),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function matcherKeys(string $sheetName, string $canonical, CarGroup $group): array
    {
        $keys = [
            $this->normalizeKey($sheetName),
            $this->normalizeKey($canonical),
            $this->normalizeKey((string) $group->name),
            $this->normalizeKey((string) $group->excel_sheet_name),
            $this->normalizeKey((string) $group->slug),
        ];

        $aliases = (array) config('imports.sheet_aliases', []);
        foreach ($aliases as $alias => $target) {
            $normalizedTarget = $this->normalizeKey((string) $target);

            if ($normalizedTarget !== $this->normalizeKey($canonical)) {
                continue;
            }

            $keys[] = $this->normalizeKey((string) $alias);
        }

        $tokens = preg_split('/[^A-Z0-9]+/', $this->normalizeKey($canonical)) ?: [];
        $keys = array_merge($keys, array_filter($tokens));

        return array_values(array_unique(array_filter($keys)));
    }

    /**
     * @param  array<int, array{group: CarGroup, keys: array<string, true>}>  $groupMatchers
     * @return array{group: CarGroup, score: int}|null
     */
    private function findBestMatch(array $groupMatchers, string $brandKey): ?array
    {
        $best = null;

        foreach ($groupMatchers as $matcher) {
            $score = 0;

            if (isset($matcher['keys'][$brandKey])) {
                $score = 3;
            } elseif ($this->tokenMatch($matcher['keys'], $brandKey)) {
                $score = 1;
            }

            if ($score === 0) {
                continue;
            }

            if ($best === null || $score > $best['score']) {
                $best = [
                    'group' => $matcher['group'],
                    'score' => $score,
                ];

                continue;
            }

            if ($score === $best['score'] && $best['group']->id !== $matcher['group']->id) {
                return null;
            }
        }

        return $best;
    }

    /**
     * @param  array<string, true>  $keys
     */
    private function tokenMatch(array $keys, string $brandKey): bool
    {
        $tokens = preg_split('/[^A-Z0-9]+/', $brandKey) ?: [];

        foreach ($tokens as $token) {
            $token = trim($token);

            if ($token !== '' && isset($keys[$token])) {
                return true;
            }
        }

        return false;
    }

    private function resolveGroup(string $sheetName, bool $createIfMissing): CarGroup
    {
        $canonical = $this->groupResolver->canonicalSheetName($this->groupResolver->normalizeSheetName($sheetName));

        $group = CarGroup::query()
            ->whereRaw('UPPER(excel_sheet_name) = ?', [$canonical])
            ->orWhereRaw('UPPER(name) = ?', [$canonical])
            ->first();

        $attributes = [
            'name' => $canonical,
            'slug' => Str::slug($canonical) ?: Str::lower(preg_replace('/\s+/u', '-', $canonical) ?: $canonical),
            'excel_sheet_name' => $canonical,
            'region' => null,
            'parent_id' => null,
            'source' => 'ecotrade',
            'source_url' => null,
        ];

        if ($group) {
            $group->fill($attributes);
            $group->save();

            return $group->refresh();
        }

        if (! $createIfMissing) {
            return new CarGroup($attributes);
        }

        return CarGroup::query()->create($attributes);
    }

    private function normalizeKey(string $value): string
    {
        $value = Str::upper(trim($value));
        $value = preg_replace('/[^A-Z0-9]+/u', '', $value) ?: $value;

        return $value;
    }

    private function extractBrandKeyFromSourceUrl(?string $url): ?string
    {
        if (! is_string($url) || $url === '') {
            return null;
        }

        $path = parse_url($url, PHP_URL_PATH);
        $path = is_string($path) ? $path : $url;

        if (! preg_match('~(?:^|/)product/([^/]+)~i', $path, $matches)) {
            return null;
        }

        $segment = trim((string) $matches[1]);
        $segment = preg_replace('/^\d+-+/u', '', $segment) ?: $segment;
        $segment = Str::upper($segment);

        return $this->normalizeKey($segment);
    }

    /**
     * @param  array<string, mixed>  $sheetInfo
     */
    private function worksheetName(array $sheetInfo): ?string
    {
        $name = (string) ($sheetInfo['worksheetName'] ?? $sheetInfo['sheetName'] ?? '');

        return trim($name) === '' ? null : $name;
    }
}
