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
     *     completed_source_hashes: list<string>,
     *     failed_item_ids: list<string>,
     *     failed_source_hashes: list<string>
     * }
     */
    public function load(string $key): array
    {
        return $this->normalizeState($this->readState($key));
    }

    public function markCompleted(string $key, EcotradeProductImageCandidate $candidate): void
    {
        $state = $this->normalizeState($this->readState($key));
        $itemId = (string) $candidate->item->id;
        $sourceHash = (string) $candidate->product->sourceHash;

        $state['completed_item_ids'] = $this->rememberValue($state['completed_item_ids'], $itemId);
        $state['completed_source_hashes'] = $this->rememberValue($state['completed_source_hashes'], $sourceHash);
        $state['failed_item_ids'] = $this->forgetValue($state['failed_item_ids'], $itemId);
        $state['failed_source_hashes'] = $this->forgetValue($state['failed_source_hashes'], $sourceHash);
        $state['updated_at'] = now()->toISOString();
        $state['completed_count'] = count($state['completed_item_ids']);
        $state['failed_count'] = count($state['failed_item_ids']);

        $this->writeState($key, $state);
    }

    public function markFailed(string $key, EcotradeProductImageCandidate $candidate): void
    {
        $state = $this->normalizeState($this->readState($key));
        $itemId = (string) $candidate->item->id;
        $sourceHash = (string) $candidate->product->sourceHash;

        if (! in_array($itemId, $state['completed_item_ids'], true)) {
            $state['failed_item_ids'] = $this->rememberValue($state['failed_item_ids'], $itemId);
        }

        if (
            $sourceHash !== ''
            && ! in_array($sourceHash, $state['completed_source_hashes'], true)
        ) {
            $state['failed_source_hashes'] = $this->rememberValue($state['failed_source_hashes'], $sourceHash);
        }

        $state['updated_at'] = now()->toISOString();
        $state['completed_count'] = count($state['completed_item_ids']);
        $state['failed_count'] = count($state['failed_item_ids']);

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
            return $this->emptyState();
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded)) {
            return $this->emptyState();
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

    /**
     * @param  array<string, mixed>  $state
     * @return array{
     *     completed_item_ids: list<string>,
     *     completed_source_hashes: list<string>,
     *     failed_item_ids: list<string>,
     *     failed_source_hashes: list<string>
     * }
     */
    private function normalizeState(array $state): array
    {
        return [
            'completed_item_ids' => $this->normalizeValues($state['completed_item_ids'] ?? []),
            'completed_source_hashes' => $this->normalizeValues($state['completed_source_hashes'] ?? []),
            'failed_item_ids' => $this->normalizeValues($state['failed_item_ids'] ?? []),
            'failed_source_hashes' => $this->normalizeValues($state['failed_source_hashes'] ?? []),
        ];
    }

    /**
     * @return array{
     *     completed_item_ids: list<string>,
     *     completed_source_hashes: list<string>,
     *     failed_item_ids: list<string>,
     *     failed_source_hashes: list<string>
     * }
     */
    private function emptyState(): array
    {
        return [
            'completed_item_ids' => [],
            'completed_source_hashes' => [],
            'failed_item_ids' => [],
            'failed_source_hashes' => [],
        ];
    }

    /**
     * @param  array<int, mixed>  $values
     * @return list<string>
     */
    private function normalizeValues(array $values): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $value): string => (string) $value,
            $values,
        )));
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function rememberValue(array $values, string $value): array
    {
        if ($value === '') {
            return $values;
        }

        return array_values(array_unique([...$values, $value]));
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function forgetValue(array $values, string $value): array
    {
        if ($value === '') {
            return $values;
        }

        return array_values(array_filter(
            $values,
            static fn (string $current): bool => $current !== $value,
        ));
    }
}
