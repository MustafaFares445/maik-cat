<?php

use App\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

uses(RefreshDatabase::class);

function watermarkFixTempFile(string $contents, string $suffix): string
{
    $base = tempnam(sys_get_temp_dir(), 'watermark_fix_');

    if ($base === false) {
        throw new RuntimeException('Failed to allocate a temporary watermark fix file.');
    }

    @unlink($base);

    $path = $base.$suffix;
    file_put_contents($path, $contents);

    return $path;
}

function watermarkFixPngBytes(int $width = 320, int $height = 220): string
{
    $image = imagecreatetruecolor($width, $height);
    $background = imagecolorallocate($image, 245, 245, 245);
    $metal = imagecolorallocate($image, 120, 120, 120);

    imagefill($image, 0, 0, $background);
    imagefilledellipse($image, (int) ($width / 2), (int) ($height / 2), (int) ($width * 0.72), (int) ($height * 0.36), $metal);

    ob_start();
    imagepng($image);
    $bytes = (string) ob_get_clean();
    imagedestroy($image);

    return $bytes;
}

function watermarkFixAttachImage(Item $item, ?string $fileName = null): Media
{
    $path = watermarkFixTempFile(watermarkFixPngBytes(), '.png');

    return $item
        ->addMedia($path)
        ->usingFileName($fileName ?? 'watermarked-maikcat.png')
        ->withCustomProperties([
            'maikcat_watermark' => true,
            'watermark_mode' => 'spatie',
            'watermark_asset' => 'resources/images/ecotrade/maikcat-transparent-v2.png',
        ])
        ->toMediaCollection('images');
}

function watermarkFixIssueCsv(array $mediaRows): string
{
    $lines = [
        'media_id,model_id,variant,file_name,relative_path,absolute_path,width,height,file_size_bytes,detection_method,reason,pixel_score,background_darkening,edge_residual_ratio,mask_coverage,fragment_pixels,watermark_mode,watermark_asset',
    ];

    foreach ($mediaRows as $mediaRow) {
        $media = $mediaRow[0];
        $variant = $mediaRow[1];
        $detectionMethod = $mediaRow[2] ?? 'pixel_mask; metadata; filename';
        $pixelScore = $mediaRow[3] ?? 1;

        $lines[] = implode(',', [
            $media->id,
            $media->model_id,
            $variant,
            $media->file_name,
            'storage/app/public/'.$media->id.'/'.$media->file_name,
            $media->getPath(),
            320,
            220,
            1234,
            $detectionMethod,
            'Visible watermark',
            $pixelScore,
            20,
            1.5,
            0.1,
            1000,
            'spatie',
            'resources/images/ecotrade/maikcat-transparent-v2.png',
        ]);
    }

    return watermarkFixTempFile(implode(PHP_EOL, $lines).PHP_EOL, '.csv');
}

beforeEach(function (): void {
    Storage::fake('public');
    Config::set('services.gemini.api_key', 'test-gemini-key');
    Config::set('services.gemini.image_model', 'gemini-2.5-flash-image');
    Config::set('services.gemini.image_cost_usd', 0.039387);
    Config::set('media-library.max_file_size', 1024 * 1024 * 10);
});

test('dry run filters watermark issue CSV to priceable item images', function () {
    $priceable = Item::factory()->create([
        'weight_kg' => 1.5,
        'pt_ppm' => 1200,
        'pd_ppm' => 0,
        'rh_ppm' => 0,
    ]);
    $notPriceable = Item::factory()->create([
        'weight_kg' => 0,
        'pt_ppm' => 0,
        'pd_ppm' => 0,
        'rh_ppm' => 0,
    ]);
    $metadataOnly = Item::factory()->create([
        'weight_kg' => 1.5,
        'pt_ppm' => 1200,
        'pd_ppm' => 0,
        'rh_ppm' => 0,
    ]);
    $priceableMedia = watermarkFixAttachImage($priceable);
    $notPriceableMedia = watermarkFixAttachImage($notPriceable);
    $metadataOnlyMedia = watermarkFixAttachImage($metadataOnly, 'metadata-only-maikcat.png');
    $issueCsv = watermarkFixIssueCsv([
        [$priceableMedia, 'original'],
        [$priceableMedia, 'conversion:thumb'],
        [$notPriceableMedia, 'original'],
        [$metadataOnlyMedia, 'original', 'metadata; filename', 0.02],
    ]);
    $filteredCsv = watermarkFixTempFile('', '.csv');
    $resultCsv = watermarkFixTempFile('', '.csv');

    Http::fake();

    $this->artisan('media:fix-watermark-issues', [
        'report' => $issueCsv,
        '--dry-run' => true,
        '--filtered-report' => $filteredCsv,
        '--result-report' => $resultCsv,
    ])
        ->expectsOutputToContain('Priceable original visual watermark rows: 1')
        ->expectsOutputToContain('Unique original media candidates: 1')
        ->assertExitCode(0);

    Http::assertNothingSent();
    $filteredCsvContents = (string) file_get_contents($filteredCsv);
    expect($filteredCsvContents)->toContain("\n".$priceableMedia->id.',')
        ->not->toContain("\n".$notPriceableMedia->id.',')
        ->not->toContain("\n".$metadataOnlyMedia->id.',')
        ->not->toContain('conversion:thumb');

    @unlink($issueCsv);
    @unlink($filteredCsv);
    @unlink($resultCsv);
});

