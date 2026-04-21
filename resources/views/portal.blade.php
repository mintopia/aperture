@extends('layouts.captive')

@section('title', 'Portal')

@section('content')
    <div data-testid="portal-page">
        <div id="dns-warning" class="mb-4 hidden rounded-lg border border-[var(--color-warning)]/30 bg-[var(--color-warning)]/10 p-4" data-testid="portal-dns-warning">
            <h3 class="text-sm font-semibold text-[var(--color-warning)]">DNS Not Configured Properly</h3>
            <p class="mt-1 text-xs text-[var(--color-text-secondary)]">
                You're using custom DNS servers. This means slower game downloads.
                Please update your network settings to use automatically assigned DNS servers.
            </p>
        </div>

        <div class="text-center">
            <div class="mx-auto mb-3 flex h-9 w-9 items-center justify-center rounded-lg bg-[var(--color-primary)]">
                <span class="font-heading text-sm font-bold text-white">A</span>
            </div>

            <h1 class="font-heading text-xl font-bold sm:text-2xl">
                Hi {{ Auth::user()->nickname }}!
            </h1>

            @if (Auth::user()->blocked)
                <div class="mt-4 rounded-lg bg-[var(--color-danger)]/10 px-4 py-3 text-sm text-[var(--color-danger)]" data-testid="portal-blocked">
                    <p class="font-semibold">Your access has been restricted</p>
                    <p class="mt-1 text-xs">Please speak to an event organizer for assistance.</p>
                </div>
            @else
                <div id="status-waiting" class="{{ $ip->allowed ? 'hidden' : '' }} mt-4" data-testid="portal-status-waiting">
                    <p class="text-sm text-[var(--color-text-secondary)]">Setting up your internet access…</p>
                    <div class="mt-2 flex justify-center">
                        <svg class="h-5 w-5 animate-spin text-[var(--color-primary)]" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                        </svg>
                    </div>
                </div>

                <div id="status-ok" class="{{ $ip->allowed ? '' : 'hidden' }} mt-4" data-testid="portal-status-ok">
                    <div class="rounded-lg bg-[var(--color-success)]/10 px-4 py-3 text-sm font-semibold text-[var(--color-success)]">
                        ✓ You're connected! Enjoy the event.
                    </div>
                    <a href="{{ route('portal.dashboard') }}" class="mt-3 inline-block text-sm text-[var(--color-primary)] underline" data-testid="portal-dashboard-link">
                        View your dashboard →
                    </a>
                </div>
            @endif

            <p class="mt-6 text-xs text-[var(--color-text-muted)]" data-testid="portal-ip">Your IP: {{ $ip->address }}</p>
        </div>
    </div>
@endsection

@section('scripts')
    <script>
    document.addEventListener("DOMContentLoaded", function() {
        const statusOK = document.getElementById('status-ok');
        const statusWaiting = document.getElementById('status-waiting');

        let checks = 0;

        function checkStatus() {
            checks++;
            let timeout = 2000;
            if (checks > 20) {
                timeout = 30000;
            } else if (checks > 4) {
                timeout = 10000;
            }

            fetch('/status')
                .then(response => response.json())
                .then(data => {
                    if (data.allowed === true) {
                        statusWaiting.classList.add('hidden');
                        statusOK.classList.remove('hidden');
                    } else {
                        setTimeout(checkStatus, timeout);
                    }
                })
                .catch(error => {
                    console.error('Error fetching status:', error);
                    setTimeout(checkStatus, timeout);
                });
        }

        fetch('https://' + crypto.randomUUID() + '.lancache.test.entropylan.party', {
            timeout: 2000,
        }).then(response => {
            if (response.ok) {
                return response.json();
            }
        }).then(data => {
            if (data && data.server !== 'event') {
                document.getElementById('dns-warning').classList.remove('hidden');
            }
        }).catch(() => {});

        @if($ipv6DetectionEndpoint)
        var ipv6Endpoint = '{{ $ipv6DetectionEndpoint }}'.replace('{random}', crypto.randomUUID());
        fetch(ipv6Endpoint)
            .then(response => response.ok ? response.text() : null)
            .then(token => {
                if (token && token.trim().length > 0) {
                    fetch("/ipv6", {
                        method: "POST",
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ 'token': token.trim() }),
                    }).then(() => setTimeout(checkStatus, 2000))
                      .catch(() => setTimeout(checkStatus, 2000));
                } else {
                    setTimeout(checkStatus, 2000);
                }
            })
            .catch(() => setTimeout(checkStatus, 2000));
        @else
        setTimeout(checkStatus, 2000);
        @endif
    });
    </script>
@endsection
