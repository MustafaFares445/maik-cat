<?php

namespace App\Services\Ecotrade;

use Illuminate\Support\Str;

class EcotradeDetailsTextResolver
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function resolve(array $payload): ?string
    {
        $candidates = [];

        foreach (['details', 'detail', 'description'] as $key) {
            $candidate = $this->normalizeCandidate($payload[$key] ?? null);

            if ($candidate !== null) {
                $candidates[] = $candidate;
            }
        }

        foreach (is_array($payload['card_texts'] ?? null) ? $payload['card_texts'] : [] as $value) {
            $candidate = $this->normalizeCandidate($value);

            if ($candidate !== null) {
                $candidates[] = $candidate;
            }
        }

        if ($candidates === []) {
            $candidate = $this->normalizeCandidate($payload['product_name'] ?? null);

            if ($candidate !== null) {
                $candidates[] = $candidate;
            }
        }

        $candidates = array_values(array_unique(array_filter($candidates, fn (string $value): bool => $value !== '')));

        if ($candidates === []) {
            return null;
        }

        return implode(' | ', $candidates);
    }

    private function normalizeCandidate(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim(preg_replace('/\s+/u', ' ', $value) ?: $value);

        if ($value === '') {
            return null;
        }

        $lower = Str::of($value)->lower()->toString();

        if (
            str_contains($lower, 'http://')
            || str_contains($lower, 'https://')
            || str_contains($lower, 'ecotradegroup.com')
            || str_contains($lower, 'eco-cat')
            || str_contains($lower, 'ecocat')
            || $lower === 'metals content'
        ) {
            return null;
        }

        return $value;
    }
}
