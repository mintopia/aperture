<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="dispatch" data-mode="{{ $themeMode ?? 'dark' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="csp-nonce" content="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
    <title>{{ $siteTitle }}</title>
    @if($hasSiteLogo ?? false)
        <link rel="icon" type="image/png" sizes="32x32" href="{{ $faviconUrls['32'] }}">
        <link rel="icon" type="image/png" sizes="16x16" href="{{ $faviconUrls['16'] }}">
        <link rel="apple-touch-icon" sizes="180x180" href="{{ $faviconUrls['180'] }}">
    @else
        <link rel="icon" type="image/svg+xml" href="{{ route('favicon') }}">
    @endif
    <script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
        window.__reverb = @json(config('reverb.frontend'));
    </script>
    @routes(null, \Illuminate\Support\Facades\Vite::cspNonce())
    <script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
        (function () {
            var root = document.documentElement;
            var stored = null;
            try { stored = localStorage.getItem('reduceTransparency'); } catch (e) {}
            if (stored === '1' || (stored === null && window.matchMedia('(prefers-reduced-transparency: reduce)').matches)) {
                root.setAttribute('data-transparency', 'reduced');
            }
            if (navigator.userAgentData && navigator.userAgentData.brands.some(function (b) { return b.brand === 'Chromium'; })) {
                root.setAttribute('data-refraction', '');
            }
        })();
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @inertiaHead
    @if($customCss ?? null)<style nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">{!! $customCss !!}</style>@endif
</head>
<body class="bg-[var(--color-bg)] text-[var(--color-text)]">
    @inertia
</body>
</html>
