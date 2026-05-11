<?php

use App\Services\Mobile\MetalsSpotService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Cache::flush();
    config([
        'services.metals.base_url' => 'https://metal-sentinel.p.rapidapi.com',
        'services.metals.rapidapi_key' => 'test-key',
        'services.metals.timeout' => 5,
        'services.metals.cache_ttl' => 120,
    ]);
});

test('fetches and normalizes PT PD RH from Metal Sentinel using bid', function (): void {
    Http::fake(function (Request $request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $symbol = $query['symbol'] ?? '';

        $prices = ['PT' => 1000.0, 'PD' => 1500.0, 'RH' => 5000.0];

        if (! isset($prices[$symbol])) {
            return Http::response([], 404);
        }

        $oz = $prices[$symbol];

        return Http::response([
            'symbol' => $symbol,
            'currency' => $query['currency'] ?? 'USD',
            'price' => $oz - 1,
            'bid' => $oz,
            'change' => 10,
            'changePercent' => 0.5,
        ], 200);
    });

    $service = app(MetalsSpotService::class);
    $result = $service->all('USD');

    expect($result['source'])->toBe('metal-sentinel')
        ->and($result['cached'])->toBeFalse()
        ->and($result['currency'])->toBe('USD')
        ->and($result['fx_rate'])->toBe(1.0)
        ->and($result['data'])->toHaveCount(3);

    $platinum = collect($result['data'])->firstWhere('key', 'platinum');
    expect($platinum)->not->toBeNull()
        ->and($platinum['price_oz'])->toBe(1000.0)
        ->and($platinum['direction'])->toBe('up');
});

test('unwraps RapidAPI ID and results wrapper JSON', function (): void {
    Http::fake(function (Request $request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $symbol = $query['symbol'] ?? '';

        $prices = ['PT' => 1100.0, 'PD' => 1600.0, 'RH' => 5100.0];

        if (! isset($prices[$symbol])) {
            return Http::response([], 404);
        }

        $oz = $prices[$symbol];

        return Http::response([
            'ID' => 'trace',
            'results' => [
                'symbol' => $symbol,
                'currency' => $query['currency'] ?? 'USD',
                'price' => $oz - 1,
                'bid' => $oz,
                'change' => 0,
                'changePercent' => 0,
            ],
        ], 200);
    });

    $service = app(MetalsSpotService::class);
    $result = $service->all('USD');

    $platinum = collect($result['data'])->firstWhere('key', 'platinum');
    expect($platinum)->not->toBeNull()
        ->and($platinum['price_oz'])->toBe(1100.0);
});

test('uses bid when price and spotPrice differ', function (): void {
    Http::fake(function (Request $request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $symbol = $query['symbol'] ?? '';

        $bidBySymbol = ['PT' => 950.0, 'PD' => 1400.0, 'RH' => 4800.0];

        if (! isset($bidBySymbol[$symbol])) {
            return Http::response([], 404);
        }

        return Http::response([
            'ID' => 'trace',
            'results' => [
                'symbol' => $symbol,
                'currency' => $query['currency'] ?? 'USD',
                'price' => 100.0,
                'spotPrice' => 200.0,
                'bid' => $bidBySymbol[$symbol],
                'change' => 0,
                'changePercent' => 0,
            ],
        ], 200);
    });

    $service = app(MetalsSpotService::class);
    $result = $service->all('USD');

    $rhodium = collect($result['data'])->firstWhere('key', 'rhodium');
    expect($rhodium)->not->toBeNull()
        ->and($rhodium['price_oz'])->toBe(4800.0);
});

test('second request uses cache for same currency', function (): void {
    Http::fake([
        '*' => Http::response(['bid' => 100.0, 'change' => 0.0, 'changePercent' => 0.0], 200),
    ]);

    $service = app(MetalsSpotService::class);

    $service->all('USD');
    $service->all('USD');

    Http::assertSentCount(3);
});

test('throws when upstream returns error status', function (): void {
    Http::fake([
        '*' => Http::response([], 503),
    ]);

    $service = app(MetalsSpotService::class);

    expect(fn () => $service->all('USD'))
        ->toThrow(RuntimeException::class, MetalsSpotService::UPSTREAM_UNAVAILABLE_MESSAGE);
});

test('throws when api key is missing', function (): void {
    config(['services.metals.rapidapi_key' => '']);

    Http::fake();

    $service = app(MetalsSpotService::class);

    expect(fn () => $service->all('USD'))
        ->toThrow(RuntimeException::class, MetalsSpotService::UPSTREAM_UNAVAILABLE_MESSAGE);

    Http::assertNothingSent();
});

test('refresh clears usd and eur cache keys', function (): void {
    Http::fake([
        '*' => Http::response(['bid' => 100.0, 'change' => 0.0, 'changePercent' => 0.0], 200),
    ]);

    $service = app(MetalsSpotService::class);

    $service->all('USD');
    $service->refresh('USD');

    Http::assertSentCount(6);
});
