<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#fbf8f5">
    <meta name="description" content="Maik Cat — precision tools for catalytic converter professionals.">
    <title>Maik Cat</title>
    <link rel="stylesheet" href="{{ asset('css/portfolio.css') }}">
    <script defer src="{{ asset('js/portfolio.js') }}"></script>
</head>
<body>
@php
    $androidUrl = $storeLinks['android'] ?? null;
    $iosUrl = $storeLinks['ios'] ?? null;
@endphp

<svg class="icon-sprite" aria-hidden="true">
    <symbol id="icon-android" viewBox="0 0 24 24">
        <path d="M7.4 8h9.2v8.1c0 .7-.5 1.2-1.2 1.2h-.7v2a1 1 0 0 1-2 0v-2h-1.4v2a1 1 0 0 1-2 0v-2h-.7c-.7 0-1.2-.5-1.2-1.2V8Zm1-4 1.1 1.7a6 6 0 0 1 5 0L15.6 4l.8.5-1 1.6c.8.5 1.3 1.2 1.5 2.1H7.1c.2-.9.7-1.6 1.5-2.1l-1-1.6.8-.5Zm1 3.2a.65.65 0 1 0 0-1.3.65.65 0 0 0 0 1.3Zm5.2 0a.65.65 0 1 0 0-1.3.65.65 0 0 0 0 1.3Z" fill="currentColor"/>
    </symbol>
    <symbol id="icon-apple" viewBox="0 0 24 24">
        <path d="M16.7 12.7c0-2 1.6-3 1.7-3.1a3.7 3.7 0 0 0-2.9-1.6c-1.2-.1-2.4.7-3 .7-.6 0-1.5-.7-2.5-.7-1.3 0-2.6.8-3.3 2-.7 1.2-1.7 3.5-.7 6 .5 1.2 1.1 2.5 2 2.5.8 0 1.1-.5 2.1-.5 1 0 1.3.5 2.2.5.9 0 1.5-1.2 2-2.4.6-1.3.8-2.6.8-2.7-.1 0-1.6-.6-1.6-2.7Zm-2-6c.7-.8.6-1.6.6-1.9-.7 0-1.6.5-2 1-.5.5-.8 1.2-.7 1.9.8.1 1.6-.4 2.1-1Z" fill="currentColor"/>
    </symbol>
    <symbol id="icon-trend" viewBox="0 0 24 24"><path d="M5 16.5 9.2 12l3.1 2.8L19 7.5M14.5 7.5H19V12"/></symbol>
    <symbol id="icon-badge" viewBox="0 0 24 24"><path d="m12 3 2 2.2 3-.4.7 3 2.6 1.5-1.2 2.7 1.2 2.7-2.6 1.5-.7 3-3-.4-2 2.2-2-2.2-3 .4-.7-3-2.6-1.5L4.9 12 3.7 9.3l2.6-1.5.7-3 3 .4L12 3Z"/></symbol>
    <symbol id="icon-user" viewBox="0 0 24 24"><circle cx="12" cy="8" r="3.4"/><path d="M5.5 20c.4-4 2.7-6 6.5-6s6.1 2 6.5 6"/></symbol>
    <symbol id="icon-lock" viewBox="0 0 24 24"><rect x="6" y="10" width="12" height="10" rx="2"/><path d="M8.7 10V7.7a3.3 3.3 0 0 1 6.6 0V10"/></symbol>
    <symbol id="icon-target" viewBox="0 0 24 24"><circle cx="12" cy="12" r="7"/><circle cx="12" cy="12" r="3"/><path d="M12 3v2M21 12h-2M12 21v-2M3 12h2"/></symbol>
    <symbol id="icon-chart" viewBox="0 0 24 24"><path d="M5 19V9m5 10V5m5 14v-7m4 7V3"/><path d="m4 14 5-5 4 3 7-7"/></symbol>
    <symbol id="icon-clock" viewBox="0 0 24 24"><circle cx="12" cy="12" r="8"/><path d="M12 7v5l3.5 2"/></symbol>
    <symbol id="icon-shield" viewBox="0 0 24 24"><path d="M12 3 19 6v5c0 4.5-2.5 7.7-7 10-4.5-2.3-7-5.5-7-10V6l7-3Z"/><path d="m9 12 2 2 4-5"/></symbol>
    <symbol id="icon-search" viewBox="0 0 24 24"><circle cx="10.5" cy="10.5" r="5.5"/><path d="m15 15 4 4"/></symbol>
    <symbol id="icon-book" viewBox="0 0 24 24"><path d="M4 5.5c2.8-.7 5.4-.3 8 1.2v12c-2.6-1.5-5.2-1.9-8-1.2v-12Zm16 0c-2.8-.7-5.4-.3-8 1.2v12c2.6-1.5 5.2-1.9 8-1.2v-12Z"/></symbol>
    <symbol id="icon-factory" viewBox="0 0 24 24"><path d="M4 20V9l5 3V8l5 3V5h3v15H4Z"/><path d="M7 16h2m3 0h2m3 0h2"/></symbol>
