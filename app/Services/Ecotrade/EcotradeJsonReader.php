<?php

namespace App\Services\Ecotrade;

use Generator;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class EcotradeJsonReader
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function read(string $path): array
    {
        return iterator_to_array($this->readIterator($path), false);
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    public function readIterator(string $path): Generator
    {
        $resolved = $this->resolvePath($path);
        $handle = fopen($resolved, 'rb');

        if ($handle === false) {
            throw new RuntimeException('Unable to open Ecotrade JSON file: '.$resolved);
        }

        try {
            yield from $this->iterateJsonArray($handle);
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    private function iterateJsonArray(mixed $handle): Generator
    {
        $buffer = '';
        $element = '';
        $arrayStarted = false;
        $inString = false;
        $escape = false;
        $depth = 0;
        $firstChunk = true;

        while (! feof($handle)) {
            $chunk = fread($handle, 8192);

            if ($chunk === false) {
                throw new RuntimeException('Unable to read Ecotrade JSON stream.');
            }

            if ($firstChunk) {
                $firstChunk = false;

                if (str_starts_with($chunk, "\xEF\xBB\xBF")) {
                    $chunk = substr($chunk, 3);
                }
            }

            $buffer .= $chunk;
            $length = strlen($buffer);
            $offset = 0;

            while ($offset < $length) {
                $char = $buffer[$offset];
                $offset++;

                if (! $arrayStarted) {
                    if (ctype_space($char)) {
                        continue;
                    }

                    if ($char === '[') {
                        $arrayStarted = true;
                        continue;
                    }

                    throw new RuntimeException('Ecotrade JSON root must be an array of records.');
                }

                if (! $inString && $depth === 0 && ($char === ',' || $char === ']')) {
                    $record = trim($element);

                    if ($record !== '') {
                        yield $this->decodeRecord($record);
                    }

                    $element = '';

                    if ($char === ']') {
                        return;
                    }

                    continue;
                }

                $element .= $char;

                if ($inString) {
                    if ($escape) {
                        $escape = false;
                        continue;
                    }

                    if ($char === '\\') {
                        $escape = true;
                        continue;
                    }

                    if ($char === '"') {
                        $inString = false;
                    }

                    continue;
                }

                if ($char === '"') {
                    $inString = true;
                    continue;
                }

                if ($char === '{' || $char === '[') {
                    $depth++;
                    continue;
                }

                if ($char === '}' || $char === ']') {
                    $depth--;

                    if ($depth < 0) {
                        throw new RuntimeException('Ecotrade JSON array is malformed.');
                    }
                }
            }

            $buffer = '';
        }

        if (trim($element) !== '') {
            yield $this->decodeRecord(trim($element));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeRecord(string $json): array
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            throw new RuntimeException('Unable to decode Ecotrade JSON record: '.$exception->getMessage(), previous: $exception);
        }

        if (! is_array($decoded)) {
            throw new RuntimeException('Ecotrade JSON record must decode to an array.');
        }

        return $decoded;
    }

    /**
     * @return array<string, string>
     */
    public function readMapping(?string $path): array
    {
        if (! is_string($path) || trim($path) === '') {
            return [];
        }

        $resolved = $this->resolvePath($path);

        try {
            $decoded = json_decode((string) file_get_contents($resolved), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            throw new RuntimeException('Unable to decode Ecotrade brand image mapping: '.$exception->getMessage(), previous: $exception);
        }

        if (! is_array($decoded)) {
            throw new RuntimeException('Ecotrade brand image mapping must be a JSON object.');
        }

        $mapping = [];

        foreach ($decoded as $slug => $url) {
            if (! is_string($slug) || ! is_string($url)) {
                continue;
            }

            $slug = Str::of($slug)->trim()->lower()->replace('_', '-')->replace(' ', '-')->toString();
            $url = trim($url);

            if ($slug === '' || $url === '') {
                continue;
            }

            $mapping[$slug] = $url;
        }

        return $mapping;
    }

    private function resolvePath(string $path): string
    {
        if (is_file($path)) {
            return $path;
        }

        $candidates = [
            base_path($path),
            storage_path($path),
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException('Ecotrade import file not found: '.$path);
    }
}
