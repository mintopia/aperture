<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"/>
    <meta http-equiv="X-UA-Compatible" content="ie=edge"/>
    <!-- CSS files -->
    <link href="/dist/css/tabler.min.css?1684106062" rel="stylesheet"/>
    <link href="/dist/css/tabler-flags.min.css?1684106062" rel="stylesheet"/>
    <link href="/dist/css/tabler-payments.min.css?1684106062" rel="stylesheet"/>
    <link href="/dist/css/tabler-vendors.min.css?1684106062" rel="stylesheet"/>
    <link href="/dist/css/tabler-icons.min.css?1684106062" rel="stylesheet"/>
    <link href="/dist/css/demo.min.css?1684106062" rel="stylesheet"/>

    <link rel="stylesheet"
          href="//cdnjs.cloudflare.com/ajax/libs/highlight.js/11.7.0/styles/github-dark.min.css">
    <script src="//cdnjs.cloudflare.com/ajax/libs/highlight.js/11.7.0/highlight.min.js"></script>

    <style>
        @import url('https://rsms.me/inter/inter.css');
        :root {
            --tblr-font-sans-serif: 'Inter Var', -apple-system, BlinkMacSystemFont, San Francisco, Segoe UI, Roboto, Helvetica Neue, sans-serif;
        }
        body {
            font-feature-settings: "cv03", "cv04", "cv11";
        }
    </style>
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>
        {{ config('app.name', 'Aperture') }}
        @if (isset($title) && is_array($title))
            - {{ implode(' - ', $title) }}
        @elseif (isset($title))
            - {{ $title }}
        @endif
    </title>
</head>
<body class="d-flex flex-column">
<script src="/dist/js/demo-theme.min.js?1684106062"></script>    <div class="page page-center">
    <div class="container container-tight py-4">
        <div class="text-center mb-4">
            <h1>
                <a href="{{ route('home') }}" class="navbar-brand navbar-brand-autodark">
                    <i class="icon ti ti-aperture"></i>
                    Aperture
                </a>
            </h1>
        </div>
        <div class="card card-md">
            @yield('content')
        </div>
        <div class="text-center text-secondary mt-3">
            <p>If you have any issues, please speak to Mintopia.</p>
            <p class="small">Your IP Address is {{ \Request::getClientIp(true) }}</p>
        </div>
    </div>
</div>
<script src="/dist/js/tabler.min.js?1684106062" defer></script>
<script src="/dist/js/demo.min.js?1684106062" defer></script>
@yield('footer')
</body>
</html>
