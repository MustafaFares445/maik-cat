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
    <div class="shell">
        <p>© Maik Cat</p>
    </div>
</header>

<main>
    <div class="shell hero">
        <div class="hero-copy">
            <div class="brand-mark hero-enter" aria-label="Maik Cat">
                <span>Maik</span><small>Cat</small><i></i>
            </div>

            <h1 class="hero-title">
                <span class="hero-enter"><strong>Track</strong> metal prices</span>
                <span class="hero-enter"><strong>Calculate</strong> values</span>
                <span class="hero-enter"><strong>Search</strong> quickly</span>
                <span class="hero-enter"><strong>Browse</strong> the catalog</span>
            </h1>

            <p class="hero-copyline hero-enter">
                View platinum, palladium and rhodium trends. Estimate by weight, humidity and metal content.
                Find parts by code and brand. Find products and view metal prices.
            </p>

            <div class="downloads hero-enter">
                @if ($androidUrl)
                    <a class="store-btn primary" href="{{ $androidUrl }}" target="_blank" rel="noopener noreferrer">
                        <span>Android</span><strong>Download</strong>
                    </a>
                @else
                    <span class="store-btn primary disabled" aria-disabled="true">
                        <span>Android</span><strong>Coming Soon</strong>
                    </span>
                @endif

                @if ($iosUrl)
                    <a class="store-btn secondary" href="{{ $iosUrl }}" target="_blank" rel="noopener noreferrer">
                        <span>iOS</span><strong>Download</strong>
                    </a>
                @else
                    <span class="store-btn secondary disabled" aria-disabled="true">
                        <span>iOS</span><strong>Coming Soon</strong>
                    </span>
                @endif
            </div>
        </div>

        <div class="hero-stage hero-enter" data-tilt data-strength="5">
            <div class="orange-orbit" aria-hidden="true"></div>
            <div class="metal-chip platinum">PLATINUM<small>999.5 · 1 oz</small></div>
            <div class="metal-chip palladium">PALLADIUM<small>999.5 · 1 oz</small></div>
            <div class="metal-chip rhodium">RHODIUM<small>999.0 · 1 oz</small></div>

            <div class="phone phone-main">
                <div class="speaker"></div>
                <div class="phone-top">
                    <div class="brand-mark mini"><span>Maik</span><small>Cat</small><i></i></div>
                    <span class="bell">◌</span>
                </div>

                <div class="price-card">
                    <div class="price-head">
                        <div><strong>Metal Prices</strong><small>Updated every 6 hours</small></div>
                        <span class="live">Live now</span>
                    </div>
                    <div class="price-grid labels">
                        <span>Metal</span><span>Change</span><span>Ounce ($)</span><span>Gram ($)</span>
                    </div>
                    <div class="price-grid">
                        <strong>Platinum (PT)</strong><b class="down">0.6% ↓</b><strong>1,599.00</strong><strong>51.41</strong>
                    </div>
                    <div class="price-grid">
                        <strong>Palladium (PD)</strong><b class="up">1.0% ↑</b><strong>1,250.00</strong><strong>40.19</strong>
                    </div>
                    <div class="price-grid">
                        <strong>Rhodium (RH)</strong><b>0.0% ›</b><strong>7,925.00</strong><strong>254.79</strong>
                    </div>
                </div>

                <div class="trend-card">
                    <div class="trend-head"><strong>Price Trends</strong><span>7D</span></div>
                    <div class="trend-row">
                        <div><small>Platinum (PT)</small><strong>1,599.00</strong><b class="down">0.6% ↓</b></div>
                        <svg viewBox="0 0 180 60" aria-hidden="true"><path class="blue" d="M2 49 C18 29,28 38,41 29 S63 40,76 26 S98 32,111 17 S132 24,145 11 S164 22,178 13"/></svg>
                    </div>
                    <div class="trend-row">
                        <div><small>Palladium (PD)</small><strong>1,250.00</strong><b class="up">1.0% ↑</b></div>
                        <svg viewBox="0 0 180 60" aria-hidden="true"><path class="orange" d="M2 49 C14 30,24 42,37 31 S57 38,72 21 S92 31,106 20 S129 27,143 11 S160 32,178 18"/></svg>
                    </div>
                    <div class="trend-row">
                        <div><small>Rhodium (RH)</small><strong>7,925.00</strong><b>0.0% ›</b></div>
                        <svg viewBox="0 0 180 60" aria-hidden="true"><path class="dark" d="M2 51 C15 28,27 41,39 33 S59 42,74 24 S97 35,110 22 S133 29,146 13 S164 30,178 15"/></svg>
                    </div>
                </div>

                <div class="phone-nav">
                    <span class="active">Home</span><span>Search</span><span>Calculator</span><span>Charts</span><span>Settings</span>
                </div>
            </div>
        </div>
    </div>

    <div class="shell flow">
        <article class="feature reveal" data-tilt data-strength="2.3">
            <div class="feature-copy">
                <h2><strong>Track</strong> metal prices</h2>
                <p>View platinum, palladium and rhodium trends.</p>
            </div>
            <div class="feature-visual">
                <div class="mini-screen track-screen">
                    <div class="screen-title">Metal Prices <span>Live now</span></div>
                    <div class="mini-row"><b>Platinum (PT)</b><em>0.6% ↓</em><strong>1,599.00</strong><span>51.41</span></div>
                    <div class="mini-row"><b>Palladium (PD)</b><em>1.0% ↑</em><strong>1,250.00</strong><span>40.19</span></div>
                    <div class="mini-row"><b>Rhodium (RH)</b><em>0.0% ›</em><strong>7,925.00</strong><span>254.79</span></div>
                    <div class="chart-lines"><i></i><i></i><i></i></div>
                </div>
                <div class="chip-stack"><i></i><i></i><i></i></div>
            </div>
        </article>

        <article class="feature reverse reveal" data-tilt data-strength="2.3">
            <div class="feature-copy">
                <h2><strong>Calculate</strong> values</h2>
                <p>Estimate by weight, humidity and metal content.</p>
            </div>
            <div class="feature-visual">
                <div class="mini-screen calc-screen">
                    <div class="calc-table"><span>Pt</span><b>1599.00</b><b>51.41</b><span>Pd</span><b>1250.00</b><b>40.19</b><span>Rh</span><b>7925.00</b><b>254.79</b></div>
                    <h3>Calculator</h3>
                    <div class="calc-values"><span><small>Pt</small><strong>51.41</strong></span><span><small>Pd</small><strong>40.19</strong></span><span><small>Rh</small><strong>254.79</strong></span></div>
                    <div class="inputs"><span>Weight <b>0</b></span><span>Humidity % <b>5</b></span></div>
                    <div class="calculate-button">Calculate</div>
                </div>
            </div>
        </article>

        <article class="feature reveal" data-tilt data-strength="2.3">
            <div class="feature-copy">
                <h2><strong>Search</strong> quickly</h2>
                <p>Find parts by code and brand.</p>
            </div>
            <div class="feature-visual">
                <div class="mini-screen search-screen">
                    <div class="screen-title">Most searched <span>Show all</span></div>
                    <div class="part-row"><div class="part-shape"></div><div><small>8670409</small><em>Volvo</em><strong>8670409</strong></div><b>♡</b></div>
                    <div class="part-row"><div class="part-shape short"></div><div><small>485853</small><em>Volvo</em><strong>485853</strong></div><b>♡</b></div>
                    <div class="part-row"><div class="part-shape slim"></div><div><small>9146685</small><em>Volvo</em><strong>9146685</strong></div><b>♡</b></div>
                </div>
            </div>
        </article>

        <article class="feature reverse reveal" data-tilt data-strength="2.3">
            <div class="feature-copy">
                <h2><strong>Browse</strong> the catalog</h2>
                <p>Find products and view metal prices.</p>
            </div>
            <div class="feature-visual">
                <div class="mini-screen catalog-screen">
                    <div class="screen-title">Metal Prices <span>Live now</span></div>
                    <div class="catalog-price"><span>Platinum (PT)</span><b>1,599.00</b><strong>51.41</strong></div>
                    <div class="catalog-price"><span>Palladium (PD)</span><b>1,250.00</b><strong>40.19</strong></div>
                    <div class="catalog-price"><span>Rhodium (RH)</span><b>7,925.00</b><strong>254.79</strong></div>
                    <div class="screen-title lower">Most searched <span>Show all</span></div>
                    <div class="part-row"><div class="part-shape"></div><div><small>8670409</small><em>Volvo</em><strong>8670409</strong></div><b>♡</b></div>
                </div>
            </div>
        </article>
    </div>

    <div class="shell ending reveal">
        <div class="brand-mark"><span>Maik</span><small>Cat</small><i></i></div>
    </div>
</main>
</body>
</html>
