<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#fcf9f8">
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
    <div class="shell"><p>© Maik Cat</p></div>
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
                        <span class="store-icon" aria-hidden="true">◆</span>
                        <span><small>Android</small><strong>Download</strong></span>
                    </a>
                @else
                    <span class="store-btn primary disabled" aria-disabled="true">
                        <span class="store-icon" aria-hidden="true">◆</span>
                        <span><small>Android</small><strong>Coming Soon</strong></span>
                    </span>
                @endif

                @if ($iosUrl)
                    <a class="store-btn secondary" href="{{ $iosUrl }}" target="_blank" rel="noopener noreferrer">
                        <span class="store-icon apple" aria-hidden="true">●</span>
                        <span><small>iOS</small><strong>Download</strong></span>
                    </a>
                @else
                    <span class="store-btn secondary disabled" aria-disabled="true">
                        <span class="store-icon apple" aria-hidden="true">●</span>
                        <span><small>iOS</small><strong>Coming Soon</strong></span>
                    </span>
                @endif
            </div>
        </div>

        <div class="hero-gallery hero-enter" data-parallax-root>
            <div class="hero-blob" aria-hidden="true"></div>
            <figure class="hero-shot shot-main" data-float="1">
                <img src="{{ asset('images/portfolio/track-visual.webp') }}" alt="Maik Cat metal prices screen with platinum, palladium and rhodium trends" width="640" height="909" fetchpriority="high">
            </figure>
            <figure class="hero-shot shot-mini" data-float="2">
                <img src="{{ asset('images/portfolio/calculate-visual.webp') }}" alt="Maik Cat value calculator screen" width="640" height="944">
            </figure>
            <div class="dots" aria-hidden="true"></div>
        </div>
    </section>

    <section class="story shell" aria-label="Maik Cat features">
        <article class="feature feature-track reveal">
            <div class="feature-copy">
                <h2><em>Track</em> metal prices</h2>
                <p>View platinum, palladium and rhodium trends.</p>
            </div>
            <figure class="feature-media media-track">
                <img src="{{ asset('images/portfolio/track-visual.webp') }}" alt="Metal prices and seven day trends in Maik Cat" width="640" height="909" loading="lazy">
            </figure>
        </article>

        <article class="feature feature-calc reveal">
            <figure class="feature-media media-calc">
                <img src="{{ asset('images/portfolio/calculate-visual.webp') }}" alt="Maik Cat calculator for weight, humidity and metal content" width="640" height="944" loading="lazy">
            </figure>
            <div class="feature-copy">
                <h2><em>Calculate</em> values</h2>
                <p>Estimate by weight, humidity and metal content.</p>
            </div>
        </article>

        <article class="feature feature-search reveal">
            <div class="feature-copy">
                <h2><em>Search</em> quickly</h2>
                <p>Find parts by code and brand.</p>
            </div>
            <figure class="feature-media media-search">
                <img src="{{ asset('images/portfolio/search-visual.webp') }}" alt="Maik Cat most searched catalytic converter parts" width="640" height="853" loading="lazy">
            </figure>
        </article>

        <article class="feature feature-browse reveal">
            <figure class="feature-media media-browse">
                <img src="{{ asset('images/portfolio/browse-visual.webp') }}" alt="Maik Cat product catalog and metal prices" width="640" height="860" loading="lazy">
            </figure>
            <div class="feature-copy">
                <h2><em>Browse</em> the catalog</h2>
                <p>Find products and view metal prices.</p>
            </div>
        </article>
    </section>

    <footer class="footer shell reveal">
        <img src="{{ asset('images/portfolio/logo.webp') }}" alt="Maik Cat" width="210" height="105" loading="lazy">
        <p>© Maik Cat</p>
    </footer>
</main>
</body>
</html>
