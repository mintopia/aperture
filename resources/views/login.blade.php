@extends('layouts.captive')

@section('title', 'Login')

@section('content')
    <div class="text-center" data-testid="login-page">
        <div class="mx-auto mb-3 flex h-9 w-9 items-center justify-center rounded-lg bg-[var(--color-primary)]">
            <span class="font-heading text-sm font-bold text-white">A</span>
        </div>

        <h1 class="font-heading text-xl font-bold sm:text-2xl">Welcome</h1>
        <p class="mt-1 text-sm text-[var(--color-text-secondary)]">Login to access the network</p>

        @if(session('errorMessage'))
            <div class="mt-4 rounded-lg bg-[var(--color-danger)]/10 px-4 py-2 text-sm text-[var(--color-danger)]" data-testid="login-error">
                {{ session('errorMessage') }}
            </div>
        @endif

        <div class="mt-6 space-y-3">
            @foreach ($providers as $provider)
                <a href="{{ route('login.provider', ['provider' => $provider->code]) }}"
                   class="block w-full rounded-lg bg-[var(--color-primary)] px-4 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-[var(--color-primary-hover)]"
                   data-testid="login-provider-{{ $provider->code }}">
                    Login with {{ $provider->name }}
                </a>
            @endforeach
        </div>
    </div>
@endsection
