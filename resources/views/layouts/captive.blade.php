<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="dispatch" data-mode="{{ $themeMode ?? 'dark' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $siteTitle }} - @yield('title', 'Connect')</title>
    @if($hasSiteLogo ?? false)
        <link rel="icon" type="image/png" sizes="32x32" href="{{ $faviconUrls['32'] }}">
        <link rel="icon" type="image/png" sizes="16x16" href="{{ $faviconUrls['16'] }}">
        <link rel="apple-touch-icon" sizes="180x180" href="{{ $faviconUrls['180'] }}">
    @else
        <link rel="icon" type="image/svg+xml" href="{{ route('favicon') }}">
    @endif
    @vite(['resources/css/app.css'])
    @php
        $hue = $accentHue ?? 55;
        $presets = [350 => [72, 0.19], 20 => [73, 0.17], 55 => [76, 0.16], 135 => [80, 0.18], 185 => [76, 0.12], 230 => [72, 0.14], 295 => [70, 0.18], 325 => [70, 0.2]];
        $p = $presets[$hue] ?? [72, 0.19];
        $l = $p[0]; $c = $p[1];
    @endphp
    <style>
        :root {
            --color-primary: oklch({{ $l }}% {{ $c }} {{ $hue }});
            --color-primary-hover: oklch({{ $l - 7 }}% {{ $c + 0.03 }} {{ $hue }});
            --color-accent: oklch({{ $l }}% {{ $c }} {{ $hue }});
            --color-accent-hover: oklch({{ $l - 7 }}% {{ $c + 0.03 }} {{ $hue }});
            --color-accent-dim: oklch({{ $l }}% {{ $c }} {{ $hue }} / 0.14);
            --color-accent-text: oklch(98% 0.01 {{ $hue }});
            --color-glow: oklch({{ $l }}% {{ $c }} {{ $hue }} / 0.25);
        }
        [data-theme='dispatch'][data-mode='light'] {
            --color-primary: oklch({{ max($l - 21, 40) }}% {{ $c + 0.02 }} {{ $hue }});
            --color-primary-hover: oklch({{ max($l - 21, 40) - 7 }}% {{ $c + 0.04 }} {{ $hue }});
            --color-accent: oklch({{ max($l - 21, 40) }}% {{ $c + 0.02 }} {{ $hue }});
            --color-accent-hover: oklch({{ max($l - 21, 40) - 7 }}% {{ $c + 0.04 }} {{ $hue }});
            --color-accent-dim: oklch({{ max($l - 21, 40) }}% {{ $c + 0.02 }} {{ $hue }} / 0.1);
            --color-accent-text: oklch(99% 0.005 {{ $hue }});
            --color-glow: oklch({{ max($l - 21, 40) }}% {{ $c + 0.02 }} {{ $hue }} / 0.15);
        }
        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow-x: hidden;
        }

        /* Ambient gradient mesh — two drifting radial spots tied to accent hue */
        body::before {
            content: '';
            position: fixed;
            inset: -50%;
            width: 200%;
            height: 200%;
            z-index: 0;
            pointer-events: none;
            background:
                radial-gradient(ellipse 600px 400px at 30% 20%, oklch({{ $l }}% {{ $c * 0.5 }} {{ $hue }} / 0.06), transparent),
                radial-gradient(ellipse 500px 500px at 70% 80%, oklch({{ $l }}% {{ $c * 0.4 }} {{ ($hue + 40) % 360 }} / 0.04), transparent);
            animation: captive-mesh-drift 20s ease-in-out infinite alternate;
        }

        @keyframes captive-mesh-drift {
            0% { transform: translate(0, 0) rotate(0deg); }
            100% { transform: translate(-5%, 3%) rotate(8deg); }
        }

        body > * { position: relative; z-index: 1; }

        /* Content entrance stagger */
        .captive-reveal {
            animation: captive-fade-up 500ms cubic-bezier(0.16, 1, 0.3, 1) backwards;
        }
        .captive-reveal:nth-child(1) { animation-delay: 0ms; }
        .captive-reveal:nth-child(2) { animation-delay: 60ms; }
        .captive-reveal:nth-child(3) { animation-delay: 120ms; }
        .captive-reveal:nth-child(4) { animation-delay: 180ms; }
        .captive-reveal:nth-child(5) { animation-delay: 240ms; }
        .captive-reveal:nth-child(6) { animation-delay: 300ms; }
        .captive-reveal:nth-child(7) { animation-delay: 360ms; }
        .captive-reveal:nth-child(8) { animation-delay: 420ms; }

        @keyframes captive-fade-up {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
        }

        .qr-glow {
            box-shadow: 0 0 32px var(--color-glow), 0 0 64px oklch(from var(--color-glow) l c h / 0.06);
            animation: captive-glow-breathe 3s ease-in-out infinite;
        }
        @keyframes captive-glow-breathe {
            0%, 100% { box-shadow: 0 0 32px var(--color-glow), 0 0 64px oklch(from var(--color-glow) l c h / 0.06); }
            50% { box-shadow: 0 0 48px var(--color-glow), 0 0 80px oklch(from var(--color-glow) l c h / 0.12); }
        }

        .success-burst {
            animation: captive-success-burst 400ms cubic-bezier(0.16, 1, 0.3, 1);
        }
        @keyframes captive-success-burst {
            0% { transform: scale(0.8); opacity: 0; }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); opacity: 1; }
        }

        .success-checkmark path {
            stroke-dasharray: 1;
            stroke-dashoffset: 1;
            animation: captive-checkmark-draw 500ms ease-out 200ms forwards;
        }
        @keyframes captive-checkmark-draw {
            to { stroke-dashoffset: 0; }
        }

        @media (prefers-reduced-motion: reduce) {
            body::before { animation: none; }
            .qr-glow { animation: none; }
            .captive-reveal { animation: none; }
            .success-burst { animation: none; opacity: 1; }
            .success-checkmark path { animation: none; stroke-dashoffset: 0; }
        }
    </style>
</head>
<body class="bg-[var(--color-bg)] text-[var(--color-text)]">
    <div class="w-full max-w-md mx-auto px-4 py-8">
        @yield('content')
    </div>
    @yield('scripts')
</body>
</html>
