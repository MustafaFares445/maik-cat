<?php

namespace App\Services\Ecotrade;

use App\Data\EcotradeProductData;
use App\Models\Item;
use App\Support\Items\CatalystSerialValidator;
use Illuminate\Support\Str;

class EcotradeRecordNormalizer
{
    private const int MIN_ALTERNATE_SERIAL_LENGTH = 6;

    /**
     * @param  array<string, mixed>  $record
     */
    public function normalize(array $record): EcotradeProductData
    {
        $brandPageUrl = $this->normalizeText($record['brand_page_url'] ?? null);
        $productUrl = $this->normalizeText($record['product_url'] ?? null);
        $serialCode = $this->normalizeText($record['serial_code'] ?? null);
        $productName = $this->normalizeText($record['product_name'] ?? null);
        $brandSlug = $this->extractBrandSlug(
            $brandPageUrl,
            $this->normalizeText($record['brand_slug'] ?? null),
            $this->normalizeText($record['brand'] ?? null),
        );

        $brandName = $brandSlug !== null ? $this->brandNameFromSlug($brandSlug) : null;

        $thumbnailUrl = $this->normalizeText($record['thumbnail_url'] ?? null);
        $mainImageUrl = $this->normalizeText($record['main_image_url'] ?? null);
        $imageUrls = array_values(array_filter(array_map(
            fn ($value): ?string => $this->normalizeText(is_string($value) ? $value : null),
            is_array($record['image_urls'] ?? null) ? $record['image_urls'] : [],
        )));
        $cardTexts = array_values(array_filter(array_map(
            fn ($value): ?string => $this->normalizeText(is_string($value) ? $value : null),
            is_array($record['card_texts'] ?? null) ? $record['card_texts'] : [],
        )));
        $cardPrice = $this->normalizeText($record['card_price'] ?? null);

        $invalidReason = null;

        if ($brandPageUrl === null) {
            $invalidReason = 'Missing brand_page_url.';
        } elseif ($brandSlug === null) {
            $invalidReason = 'Unable to extract a valid brand slug.';
        } elseif ($productUrl === null) {
            $invalidReason = 'Missing product_url.';
        } elseif ($serialCode === null) {
            $invalidReason = 'Missing serial_code.';
        } elseif ($productName === null) {
            $invalidReason = 'Missing product_name.';
        } elseif ($brandName === null) {
            $invalidReason = 'Unable to derive brand name.';
        }

        $sourceHash = $brandSlug !== null && $serialCode !== null && $productUrl !== null
            ? $this->makeSourceHash($brandSlug, $serialCode, $productUrl)
            : '';

        return new EcotradeProductData(
            brandSlug: $brandSlug ?? '',
            brandName: $brandName ?? '',
            brandPageUrl: $brandPageUrl ?? '',
            productUrl: $productUrl ?? '',
            serialCode: $serialCode ?? '',
            productName: $productName ?? '',
            thumbnailUrl: $thumbnailUrl,
            mainImageUrl: $mainImageUrl,
            imageUrls: $imageUrls,
            imageCount: count($imageUrls),
            cardPrice: $cardPrice,
            cardTexts: $cardTexts,
            sourceHash: $sourceHash,
            raw: $record,
            invalidReason: $invalidReason,
        );
    }

    /** @return list<string> */
    public function serialFamilies(EcotradeProductData $product): array
    {
        $families = [Item::normalizeSerialValue($product->serialCode)];

        foreach ($this->alternateSerialCandidates($product) as $candidate) {
            if ($this->isAlternateSerialCandidate($candidate)) {
                $families[] = Item::normalizeSerialValue($candidate);
            }
        }

        return array_values(array_unique(array_filter($families)));
    }

    /** @return list<string> */
    private function alternateSerialCandidates(EcotradeProductData $product): array
    {
        $productName = trim($product->productName);
        $serialCode = trim($product->serialCode);

        if ($productName === '' || Item::normalizeSerialValue($productName) === Item::normalizeSerialValue($serialCode)) {
            return [];
        }

        if (preg_match('/^'.preg_quote($productName, '/').'\s+(.+)$/iu', $serialCode, $matches) === 1) {
            return [$productName, $matches[1], ...$this->serialTokens($matches[1])];
        }

        $serialTokens = $this->serialTokens($serialCode);
        $productTokens = $this->serialTokens($productName);

        return $this->containSameValidatedTokens($serialTokens, $productTokens) ? $serialTokens : [];
    }

    /** @return list<string> */
    private function serialTokens(string $serial): array
    {
        return preg_split('/[\s,;|]+/u', $serial, flags: PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /** @param list<string> $serialTokens @param list<string> $productTokens */
    private function containSameValidatedTokens(array $serialTokens, array $productTokens): bool
    {
        if (count($serialTokens) < 2 || count($serialTokens) !== count($productTokens)) {
            return false;
        }

        $normalize = static fn (array $tokens): array => collect($tokens)
            ->map(static fn (string $token): string => Item::normalizeSerialValue($token))
            ->sort()
            ->values()
            ->all();

        return $normalize($serialTokens) === $normalize($productTokens)
            && collect($serialTokens)->every($this->isAlternateSerialCandidate(...));
    }

    private function isAlternateSerialCandidate(string $candidate): bool
    {
        $normalized = Item::normalizeSerialValue($candidate);

        return CatalystSerialValidator::isUsable($candidate)
            && mb_strlen($normalized) >= self::MIN_ALTERNATE_SERIAL_LENGTH
            && preg_match('/\d/u', $normalized) === 1;
    }

    private function extractBrandSlug(?string $brandPageUrl, ?string $fallback = null, ?string $secondaryFallback = null): ?string
    {
        if (is_string($brandPageUrl) && $brandPageUrl !== '') {
            $path = parse_url($brandPageUrl, PHP_URL_PATH);
            $path = is_string($path) ? $path : $brandPageUrl;

            if (preg_match('~(?:^|/)carbrand/([^/]+)~i', $path, $matches)) {
                $slug = $this->normalizeSlug($matches[1]);

                if ($slug !== null) {
                    return $slug;
                }
            }
        }

        foreach ([$fallback, $secondaryFallback] as $candidate) {
            $slug = $this->normalizeSlug($candidate);

            if ($slug !== null) {
                return $slug;
            }
        }

        return null;
    }

    private function brandNameFromSlug(string $slug): string
    {
        return Str::of($slug)
            ->replace('-', ' ')
            ->replace('_', ' ')
            ->squish()
            ->title()
            ->toString();
    }

    private function normalizeText(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        return preg_replace('/\s+/u', ' ', $value) ?: $value;
    }

    private function normalizeSlug(?string $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = Str::of($value)
            ->trim()
            ->lower()
            ->replace('_', '-')
            ->replace(' ', '-')
            ->replaceMatches('/[^a-z0-9\-]+/', '')
            ->replaceMatches('/-+/', '-')
            ->trim('-')
            ->toString();

        if ($value === '' || is_numeric($value)) {
            return null;
        }

        return $value;
    }

    private function makeSourceHash(string $brandSlug, string $serialCode, string $productUrl): string
    {
        return sha1(mb_strtolower($brandSlug).'|'.mb_strtoupper($serialCode).'|'.mb_strtolower($productUrl));
    }
}
