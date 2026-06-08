<?php

namespace App\Services\Ecotrade;

use App\Data\EcotradeProductData;
use Illuminate\Support\Str;

class EcotradeRecordNormalizer
{
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
