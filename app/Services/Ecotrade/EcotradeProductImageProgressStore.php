<?php

declare(strict_types=1);

namespace App\Services\Ecotrade;

use App\Data\EcotradeProductImageCandidate;
use Illuminate\Support\Facades\File;
use RuntimeException;

final class EcotradeProductImageProgressStore
{
    private const DIRECTORY = 'app/ecotrade/product-image-imports';

    /**
     * @return array{
     *     completed_item_ids: list<string>,
     *     completed_source_hashes: list<string>
     * }
     */
    public function load(string $key): array
    {
        $state = $this->readState($key);

        return [
            'completed_item_ids' => array_values(array_filter(array_map(
                static fn (mixed $value): string => (string) $value,
                $state['completed_item_ids'] ?? [],
            ))),
            'completed_source_hashes' => array_values(array_filter(array_map(
                static fn (mixed $value): string => (string) $value,
                $state['completed_source_hashes'] ?? [],
            ))),
        ];
    }

    public function markCompleted(string $key, EcotradeProductImageCandidate $candidate): void
    {
        $state = $this->readState($key);
        $itemId = (string) $candidate->item->id;
        $sourceHash = (string) $candidate->product->sourceHash;

        $state['completed_item_ids'] = array_values(array_unique(array_merge(
            array_filter(array_map('strval', $state['completed_item_ids'] ?? [])),
            [$itemId],
        )));

        $state['completed_source_hashes'] = array_values(array_unique(array_merge(
            array_filter(array_map('strval', $state['completed_source_hashes'] ?? [])),
            [$sourceHash],
        )));

        $state['updated_at'] = now()->toISOString();
        $state['completed_count'] = count($state['completed_item_ids']);

        $this->writeState($key, $state);
    }

    public function forget(string $key): void
    {
        $path = $this->pathForKey($key);

        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function readState(string $key): array
    {
        $path = $this->pathForKey($key);

        if (! is_file($path)) {
            return [
                'completed_item_ids' => [],
                'completed_source_hashes' => [],
            ];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded)) {
            return [
                'completed_item_ids' => [],
                'completed_source_hashes' => [],
            ];
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function writeState(string $key, array $state): void
    {
        $path = $this->pathForKey($key);
        File::ensureDirectoryExists(dirname($path));

        $json = json_encode(
            $state,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );

        if (file_put_contents($path, $json, LOCK_EX) === false) {
            throw new RuntimeException('Unable to persist Ecotrade product image progress.');
        }
    }

    private function pathForKey(string $key): string
    {
        return storage_path(self::DIRECTORY.'/'.$key.'.json');
    }
}
