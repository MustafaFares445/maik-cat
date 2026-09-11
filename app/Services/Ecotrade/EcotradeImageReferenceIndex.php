<?php

declare(strict_types=1);

namespace App\Services\Ecotrade;

use App\Data\EcotradeProductData;
use App\Models\CarGroup;
use App\Models\Item;
use App\Services\ImportSheetGroupResolver;
use App\Support\Items\CatalystSerialValidator;
use Illuminate\Support\Str;

final class EcotradeImageReferenceIndex
{
    /** @var array<string,array<string,array<string,mixed>>> */
    private array $referencesByFamily = [];

    /** @var array<string,array<string,true>> */
    private array $familiesBySourceHash = [];

    /** @var array<string,array<string,true>> */
    private array $familiesBySourceUrl = [];

    /** @var array<string,string> */
    private array $groupIdsByCanonicalName = [];

    /** @var array<string,int> */
    private array $statistics = [];

    public function __construct(
        private readonly EcotradeRecordNormalizer $normalizer,
        private readonly ImportSheetGroupResolver $groupResolver,
    ) {}

    /** @param iterable<int,array<string,mixed>> $records */
    public function build(iterable $records): self
    {
        $this->reset();
        $this->loadGroupIds();

        foreach ($records as $record) {
            $this->indexRecord($this->normalizer->normalize($record));
        }

        $this->statistics['families_indexed'] = count($this->referencesByFamily);

        return $this;
    }

    /** @return array{primary_family_key:string,family_keys:list<string>,references:list<array<string,mixed>>,ambiguous:bool} */
    public function referencesFor(Item $item): array
    {
        $primaryFamilyKey = $this->familyKey(
            (string) $item->car_group_id,
            Item::normalizeSerialValue($item->normalized_serial ?: $item->serial_code),
        );
        $primaryReferences = array_values($this->referencesByFamily[$primaryFamilyKey] ?? []);

        if ($primaryReferences !== []) {
            return $this->referenceMatch($primaryFamilyKey, [$primaryFamilyKey], $primaryReferences);
        }

        $familyKeys = array_values(array_unique([
            $primaryFamilyKey,
            ...$this->extraCodeFamilyKeys($item),
        ]));
        $references = [];

        foreach ($familyKeys as $familyKey) {
            foreach ($this->referencesByFamily[$familyKey] ?? [] as $sourceHash => $reference) {
                $references[$sourceHash] = $reference;
            }
        }

        return $this->referenceMatch($primaryFamilyKey, $familyKeys, array_values($references));
    }

    /** @return list<string> */
    public function familiesForSourceHash(string $sourceHash): array
    {
        return array_keys($this->familiesBySourceHash[$sourceHash] ?? []);
    }

    /** @return list<string> */
    public function familiesForSourceUrl(string $sourceUrl): array
    {
        return array_keys($this->familiesBySourceUrl[$this->normalizeUrl($sourceUrl)] ?? []);
    }

    /** @return array<string,int> */
    public function statistics(): array
    {
        return $this->statistics;
    }

    private function reset(): void
    {
        $this->referencesByFamily = [];
        $this->familiesBySourceHash = [];
        $this->familiesBySourceUrl = [];
        $this->groupIdsByCanonicalName = [];
        $this->statistics = [
            'records_scanned' => 0,
            'records_invalid' => 0,
            'records_without_image' => 0,
            'records_rejected_image' => 0,
            'records_without_group' => 0,
            'records_indexed' => 0,
            'families_indexed' => 0,
        ];
    }

    private function loadGroupIds(): void
    {
        CarGroup::query()
            ->get(['id', 'name', 'excel_sheet_name'])
            ->each(function (CarGroup $group): void {
                foreach ([$group->name, $group->excel_sheet_name] as $name) {
                    if (! is_string($name) || trim($name) === '') {
                        continue;
                    }

                    $canonicalName = $this->groupResolver->canonicalSheetName(
                        $this->groupResolver->normalizeSheetName($name),
                    );
                    $this->groupIdsByCanonicalName[$canonicalName] = (string) $group->id;
                }
            });
    }

