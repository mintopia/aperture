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
        - Administration
        @if (isset($title) && is_array($title))
            - {{ implode(' - ', $title) }}
        @elseif (isset($title))
            - {{ $title }}
        @endif
    </title>
</head>
<body >
<script src="/dist/js/demo-theme.min.js?1684106062"></script>
<div class="page">
    <!-- Navbar -->
    <header class="navbar navbar-expand-md d-print-none" >
        <div class="container-xl">
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbar-menu" aria-controls="navbar-menu" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <h1 class="navbar-brand navbar-brand-autodark d-none-navbar-horizontal pe-0 pe-md-3">
                <i class="icon ti ti-aperture"></i>
                <a href="{{ route('admin.home') }}">
                    Aperture
                </a>
            </h1>
            <div class="navbar-nav flex-row order-md-last">
                <div class="nav-item dropdown">
                    <a href="#" class="nav-link d-flex lh-1 text-reset p-0" data-bs-toggle="dropdown" aria-label="Open user menu">
                        <span class="avatar avatar-sm" style="background-image: url('https://www.gravatar.com/avatar/{{ md5(Auth::user()->email) }}?d=mp')"></span>
                        <div class="d-none d-xl-block ps-2">
                            <div>{{ Auth::user()->nickname }}</div>
                        </div>
                    </a>
                    <div class="dropdown-menu dropdown-menu-end dropdown-menu-arrow">
                        <a href="{{ route('logout') }}" class="dropdown-item">Logout</a>
                    </div>
                </div>
            </div>
        </div>
    </header>
    <header class="navbar-expand-md">
        <div class="collapse navbar-collapse" id="navbar-menu">
            <div class="navbar">
                <div class="container-xl">
                    <ul class="navbar-nav">
                        <li class="nav-item">
                            <a class="nav-link" href="{{ route('admin.home') }}" >
                                <span class="nav-link-icon d-md-none d-lg-inline-block">
                                    <i class="icon ti ti-home"></i>
                                </span>
                                <span class="nav-link-title">
                                  Home
                                </span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="{{ route('admin.users.index') }}" >
                                <span class="nav-link-icon d-md-none d-lg-inline-block">
                                    <i class="icon ti ti-user"></i>
                                </span>
                                <span class="nav-link-title">
                                  Users
                                </span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="{{ route('admin.ips.index') }}" >
                                <span class="nav-link-icon d-md-none d-lg-inline-block">
                                    <i class="icon ti ti-network"></i>
                                </span>
                                <span class="nav-link-title">
                                  IP Addresses
                                </span>
                            </a>
                        </li>
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle" href="#navbar-third" data-bs-toggle="dropdown" data-bs-auto-close="outside" role="button" aria-expanded="true">
                              <span class="nav-link-icon d-md-none d-lg-inline-block">
                                <i class="icon ti ti-settings"></i>
                              </span>
                                <span class="nav-link-title">
                                Config
                              </span>
                            </a>
                            <div class="dropdown-menu" data-bs-popper="static">
                                <a class="dropdown-item" href="./#">
                                    Settings
                                </a>
                                <a class="dropdown-item" href="./#">
                                    Authentication
                                </a>
                                <a class="dropdown-item" href="./#">
                                    Firewalls
                                </a>
                            </div>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </header>
    <div class="page-wrapper">
        @if(session()->has('successMessage'))
            <div class="page-body mb-0">
                <div class="container-xl">
                    <div class="alert alert-success container-xl mt-1 mb-0">
                        {{ session()->get('successMessage') }}
                    </div>
                </div>
            </div>
        @endif
            @if(session()->has('errorMessage'))
                <div class="page-body mb-0">
                    <div class="container-xl">
                        <div class="alert alert-danger">
                        {{ session()->get('errorMessage') }}
                    </div>
                    </div>
                </div>
        @endif
        <div class="page-header d-print-none {{ session()->has('successMessage') || session()->has('errorMessage') ? 'mt-0' : '' }}">
            @yield('header')
        </div>
        <div class="page-body">
            <div class="container-xl">
                @yield('content')
            </div>
        </div>
    </div>
</div>
<script src="/dist/js/tabler.min.js?1684106062" defer></script>
<script src="/dist/js/demo.min.js?1684106062" defer></script>
@yield('footer')
</body>
</html>
