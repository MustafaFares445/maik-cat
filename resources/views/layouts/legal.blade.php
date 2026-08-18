<!DOCTYPE html>
<html lang="{{ $lang ?? 'en' }}" dir="{{ $dir ?? 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="index,follow">
    <meta name="description" content="{{ $description ?? $title }}">
    <title>{{ $title }} | {{ config('legal.brand_name', 'Maik Cat') }}</title>
    <style>
        :root {
            color-scheme: light;
            --background: #f5f5f4;
            --surface: #ffffff;
            --text: #1c1917;
            --muted: #57534e;
            --border: #e7e5e4;
            --accent: #b45309;
            --accent-soft: #fff7ed;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: var(--background);
            color: var(--text);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Tahoma, Arial, sans-serif;
            line-height: 1.75;
        }

        a { color: var(--accent); }

        .shell {
            width: min(960px, calc(100% - 32px));
            margin: 0 auto;
        }

        .topbar {
            background: var(--surface);
            border-bottom: 1px solid var(--border);
        }

        .topbar-inner {
            min-height: 68px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
        }

        .brand {
            color: var(--text);
            font-size: 1.15rem;
            font-weight: 800;
            text-decoration: none;
            letter-spacing: .02em;
        }

        .language-link {
            border: 1px solid var(--border);
            border-radius: 999px;
            padding: 7px 14px;
            text-decoration: none;
            font-weight: 700;
            background: var(--surface);
        }

        main { padding: 40px 0 56px; }

        .document {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 18px;
            padding: clamp(24px, 5vw, 56px);
            box-shadow: 0 12px 36px rgba(28, 25, 23, .06);
        }

        .eyebrow {
            display: inline-block;
            margin-bottom: 12px;
            border-radius: 999px;
            padding: 5px 11px;
            background: var(--accent-soft);
            color: var(--accent);
            font-size: .85rem;
            font-weight: 800;
        }

        h1 {
            margin: 0;
            font-size: clamp(2rem, 5vw, 3.2rem);
            line-height: 1.15;
        }

        .updated {
            margin: 14px 0 32px;
            color: var(--muted);
        }

        h2 {
            margin: 38px 0 12px;
            font-size: 1.35rem;
            line-height: 1.35;
        }

        h3 { margin: 24px 0 8px; }
        p { margin: 0 0 14px; }
        ul, ol { margin: 8px 0 18px; padding-inline-start: 24px; }
        li + li { margin-top: 7px; }

        .notice {
            margin: 26px 0;
            border-inline-start: 4px solid var(--accent);
            background: var(--accent-soft);
            border-radius: 10px;
            padding: 16px 18px;
        }

        .contact-card {
            margin-top: 34px;
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 18px;
            background: #fafaf9;
        }

        footer {
            color: var(--muted);
            text-align: center;
            padding: 0 16px 34px;
            font-size: .92rem;
        }

        @media (max-width: 640px) {
            .topbar-inner { min-height: 60px; }
            main { padding-top: 22px; }
            .document { border-radius: 14px; }
        }
    </style>
</head>
<body>
<header class="topbar">
    <div class="shell topbar-inner">
        <a class="brand" href="{{ url('/') }}">{{ config('legal.brand_name', 'Maik Cat') }}</a>

        @isset($alternateUrl)
            <a class="language-link" href="{{ $alternateUrl }}">{{ $alternateLabel ?? 'Language' }}</a>
        @endisset
    </div>
</header>

<main>
    <div class="shell">
        <article class="document">
            @yield('content')
        </article>
    </div>
</main>

<footer>
    &copy; {{ now()->year }} {{ config('legal.brand_name', 'Maik Cat') }}. {{ $rightsText ?? 'All rights reserved.' }}
</footer>
</body>
</html>