test('paid run retries prompt until Gemini verifies watermark is gone and replaces media', function () {
    $item = Item::factory()->create([
        'weight_kg' => 1.5,
        'pt_ppm' => 1200,
        'pd_ppm' => 0,
        'rh_ppm' => 0,
    ]);
    $media = watermarkFixAttachImage($item, 'sample-maikcat.png');
    $issueCsv = watermarkFixIssueCsv([[$media, 'original']]);
    $filteredCsv = watermarkFixTempFile('', '.csv');
    $resultCsv = watermarkFixTempFile('', '.csv');
    $firstEditedBytes = watermarkFixPngBytes(330, 230);
    $cleanEditedBytes = watermarkFixPngBytes(340, 240);
    $editPrompts = [];

    Http::fake(function (Request $request) use ($firstEditedBytes, $cleanEditedBytes, &$editPrompts) {
        $modalities = $request->data()['generationConfig']['responseModalities'] ?? [];

        if ($modalities === ['TEXT', 'IMAGE']) {
            $editPrompts[] = $request->data()['contents'][0]['parts'][0]['text'] ?? '';
            $bytes = count($editPrompts) === 1 ? $firstEditedBytes : $cleanEditedBytes;

            return Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                [
                                    'inlineData' => [
                                        'mimeType' => 'image/png',
                                        'data' => base64_encode($bytes),
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200);
        }

        $hasWatermark = count($editPrompts) === 1;

        return Http::response([
            'candidates' => [
                [
                    'content' => [
                        'parts' => [
                            [
                                'text' => json_encode([
                                    'has_watermark' => $hasWatermark,
                                    'watermark_terms' => $hasWatermark ? ['Maik Cat'] : [],
                                    'notes' => $hasWatermark ? 'Faint Maik Cat fragment remains.' : 'No watermark overlay visible.',
                                ]),
                            ],
                        ],
                    ],
                ],
            ],
        ], 200);
    });

    $this->artisan('media:fix-watermark-issues', [
        'report' => $issueCsv,
        '--max-attempts' => 2,
        '--max-cost-usd' => '1',
        '--filtered-report' => $filteredCsv,
        '--result-report' => $resultCsv,
    ])
        ->expectsOutputToContain('Fixed: 1')
        ->assertExitCode(0);

    $newMedia = $item->refresh()->getFirstMedia('images');

    expect($newMedia)->not->toBeNull()
        ->and($newMedia->id)->not->toBe($media->id)
        ->and($newMedia->file_name)->toBe('sample.png')
        ->and(Media::query()->find($media->id))->toBeNull()
        ->and($newMedia->hasGeneratedConversion('thumb'))->toBeTrue()
        ->and($newMedia->hasGeneratedConversion('card'))->toBeTrue()
        ->and($newMedia->hasGeneratedConversion('detail'))->toBeTrue()
        ->and($newMedia->getCustomProperty('maikcat_watermark'))->toBeFalse()
        ->and($newMedia->getCustomProperty('watermark_mode'))->toBe('none')
        ->and($newMedia->getCustomProperty('watermark_fix_source_media_id'))->toBe($media->id)
        ->and($newMedia->getCustomProperty('watermark_fix_attempts'))->toBe(2)
        ->and($editPrompts[0])->toContain('Maik Cat')
        ->and($editPrompts[0])->toContain('EcoTrade')
        ->and($editPrompts[1])->toContain('Previous verification feedback');

    expect(file_get_contents($resultCsv))->toContain('fixed')
        ->toContain('false');

    @unlink($issueCsv);
    @unlink($filteredCsv);
    @unlink($resultCsv);
});
