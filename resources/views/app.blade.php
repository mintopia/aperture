<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="dispatch" data-mode="{{ $themeMode ?? 'dark' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $siteTitle }}</title>
    @if($hasSiteLogo ?? false)
        <link rel="icon" type="image/png" sizes="32x32" href="{{ $faviconUrls['32'] }}">
        <link rel="icon" type="image/png" sizes="16x16" href="{{ $faviconUrls['16'] }}">
        <link rel="apple-touch-icon" sizes="180x180" href="{{ $faviconUrls['180'] }}">
    @else
        <link rel="icon" type="image/svg+xml" href="{{ route('favicon') }}">
    @endif
    <script>
        window.__reverb = @json(config('reverb.frontend'));
    </script>
    @routes
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @inertiaHead
</head>
<body class="bg-[var(--color-bg)] text-[var(--color-text)]">
    @inertia
</body>
</html>
