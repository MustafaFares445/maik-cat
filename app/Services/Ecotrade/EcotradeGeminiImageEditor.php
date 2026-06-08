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
        $apiKey = trim((string) config('services.gemini.api_key'));

        if ($apiKey === '') {
            throw new RuntimeException('GEMINI_API_KEY is not configured.');
        }

        $model = trim((string) config('services.gemini.image_model', 'gemini-2.5-flash-image'));
        $baseUrl = rtrim((string) config('services.gemini.base_url', 'https://generativelanguage.googleapis.com/v1beta'), '/');
        $timeout = max(1, (int) config('services.gemini.image_timeout', 90));
        $retryTimes = max(0, (int) config('services.gemini.image_retry_times', 2));
        $retrySleep = max(0, (int) config('services.gemini.image_retry_sleep_ms', 1000));

        $response = Http::timeout($timeout)
            ->retry($retryTimes, $retrySleep)
            ->post($baseUrl.'/models/'.$model.':generateContent?key='.$apiKey, [
                'contents' => [
                    [
                        'role' => 'user',
                        'parts' => [
                            ['text' => $prompt],
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
                    'responseModalities' => ['IMAGE'],
                ],
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Gemini image edit failed with HTTP '.$response->status().': '.$response->body());
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw new RuntimeException('Gemini image edit returned an invalid JSON response.');
        }

        $image = $this->extractImage($payload);

        if ($image === null) {
            throw new RuntimeException('Gemini image edit response did not contain image bytes.');
        }

        return $image;
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
}
