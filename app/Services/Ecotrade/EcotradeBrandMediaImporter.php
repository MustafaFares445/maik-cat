<?php

namespace App\Services\Ecotrade;

use App\Models\CarGroup;
use Illuminate\Support\Str;
use Throwable;

class EcotradeBrandMediaImporter
{
    public function importForBrand(CarGroup $carGroup, string $brandSlug, ?string $imageUrl): string
    {
        if (! is_string($imageUrl) || trim($imageUrl) === '') {
            return 'skipped';
        }

        if ($carGroup->getFirstMedia('logo')) {
            return 'skipped';
        }

        try {
            $carGroup
                ->addMediaFromUrl($imageUrl, ['image/jpeg', 'image/png', 'image/webp', 'image/avif', 'image/svg+xml'])
                ->usingName($carGroup->name)
                ->withCustomProperties([
                    'source' => 'ecotrade',
                    'brand_slug' => $brandSlug,
                    'source_url' => $imageUrl,
                ])
                ->toMediaCollection('logo');
        } catch (Throwable $exception) {
            report($exception);

            return 'failed';
        }

        return 'imported';
    }

    /**
     * @return array<string, string>
     */
    public function normalizeMapping(array $mapping): array
    {
        $normalized = [];

        foreach ($mapping as $slug => $url) {
            if (! is_string($slug) || ! is_string($url)) {
                continue;
            }

            $slug = Str::of($slug)->trim()->lower()->replace('_', '-')->replace(' ', '-')->toString();
            $url = trim($url);

            if ($slug === '' || $url === '') {
                continue;
            }

            $normalized[$slug] = $url;
        }

        return $normalized;
    }
}