</svg>

<header class="site-header">
    <div class="shell header-inner">
        <a class="header-brand" href="#top" aria-label="Maik Cat home">
            <img src="{{ asset('images/portfolio/logo-mark.svg') }}" alt="Maik Cat" width="200" height="90">
        </a>
    </div>
</header>

<main id="top">
    <section class="hero-wrap" id="product" aria-labelledby="hero-title">
        <div class="hero-lines hero-lines-left" aria-hidden="true"></div>
        <div class="hero-lines hero-lines-right" aria-hidden="true"></div>
        <div class="hero shell">
            <div class="hero-copy">
                <p class="eyebrow hero-enter">MAIK CAT <span>•</span></p>
                <h1 class="hero-title hero-enter" id="hero-title">Precision tools for <em>catalytic converter</em> professionals</h1>
                <p class="hero-description hero-enter">Track live metal prices, calculate values, search parts, and browse the catalog — all in one industrial-grade mobile experience.</p>
                <div class="downloads hero-enter" id="download">
                    @if ($androidUrl)
                        <a class="store-btn primary" href="{{ $androidUrl }}" target="_blank" rel="noopener noreferrer"><svg class="store-icon" aria-hidden="true"><use href="#icon-android"/></svg><strong>Download for Android</strong></a>
                    @else
                        <span class="store-btn primary disabled" aria-disabled="true"><svg class="store-icon" aria-hidden="true"><use href="#icon-android"/></svg><strong>Download for Android</strong></span>
                    @endif
                    @if ($iosUrl)
                        <a class="store-btn secondary" href="{{ $iosUrl }}" target="_blank" rel="noopener noreferrer"><svg class="store-icon" aria-hidden="true"><use href="#icon-apple"/></svg><strong>Download for iOS</strong></a>
                    @else
                        <span class="store-btn secondary disabled" aria-disabled="true"><svg class="store-icon" aria-hidden="true"><use href="#icon-apple"/></svg><strong>iOS — Coming Soon</strong></span>
                    @endif
                </div>
                <div class="trust-row hero-enter" aria-label="Product benefits">
                    <div><span class="round-icon"><svg><use href="#icon-trend"/></svg></span><p>Live price<br>updates</p></div>
                    <div><span class="round-icon"><svg><use href="#icon-badge"/></svg></span><p>Industrial<br>accuracy</p></div>
                    <div><span class="round-icon"><svg><use href="#icon-user"/></svg></span><p>Trusted by<br>professionals</p></div>
                    <div><span class="round-icon"><svg><use href="#icon-lock"/></svg></span><p>Secure &amp;<br>reliable</p></div>
                </div>
            </div>
            <div class="hero-visual hero-enter" aria-label="Maik Cat mobile app with precious metal bars">
                <div class="hero-dots" aria-hidden="true"></div>
                <div class="orange-swoop" aria-hidden="true"></div>
                <img class="hero-phone" src="{{ asset('images/portfolio/hero-phone-v2-cutout.png') }}" alt="Maik Cat metal prices mobile app" width="941" height="1672" fetchpriority="high" decoding="async">
                <img class="metal metal-platinum" src="{{ asset('images/portfolio/platinum-bar.png') }}" alt="Platinum 999.5 one ounce bar" width="1536" height="1024" fetchpriority="high">
                <img class="metal metal-palladium" src="{{ asset('images/portfolio/palladium-bar.png') }}" alt="Palladium 999.5 one ounce bar" width="1254" height="1254" fetchpriority="high">
                <img class="metal metal-rhodium" src="{{ asset('images/portfolio/rhodium-bar.png') }}" alt="Rhodium 999.0 one ounce bar" width="1254" height="1254" fetchpriority="high">
            </div>
        </div>
        <img class="hero-converter" src="{{ asset('images/portfolio/catalytic-converter.png') }}" alt="" width="1536" height="1024" aria-hidden="true">
    </section>

    <section class="stats reveal" id="pricing" aria-label="Maik Cat product highlights">
        <div class="stat"><span class="stat-icon"><svg><use href="#icon-user"/></svg></span><strong>10K+</strong><p>Professionals<br>trust Maik Cat</p></div>
        <div class="stat"><span class="stat-icon"><svg><use href="#icon-chart"/></svg></span><strong>99.9%</strong><p>Accurate data<br>you can rely on</p></div>
        <div class="stat"><span class="stat-icon"><svg><use href="#icon-clock"/></svg></span><strong>6h</strong><p>Prices updated<br>every 6 hours</p></div>
        <div class="stat"><span class="stat-icon"><svg><use href="#icon-shield"/></svg></span><strong>100%</strong><p>Built for industrial<br>environments</p></div>
    </section>

    <section class="features shell reveal" id="features" aria-labelledby="features-title">
        <div class="features-intro">
            <p class="section-kicker">EVERY TOOL YOU NEED <span></span></p>
            <h2 id="features-title">Powerful features.<br>Built for the <em>industry.</em></h2>
            <p>From live market data to advanced calculations, Maik Cat gives you the edge to make confident, profitable decisions.</p>
            <article class="feature-note"><span class="feature-note-icon blue"><svg><use href="#icon-target"/></svg></span><div><strong>Track metal prices</strong><p>Monitor platinum, palladium, and rhodium with live updates and 7-day price trends.</p></div></article>
            <article class="feature-note"><span class="feature-note-icon orange"><svg><use href="#icon-chart"/></svg></span><div><strong>Calculate values</strong><p>Estimate value by weight, humidity, and metal content with precision.</p></div></article>
        </div>
        <div class="calculator-stage">
            <div class="calculator-glow" aria-hidden="true"></div>
            <img class="calculator-phone" src="{{ asset('images/portfolio/calculator-phone.png') }}" alt="Maik Cat catalytic converter calculator screen" width="941" height="1672" loading="lazy" decoding="async">
        </div>
        <div class="feature-actions">
            <article class="action-card">
                <div class="action-head"><span class="action-icon blue"><svg><use href="#icon-search"/></svg></span><div><strong>Search quickly</strong><p>Find parts by code and brand in seconds.</p></div></div>
                <div class="product-mini"><img src="{{ asset('images/portfolio/catalytic-converter.png') }}" alt="Catalytic converter search result" loading="lazy"><div><b>8670409</b><span>Volvo</span></div></div>
            </article>
            <article class="action-card">
                <div class="action-head"><span class="action-icon orange"><svg><use href="#icon-book"/></svg></span><div><strong>Browse the catalog</strong><p>Explore products and view prices for the most searched parts.</p></div></div>
                <div class="product-mini"><img class="flipped" src="{{ asset('images/portfolio/catalytic-converter.png') }}" alt="Catalytic converter catalog result" loading="lazy"><div><b>9146685</b><span>Volvo</span></div></div>
            </article>
        </div>
    </section>

    <section class="industry-band" id="about" aria-labelledby="industry-title">
        <div class="shell industry-grid reveal">
            <h2 id="industry-title">Built for recyclers, traders,<br>and catalytic converter <em>professionals.</em></h2>
            <div class="industry-point"><span><svg><use href="#icon-factory"/></svg></span><div><strong>Industrial grade</strong><p>Designed for real-world conditions and daily industrial use.</p></div></div>
            <div class="industry-point"><span><svg><use href="#icon-badge"/></svg></span><div><strong>Data you can trust</strong><p>Reliable sources and consistent updates for confident decisions.</p></div></div>
            <div class="industry-point"><span><svg><use href="#icon-shield"/></svg></span><div><strong>Privacy first</strong><p>Your data stays secure — we never share your information.</p></div></div>
            <img class="footer-converter" src="{{ asset('images/portfolio/catalytic-converter.png') }}" alt="" width="1536" height="1024" aria-hidden="true" loading="lazy">
        </div>
    </section>
</main>

<footer class="site-footer">
    <p class="footer-copy">© {{ date('Y') }} Maik Cat. All rights reserved.</p>
</footer>
</body>
</html>
