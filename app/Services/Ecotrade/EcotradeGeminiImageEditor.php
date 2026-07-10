<?php

namespace App\Services\Ecotrade;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class EcotradeGeminiImageEditor
{
    /**
     * @return array{bytes: string, mime_type: string}
     */
    public function edit(string $imageBytes, string $mimeType, string $prompt): array
    {
        $payload = $this->generateContent($imageBytes, $mimeType, $prompt, ['TEXT', 'IMAGE']);
        $image = $this->extractImage($payload);

        if ($image === null) {
            throw new EcotradeGeminiImageUnavailableException($this->extractText($payload));
        }

        return $image;
    }

    public function inspect(string $imageBytes, string $mimeType, string $prompt): string
    {
        $payload = $this->generateContent($imageBytes, $mimeType, $prompt, ['TEXT']);
        $text = $this->extractText($payload);

        if ($text === null) {
            throw new RuntimeException('Gemini image inspection returned no text.');
        }

        return $text;
    }

    /**
     * @return array<string, mixed>
     */
    private function generateContent(string $imageBytes, string $mimeType, string $prompt, array $responseModalities): array
    {
        $apiKey = trim((string) config('services.gemini.api_key'));

        if ($apiKey === '') {
            throw new RuntimeException('GEMINI_API_KEY is not configured.');
        }

        $model = trim((string) config('services.gemini.image_model', 'gemini-2.5-flash-image'));
        $baseUrl = rtrim((string) config('services.gemini.base_url', 'https://generativelanguage.googleapis.com/v1beta'), '/');
        $timeout = max(1, (int) config('services.gemini.image_timeout', 90));
        $retryTimes = max(0, (int) config('services.gemini.image_retry_times', 2));
        $retrySleep = max(0, (int) config('services.gemini.image_retry_sleep_ms', 1000));
        $url = rtrim($baseUrl, '/').'/models/'.$model.':generateContent';

        $response = Http::timeout($timeout)
            ->retry($retryTimes, $retrySleep)
            ->withHeaders([
                'x-goog-api-key' => $apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])
            ->post($url, [
                'contents' => [
                    [
                        'role' => 'user',
                        'parts' => [
                            [
                                'text' => $prompt,
                            ],
                            [
                                'inline_data' => [
                                    'mime_type' => $mimeType,
                                    'data' => base64_encode($imageBytes),
                                ],
                            ],
                        ],
                    ],
                ],
                'generationConfig' => [
                    'responseModalities' => $responseModalities,
                ],
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Gemini image request failed with HTTP '.$response->status().': '.$response->body());
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw new RuntimeException('Gemini image request returned an invalid JSON response.');
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{bytes: string, mime_type: string}|null
     */
    private function extractImage(array $payload): ?array
    {
        foreach (($payload['candidates'] ?? []) as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            $content = $candidate['content'] ?? null;
            $parts = is_array($content) ? ($content['parts'] ?? []) : [];

            foreach ($parts as $part) {
                if (! is_array($part)) {
                    continue;
                }

                $inlineData = $part['inlineData'] ?? $part['inline_data'] ?? null;

                if (! is_array($inlineData)) {
                    $uri = $part['fileUri']
                        ?? $part['downloadUri']
                        ?? $part['fileData']['fileUri']
                        ?? $part['file_data']['file_uri']
                        ?? $part['uri']
                        ?? null;

                    if (! is_string($uri) || trim($uri) === '') {
                        continue;
                    }

                    $downloaded = $this->downloadImageUri($uri);

                    if ($downloaded !== null) {
                        return $downloaded;
                    }

                    continue;
                }

                $encoded = $inlineData['data'] ?? null;

                if (! is_string($encoded) || trim($encoded) === '') {
                    continue;
                }

                $bytes = base64_decode($encoded, true);

                if (! is_string($bytes) || $bytes === '') {
                    continue;
                }

                $mimeType = $inlineData['mimeType'] ?? $inlineData['mime_type'] ?? 'image/png';

                return [
                    'bytes' => $bytes,
                    'mime_type' => is_string($mimeType) && $mimeType !== '' ? $mimeType : 'image/png',
                ];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extractText(array $payload): ?string
    {
        foreach (($payload['candidates'] ?? []) as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            $content = $candidate['content'] ?? null;
            $parts = is_array($content) ? ($content['parts'] ?? []) : [];

            foreach ($parts as $part) {
                if (! is_array($part)) {
                    continue;
                }

                $text = $part['text'] ?? null;

                if (is_string($text) && trim($text) !== '') {
                    return trim($text);
                }
            }
        }

        return null;
    }

    /**
     * @return array{bytes: string, mime_type: string}|null
     */
    private function downloadImageUri(string $uri): ?array
    {
        try {
            $response = Http::timeout(max(1, (int) config('services.gemini.image_download_timeout', 30)))
                ->get($uri);
        } catch (\Throwable) {
            return null;
        }

        if ($response->failed()) {
            return null;
        }

        $bytes = $response->body();

        if ($bytes === '') {
            return null;
        }

        return [
            'bytes' => $bytes,
            'mime_type' => $this->detectMimeType($bytes, (string) $response->header('Content-Type')),
        ];
    }

    private function detectMimeType(string $bytes, string $header): string
    {
        $header = strtolower(trim(strtok($header, ';') ?: ''));

        if (str_starts_with($header, 'image/')) {
            return $header;
        }

        $detected = finfo_buffer(finfo_open(FILEINFO_MIME_TYPE), $bytes) ?: null;

        return is_string($detected) && str_starts_with($detected, 'image/') ? $detected : 'image/png';
    }
}
