<?php

declare(strict_types=1);

namespace App\Services\Ecotrade;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class EcotradeSourceImageDownloader
{
    /** @return array{bytes:string,mime_type:string} */
    public function download(string $url): array
    {
        $timeout = max(1, (int) config('services.gemini.image_download_timeout', 30));

        try {
            $response = Http::connectTimeout($timeout)->timeout($timeout)->get($url)->throw();
        } catch (RequestException $exception) {
            throw new RuntimeException('Unable to download Ecotrade source image: '.$exception->getMessage(), previous: $exception);
        }

        $bytes = $response->body();

        if ($bytes === '') {
            throw new RuntimeException('Downloaded Ecotrade source image is empty.');
        }

        $mimeType = $this->detectMimeType($bytes, (string) $response->header('Content-Type'));

        if (! str_starts_with($mimeType, 'image/')) {
            throw new RuntimeException('Downloaded Ecotrade source is not a supported image.');
        }

        return [
            'bytes' => $bytes,
            'mime_type' => $mimeType,
        ];
    }

    public function extensionForMimeType(string $mimeType): string
    {
        return match (strtolower($mimeType)) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/webp' => 'webp',
            'image/avif' => 'avif',
            'image/png' => 'png',
            default => throw new RuntimeException('Unsupported image MIME type: '.$mimeType),
        };
    }

    private function detectMimeType(string $bytes, string $header): string
    {
        $header = strtolower(trim(strtok($header, ';') ?: ''));

        if (str_starts_with($header, 'image/')) {
            return $header;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $detected = $finfo !== false ? finfo_buffer($finfo, $bytes) : null;

        if ($finfo !== false) {
            finfo_close($finfo);
        }

        return is_string($detected) && str_starts_with($detected, 'image/')
            ? $detected
            : 'application/octet-stream';
    }
}
