<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#fbf7f3">
    <meta name="description" content="Maik Cat — track metal prices, calculate values, search quickly, and browse the catalog.">
    <title>Maik Cat</title>
    <link rel="stylesheet" href="{{ asset('css/portfolio.css') }}">
    <script defer src="{{ asset('js/portfolio.js') }}"></script>
</head>
<body>
@php
    $androidUrl = $storeLinks['android'] ?? null;
    $iosUrl = $storeLinks['ios'] ?? null;
@endphp

<header class="site-header">
    <div class="shell">
        <p>© Maik Cat</p>
    </div>
</header>

<main>
    <section class="hero shell">
        <div class="hero-copy">
            <img class="brand-logo hero-enter" src="{{ asset('images/portfolio/logo.webp') }}" alt="Maik Cat" width="210" height="105">

            <h1 class="hero-title" aria-label="Track metal prices. Calculate values. Search quickly. Browse the catalog.">
                <span class="hero-enter"><em>Track</em> metal prices</span>
                <span class="hero-enter"><em>Calculate</em> values</span>
                <span class="hero-enter"><em>Search</em> quickly</span>
                <span class="hero-enter"><em>Browse</em> the catalog</span>
            </h1>

            <p class="hero-sub hero-enter">Everything you need to work with metals in one powerful app.</p>

            <div class="downloads hero-enter">
                @if ($androidUrl)
                    <a class="store-btn primary" href="{{ $androidUrl }}" target="_blank" rel="noopener noreferrer">
                        <span class="store-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" role="img"><path d="M7.3 7.2h9.4l.8 1.3v7.9a1.2 1.2 0 0 1-1.2 1.2h-.6v2a1 1 0 0 1-2 0v-2H10.3v2a1 1 0 0 1-2 0v-2h-.6a1.2 1.2 0 0 1-1.2-1.2V8.5l.8-1.3Zm1-3.2 1.2 1.8h5L15.7 4l.8.5-1 1.5c1.1.4 1.9 1.4 2.1 2.6H6.4c.2-1.2 1-2.2 2.1-2.6l-1-1.5.8-.5Zm1.3 3.4a.8.8 0 1 0 0-1.6.8.8 0 0 0 0 1.6Zm4.8 0a.8.8 0 1 0 0-1.6.8.8 0 0 0 0 1.6Z" fill="currentColor"/></svg>
                        </span>
                        <strong>Download for Android</strong>
                    </a>
                @else
                    <span class="store-btn primary disabled" aria-disabled="true">
                        <span class="store-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" role="img"><path d="M7.3 7.2h9.4l.8 1.3v7.9a1.2 1.2 0 0 1-1.2 1.2h-.6v2a1 1 0 0 1-2 0v-2H10.3v2a1 1 0 0 1-2 0v-2h-.6a1.2 1.2 0 0 1-1.2-1.2V8.5l.8-1.3Zm1-3.2 1.2 1.8h5L15.7 4l.8.5-1 1.5c1.1.4 1.9 1.4 2.1 2.6H6.4c.2-1.2 1-2.2 2.1-2.6l-1-1.5.8-.5Zm1.3 3.4a.8.8 0 1 0 0-1.6.8.8 0 0 0 0 1.6Zm4.8 0a.8.8 0 1 0 0-1.6.8.8 0 0 0 0 1.6Z" fill="currentColor"/></svg>
                        </span>
                        <strong>Android — Coming Soon</strong>
                    </span>
                @endif

                @if ($iosUrl)
                    <a class="store-btn secondary" href="{{ $iosUrl }}" target="_blank" rel="noopener noreferrer">
                        <span class="store-icon apple" aria-hidden="true">●</span>
                        <strong>Download for iOS</strong>
                    </a>
                @else
                    <span class="store-btn secondary disabled" aria-disabled="true">
                        <span class="store-icon apple" aria-hidden="true">●</span>
                        <strong>iOS — Coming Soon</strong>
                    </span>
                @endif
            </div>
        </div>

        <div class="hero-visual hero-enter" data-parallax>
            <div class="hero-dots" aria-hidden="true"></div>
            <img class="hero-image" src="{{ asset('images/portfolio/track-visual.webp') }}" alt="Maik Cat metal prices screen with platinum, palladium and rhodium trends" width="1200" height="900" fetchpriority="high">
        </div>
    </section>

    <section class="feature-stack shell" aria-label="Maik Cat features">
        <article class="feature-card feature-track reveal">
            <div class="feature-copy">
                <h2><em>Track</em> metal prices</h2>
                <p>View platinum, palladium and rhodium trends.</p>
            </div>
            <div class="feature-art">
                <img src="{{ asset('images/portfolio/track-visual.webp') }}" alt="Metal prices and price trends in Maik Cat" width="1200" height="900" loading="lazy">
            </div>
        </article>

        <article class="feature-card feature-calculate reverse reveal">
            <div class="feature-copy">
                <h2><em>Calculate</em> values</h2>
                <p>Estimate by weight, humidity and metal content.</p>
            </div>
            <div class="feature-art">
                <img src="{{ asset('images/portfolio/calculate-visual.webp') }}" alt="Maik Cat calculator for weight, humidity and metal content" width="1200" height="900" loading="lazy">
            </div>
        </article>

        <article class="feature-card feature-search reveal">
            <div class="feature-copy">
                <h2><em>Search</em> quickly</h2>
                <p>Find parts by code and brand.</p>
            </div>
            <div class="feature-art">
                <img src="{{ asset('images/portfolio/search-visual.webp') }}" alt="Maik Cat part search and most searched products" width="1200" height="900" loading="lazy">
            </div>
        </article>

        <article class="feature-card feature-browse reverse reveal">
            <div class="feature-copy">
                <h2><em>Browse</em> the catalog</h2>
                <p>Find products and view metal prices.</p>
            </div>
            <div class="feature-art">
                <img src="{{ asset('images/portfolio/browse-visual.webp') }}" alt="Maik Cat product catalog and metal prices" width="1200" height="900" loading="lazy">
            </div>
        </article>
    </section>
</main>
</body>
</html>
