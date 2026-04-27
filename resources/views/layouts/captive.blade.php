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
        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
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
