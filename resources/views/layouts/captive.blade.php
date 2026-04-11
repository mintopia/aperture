<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="{{ $themeName ?? 'cool-neon' }}" data-mode="{{ $themeMode ?? 'dark' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'Aperture') }} - @yield('title', 'Connect')</title>
    @vite(['resources/css/app.css'])
    <style>
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
