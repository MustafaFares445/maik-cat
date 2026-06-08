<?php

namespace App\Services;

use App\Models\Item;
use App\Models\DuplicateReview;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class DuplicateResolverService
{
    public function resolve(DuplicateReview $review, string $action, string $resolvedBy): void
    {
        $allowed = ['keep', 'overwrite', 'insert'];

        if (! in_array($action, $allowed, true)) {
            throw new InvalidArgumentException(
                "Invalid action '{$action}'. Allowed: " . implode(', ', $allowed)
            );
        }

        if (! $review->isPending()) {
            throw new InvalidArgumentException('Duplicate review is already resolved.');
        }

        DB::transaction(function () use ($review, $action, $resolvedBy) {
            match ($action) {
                'keep' => $this->keep($review),
                'overwrite' => $this->overwrite($review),
                'insert' => $this->insert($review),
            };

            $resolvedStatus = match ($action) {
                'keep' => 'kept',
                'overwrite' => 'overwritten',
                'insert' => 'inserted',
            };

            $review->update([
                'status' => $resolvedStatus,
                'resolved_by' => $resolvedBy,
                'resolved_at' => now(),
            ]);
        });
    }

    private function keep(DuplicateReview $review): void {}

    private function overwrite(DuplicateReview $review): void
    {
        $payload = $review->payload;
        $converter = Item::findOrFail($review->existing_item_id);

        $converter->update([
            'model' => $payload['model'],
            'serial_code' => $payload['serial_code'],
            'normalized_serial' => Item::normalizeSerialValue($payload['serial_code'] ?? null),
            'weight_kg' => $payload['weight_kg'],
            'pt_ppm' => $payload['pt_ppm'],
            'pd_ppm' => $payload['pd_ppm'],
            'rh_ppm' => $payload['rh_ppm'],
            'details' => $payload['details'],
            'shape_code' => $payload['shape_code'],
        ]);

        $converter->extraCodes()->delete();
        $this->insertExtraCodes($converter, $payload['extra_codes'] ?? null);
    }

    private function insert(DuplicateReview $review): void
    {
        $payload = $review->payload;
        $existing = Item::findOrFail($review->existing_item_id);

        $converter = Item::create([
            'id' => Str::uuid(),
            'car_group_id' => $existing->car_group_id,
            'model' => $payload['model'],
            'serial_code' => $payload['serial_code'],
            'normalized_serial' => Item::normalizeSerialValue($payload['serial_code'] ?? null),
            'weight_kg' => $payload['weight_kg'],
            'pt_ppm' => $payload['pt_ppm'],
            'pd_ppm' => $payload['pd_ppm'],
            'rh_ppm' => $payload['rh_ppm'],
            'details' => $payload['details'],
            'shape_code' => $payload['shape_code'],
        ]);

        $this->insertExtraCodes($converter, $payload['extra_codes'] ?? null);
    }

    private function insertExtraCodes(Item $converter, ?string $raw): void
    {
        if (blank($raw)) {
            return;
        }

        collect(explode('/', $raw))
            ->map(fn(string $code) => trim($code))
            ->filter()
            ->each(fn(string $code) => $converter->extraCodes()->create([
                'id' => Str::uuid(),
                'code' => $code,
                'source' => 'manual_resolution',
            ]));
    }
}
