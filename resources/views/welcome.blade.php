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

<header class="site-header">
    <div class="shell header-inner">
        <p>© Maik Cat</p>
    </div>
</header>

<main>
    <div class="hero-wrap">
        <div class="hero-lines hero-lines-left" aria-hidden="true"></div>
        <div class="hero-lines hero-lines-right" aria-hidden="true"></div>
        <div class="hero shell">
            <div class="hero-copy">
                <img class="brand-logo hero-enter" src="{{ asset('images/portfolio/logo.webp') }}" alt="Maik Cat" width="210" height="105">
                <p class="eyebrow hero-enter">MAIK CAT <span>•</span></p>
                <h1 class="hero-title hero-enter">Precision tools for <em>catalytic converter</em> professionals</h1>
                <p class="hero-description hero-enter">Track live metal prices, calculate values, search parts, and browse the catalog — all in one industrial-grade mobile experience.</p>

                <div class="downloads hero-enter">
                    @if ($androidUrl)
                        <a class="store-btn primary" href="{{ $androidUrl }}" target="_blank" rel="noopener noreferrer">
                            <span class="store-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24"><path d="M7.3 7.2h9.4l.8 1.3v7.9a1.2 1.2 0 0 1-1.2 1.2h-.6v2a1 1 0 0 1-2 0v-2H10.3v2a1 1 0 0 1-2 0v-2h-.6a1.2 1.2 0 0 1-1.2-1.2V8.5l.8-1.3Zm1-3.2 1.2 1.8h5L15.7 4l.8.5-1 1.5c1.1.4 1.9 1.4 2.1 2.6H6.4c.2-1.2 1-2.2 2.1-2.6l-1-1.5.8-.5Zm1.3 3.4a.8.8 0 1 0 0-1.6.8.8 0 0 0 0 1.6Zm4.8 0a.8.8 0 1 0 0-1.6.8.8 0 0 0 0 1.6Z" fill="currentColor"/></svg>
                            </span>
                            <strong>Download for Android</strong>
                        </a>
                    @else
                        <span class="store-btn primary disabled" aria-disabled="true">
                            <span class="store-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24"><path d="M7.3 7.2h9.4l.8 1.3v7.9a1.2 1.2 0 0 1-1.2 1.2h-.6v2a1 1 0 0 1-2 0v-2H10.3v2a1 1 0 0 1-2 0v-2h-.6a1.2 1.2 0 0 1-1.2-1.2V8.5l.8-1.3Zm1-3.2 1.2 1.8h5L15.7 4l.8.5-1 1.5c1.1.4 1.9 1.4 2.1 2.6H6.4c.2-1.2 1-2.2 2.1-2.6l-1-1.5.8-.5Zm1.3 3.4a.8.8 0 1 0 0-1.6.8.8 0 0 0 0 1.6Zm4.8 0a.8.8 0 1 0 0-1.6.8.8 0 0 0 0 1.6Z" fill="currentColor"/></svg>
                            </span>
                            <strong>Android — Coming Soon</strong>
                        </span>
                    @endif

                    @if ($iosUrl)
                        <a class="store-btn secondary" href="{{ $iosUrl }}" target="_blank" rel="noopener noreferrer">
                            <span class="apple-mark" aria-hidden="true">●</span>
                            <strong>Download for iOS</strong>
                        </a>
                    @else
                        <span class="store-btn secondary disabled" aria-disabled="true">
                            <span class="apple-mark" aria-hidden="true">●</span>
                            <strong>iOS — Coming Soon</strong>
                        </span>
                    @endif
                </div>

                <div class="trust-row hero-enter" aria-label="Product benefits">
                    <div><span class="round-icon">↗</span><p>Live price<br>updates</p></div>
                    <div><span class="round-icon">◇</span><p>Industrial<br>accuracy</p></div>
                    <div><span class="round-icon">○</span><p>Trusted by<br>professionals</p></div>
                    <div><span class="round-icon">▢</span><p>Secure &amp;<br>reliable</p></div>
                </div>
            </div>

            <div class="hero-visual hero-enter" data-parallax>
                <div class="hero-dots" aria-hidden="true"></div>
                <div class="orange-swoop" aria-hidden="true"></div>
                <img class="hero-phone" src="{{ asset('images/portfolio/hero-phone.webp') }}" alt="Maik Cat metal prices mobile app" width="941" height="1672" fetchpriority="high">
                <img class="metal metal-platinum" src="{{ asset('images/portfolio/platinum-bar.webp') }}" alt="Platinum 999.5 one ounce bar" width="1254" height="1254" fetchpriority="high">
                <img class="metal metal-palladium" src="{{ asset('images/portfolio/palladium-bar.webp') }}" alt="Palladium 999.5 one ounce bar" width="1254" height="1254" fetchpriority="high">
                <img class="metal metal-rhodium" src="{{ asset('images/portfolio/rhodium-bar.webp') }}" alt="Rhodium 999.0 one ounce bar" width="1254" height="1254" fetchpriority="high">
            </div>
        </div>
        <img class="hero-converter" src="{{ asset('images/portfolio/catalytic-converter.webp') }}" alt="" width="1536" height="1024" aria-hidden="true">
    </div>

    <div class="stats shell reveal" aria-label="Maik Cat product highlights">
        <div class="stat"><span class="stat-icon">◎</span><strong>10K+</strong><p>Professionals<br>trust Maik Cat</p></div>
        <div class="stat"><span class="stat-icon">↗</span><strong>99.9%</strong><p>Accurate data<br>you can rely on</p></div>
        <div class="stat"><span class="stat-icon">◷</span><strong>6h</strong><p>Prices updated<br>every 6 hours</p></div>
        <div class="stat"><span class="stat-icon">◇</span><strong>100%</strong><p>Built for industrial<br>environments</p></div>
    </div>

    <div class="features shell reveal">
        <div class="features-intro">
            <p class="section-kicker">EVERY TOOL YOU NEED <span></span></p>
            <h2>Powerful features.<br>Built for the <em>industry.</em></h2>
            <p>From live market data to advanced calculations, Maik Cat gives you the edge to make confident, profitable decisions.</p>

            <div class="feature-note">
                <span class="feature-note-icon blue">◉</span>
                <div><strong>Track metal prices</strong><p>Monitor platinum, palladium, and rhodium with live updates and 7-day price trends.</p></div>
            </div>
            <div class="feature-note">
                <span class="feature-note-icon orange">↗</span>
                <div><strong>Calculate values</strong><p>Estimate value by weight, humidity, and metal content with precision.</p></div>
            </div>
        </div>

        <div class="calculator-stage">
            <div class="calculator-glow" aria-hidden="true"></div>
            <img class="calculator-phone" src="{{ asset('images/portfolio/calculator-phone.webp') }}" alt="Maik Cat catalytic converter calculator screen" width="941" height="1672" loading="lazy">
        </div>

        <div class="feature-actions">
            <article class="action-card">
                <div class="action-head"><span class="action-icon blue">⌕</span><div><strong>Search quickly</strong><p>Find parts by code and brand in seconds.</p></div></div>
                <div class="product-mini"><img src="{{ asset('images/portfolio/catalytic-converter.webp') }}" alt="Catalytic converter search result" loading="lazy"><div><b>8670409</b><span>Volvo</span></div></div>
            </article>
            <article class="action-card">
                <div class="action-head"><span class="action-icon orange">▤</span><div><strong>Browse the catalog</strong><p>Explore products and view prices for the most searched parts.</p></div></div>
                <div class="product-mini"><img class="flipped" src="{{ asset('images/portfolio/catalytic-converter.webp') }}" alt="Catalytic converter catalog result" loading="lazy"><div><b>9146685</b><span>Volvo</span></div></div>
            </article>
        </div>
    </div>

    <div class="industry-band">
        <div class="shell industry-grid reveal">
            <h2>Built for recyclers, traders,<br>and catalytic converter <em>professionals.</em></h2>
            <div class="industry-point"><span>♙</span><div><strong>Industrial grade</strong><p>Designed for real-world conditions and daily industrial use.</p></div></div>
            <div class="industry-point"><span>✦</span><div><strong>Data you can trust</strong><p>Reliable sources and consistent updates for confident decisions.</p></div></div>
            <div class="industry-point"><span>◇</span><div><strong>Privacy first</strong><p>Your data stays secure — we never share your information.</p></div></div>
            <img class="footer-converter" src="{{ asset('images/portfolio/catalytic-converter.webp') }}" alt="" width="1536" height="1024" aria-hidden="true" loading="lazy">
        </div>
    </div>
</main>

<footer class="site-footer">
    <div class="shell footer-grid">
        <div class="footer-brand">
            <img src="{{ asset('images/portfolio/logo.webp') }}" alt="Maik Cat" width="150" height="75">
            <p>Precision tools for catalytic converter professionals.</p>
        </div>
        <nav class="footer-links" aria-label="Legal">
            <a href="{{ route('legal.privacy.en') }}">Privacy Policy</a>
            <a href="{{ route('legal.terms.en') }}">Terms of Use</a>
        </nav>
        <p class="footer-copy">© {{ date('Y') }} Maik Cat. All rights reserved.</p>
    </div>
</footer>
</body>
</html>
