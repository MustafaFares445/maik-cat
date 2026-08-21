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

        *, *::before, *::after { box-sizing: border-box; }

        html { text-size-adjust: 100%; }

        body {
            margin: 0;
            min-width: 0;
            background: var(--background);
            color: var(--text);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Tahoma, Arial, sans-serif;
            font-size: clamp(.95rem, .25vw + .9rem, 1rem);
            line-height: 1.75;
        }

        a {
            color: var(--accent);
            overflow-wrap: anywhere;
        }

        .shell {
            width: min(960px, calc(100% - clamp(20px, 5vw, 32px)));
            min-width: 0;
            margin: 0 auto;
        }

        .topbar {
            background: var(--surface);
            border-bottom: 1px solid var(--border);
        }

        .topbar-inner {
            min-height: 68px;
            display: flex;
            min-width: 0;
            align-items: center;
            justify-content: space-between;
            gap: clamp(10px, 3vw, 20px);
        }

        .brand {
            min-width: 0;
            color: var(--text);
            font-size: clamp(1rem, 2.5vw, 1.15rem);
            font-weight: 800;
            text-decoration: none;
            letter-spacing: .02em;
            overflow-wrap: anywhere;
        }

        .language-link {
            flex: 0 1 auto;
            max-width: 50%;
            border: 1px solid var(--border);
            border-radius: 999px;
            padding: 7px 14px;
            text-align: center;
            text-decoration: none;
            font-weight: 700;
            line-height: 1.3;
            background: var(--surface);
            overflow-wrap: anywhere;
        }

        main { padding: clamp(22px, 4vw, 40px) 0 clamp(40px, 5vw, 56px); }

        .document {
            min-width: 0;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 18px;
            padding: clamp(18px, 5vw, 56px);
            box-shadow: 0 12px 36px rgba(28, 25, 23, .06);
            overflow-wrap: anywhere;
        }

        .eyebrow {
            display: inline-block;
            max-width: 100%;
            margin-bottom: 12px;
            border-radius: 999px;
            padding: 5px 11px;
            background: var(--accent-soft);
            color: var(--accent);
            font-size: .85rem;
            font-weight: 800;
            overflow-wrap: anywhere;
        }

        h1 {
            margin: 0;
            font-size: clamp(1.9rem, 7vw, 3.2rem);
            line-height: 1.15;
            text-wrap: balance;
        }

        .updated {
            margin: 14px 0 32px;
            color: var(--muted);
        }

        h2 {
            margin: clamp(30px, 5vw, 38px) 0 12px;
            font-size: clamp(1.2rem, 3vw, 1.35rem);
            line-height: 1.35;
            text-wrap: balance;
        }

        h3 {
            margin: 24px 0 8px;
            overflow-wrap: anywhere;
        }

        p { margin: 0 0 14px; }
        ul, ol { margin: 8px 0 18px; padding-inline-start: clamp(20px, 5vw, 24px); }
        li + li { margin-top: 7px; }

        .notice {
            margin: 26px 0;
            border-inline-start: 4px solid var(--accent);
            background: var(--accent-soft);
            border-radius: 10px;
            padding: clamp(14px, 4vw, 18px);
            overflow-wrap: anywhere;
        }

        .contact-card {
            min-width: 0;
            margin-top: 34px;
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: clamp(14px, 4vw, 18px);
            background: #fafaf9;
            overflow-wrap: anywhere;
        }

        footer {
            color: var(--muted);
            text-align: center;
            padding: 0 clamp(12px, 4vw, 16px) 34px;
            font-size: .92rem;
            overflow-wrap: anywhere;
        }

        @media (max-width: 480px) {
            .topbar-inner {
                min-height: 60px;
                align-items: center;
            }

            .language-link {
                max-width: 55%;
                padding: 6px 10px;
                font-size: .9rem;
            }

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