    private function indexRecord(EcotradeProductData $product): void
    {
        $this->statistics['records_scanned']++;

        if (! $product->isValid() || ! CatalystSerialValidator::isUsable($product->serialCode)) {
            $this->statistics['records_invalid']++;

            return;
        }

        $imageUrls = $this->acceptedImageUrls($product);

        if ($imageUrls === []) {
            $counter = $this->hasImageUrl($product) ? 'records_rejected_image' : 'records_without_image';
            $this->statistics[$counter]++;

            return;
        }

        $groupId = $this->productGroupId($product);

        if ($groupId === null) {
            $this->statistics['records_without_group']++;

            return;
        }

        $familyKeys = array_map(
            fn (string $serial): string => $this->familyKey($groupId, $serial),
            $this->normalizer->serialFamilies($product),
        );
        $reference = [
            'serial_code' => $product->serialCode,
            'product_url' => $product->productUrl,
            'image_urls' => $imageUrls,
            'source_hash' => $product->sourceHash,
        ];
        $referenceKey = $this->normalizeUrl($imageUrls[0]);

        foreach ($familyKeys as $familyKey) {
            $this->referencesByFamily[$familyKey][$referenceKey] ??= $reference;
            $this->familiesBySourceHash[$product->sourceHash][$familyKey] = true;

            foreach ([$product->productUrl, ...$imageUrls] as $sourceUrl) {
                $this->familiesBySourceUrl[$this->normalizeUrl($sourceUrl)][$familyKey] = true;
            }
        }

        $this->statistics['records_indexed']++;
    }

    /** @return list<string> */
    private function acceptedImageUrls(EcotradeProductData $product): array
    {
        return collect([$product->mainImageUrl, ...$product->imageUrls])
            ->filter(fn (mixed $url): bool => is_string($url) && ! $this->isRejectedImageUrl($url))
            ->map(static fn (string $url): string => trim($url))
            ->unique()
            ->values()
            ->all();
    }

    private function productGroupId(EcotradeProductData $product): ?string
    {
        $slug = Str::of($product->brandSlug)
            ->trim()
            ->lower()
            ->replace('_', '-')
            ->replace(' ', '-')
            ->toString();
        $configuredGroups = (array) config('imports.ecotrade_brand_groups', []);
        $configuredName = $this->canonicalGroupName((string) ($configuredGroups[$slug] ?? $product->brandName));
        $brandName = $this->canonicalGroupName($product->brandName);

        return $this->groupIdsByCanonicalName[$configuredName]
            ?? $this->groupIdsByCanonicalName[$brandName]
            ?? null;
    }

    private function canonicalGroupName(string $name): string
    {
        return $this->groupResolver->canonicalSheetName(
            $this->groupResolver->normalizeSheetName($name),
        );
    }

    private function isRejectedImageUrl(string $url): bool
    {
        $normalizedUrl = $this->normalizeUrl($url);

        if (! str_starts_with($normalizedUrl, 'http://') && ! str_starts_with($normalizedUrl, 'https://')) {
            return true;
        }

        foreach ((array) config('imports.rejected_image_url_fragments', []) as $fragment) {
            if ($fragment !== '' && str_contains($normalizedUrl, Str::lower((string) $fragment))) {
                return true;
            }
        }

        return false;
    }

    private function hasImageUrl(EcotradeProductData $product): bool
    {
        return collect([$product->mainImageUrl, ...$product->imageUrls])
            ->contains(static fn (mixed $url): bool => is_string($url) && trim($url) !== '');
    }

    /** @return list<string> */
    private function extraCodeFamilyKeys(Item $item): array
    {
        return $item->extraCodes
            ->pluck('code')
            ->map(fn (mixed $code): string => $this->familyKey(
                (string) $item->car_group_id,
                Item::normalizeSerialValue($code),
            ))
            ->filter(fn (string $familyKey): bool => ! str_ends_with($familyKey, '|'))
            ->unique()
            ->values()
            ->all();
    }

    /** @param list<string> $familyKeys @param list<array<string,mixed>> $references */
    private function referenceMatch(string $primaryFamilyKey, array $familyKeys, array $references): array
    {
        return [
            'primary_family_key' => $primaryFamilyKey,
            'family_keys' => $familyKeys,
            'references' => $references,
            'ambiguous' => count($references) > 1,
        ];
    }

    private function familyKey(string $groupId, string $serial): string
    {
        return $groupId.'|'.$serial;
    }

    private function normalizeUrl(string $url): string
    {
        return Str::lower(trim($url));
    }
}
