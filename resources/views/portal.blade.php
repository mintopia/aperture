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
            @if($hasSiteLogo ?? false)
                <img src="{{ $siteLogoUrl }}" alt="{{ $siteTitle }}" class="mx-auto mb-3 h-9 w-9 rounded-lg" data-testid="portal-logo-image">
            @else
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" class="mx-auto mb-3 h-9 w-9 text-[var(--color-primary)]" aria-hidden="true" data-testid="portal-logo-icon">
                    <circle cx="12" cy="12" r="10" /><line x1="14.31" y1="8" x2="20.05" y2="17.94" /><line x1="9.69" y1="8" x2="21.17" y2="8" /><line x1="7.38" y1="12" x2="13.12" y2="2.06" /><line x1="9.69" y1="16" x2="3.95" y2="6.06" /><line x1="14.31" y1="16" x2="2.83" y2="16" /><line x1="16.62" y1="12" x2="10.88" y2="21.94" />
                </svg>
            @endif

            <h1 class="font-heading text-xl font-bold sm:text-2xl">
                Hi {{ Auth::user()->nickname }}!
            </h1>

            @if (Auth::user()->internet_blocked)
                <div class="mt-4 rounded-lg bg-[var(--color-danger)]/10 px-4 py-3 text-sm text-[var(--color-danger)]" data-testid="portal-blocked">
                    <p class="font-semibold">Your access has been restricted</p>
                    <p class="mt-1 text-xs">Please speak to an event organizer for assistance.</p>
                </div>
            @else
                <div id="status-waiting" class="{{ $ip?->internet_enabled ? 'hidden' : '' }} mt-4" data-testid="portal-status-waiting">
                    <p class="text-sm text-[var(--color-text-secondary)]">Setting up your internet access…</p>
                    <div class="mt-2 flex justify-center">
                        <svg class="h-5 w-5 animate-spin text-[var(--color-primary)]" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                        </svg>
                    </div>
                </div>

                <div id="status-ok" class="{{ $ip?->internet_enabled ? '' : 'hidden' }} mt-4" data-testid="portal-status-ok">
                    <div class="rounded-lg bg-[var(--color-success)]/10 px-4 py-3 text-sm font-semibold text-[var(--color-success)]">
                        ✓ You're connected! Enjoy the event.
                    </div>
                    <a href="{{ route('portal.dashboard') }}" class="mt-3 inline-block text-sm text-[var(--color-primary)] underline" data-testid="portal-dashboard-link">
                        View your dashboard →
                    </a>
                </div>
            @endif

            <p class="mt-6 text-xs text-[var(--color-text-muted)]" data-testid="portal-ip">Your IP: {{ $ip?->address }}</p>
        </div>
    </div>
@endsection

@section('scripts')
    <script>
    document.addEventListener("DOMContentLoaded", function() {
        var statusOK = document.getElementById('status-ok');
        var statusWaiting = document.getElementById('status-waiting');
        var ipv6Endpoint = @json($ipv6DetectionEndpoint ?? '');

        function uuid() {
            if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
                return crypto.randomUUID();
            }
            return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
                var r = Math.random() * 16 | 0;
                return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
            });
        }

        function attemptIpv6Detection(remaining, callback) {
            if (remaining <= 0 || !ipv6Endpoint) {
                callback();
                return;
            }

            var endpoint = ipv6Endpoint.replace('{uuid}', uuid());
            fetch(endpoint)
                .then(function(response) { return response.ok ? response.text() : null; })
                .then(function(token) {
                    if (token && token.trim().length > 0) {
                        return fetch("/ipv6", {
                            method: "POST",
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ 'token': token.trim() }),
                        });
                    }
                })
                .then(function() {
                    setTimeout(function() {
                        attemptIpv6Detection(remaining - 1, callback);
                    }, 1000);
                })
                .catch(function() {
                    setTimeout(function() {
                        attemptIpv6Detection(remaining - 1, callback);
                    }, 1000);
                });
        }

        function redirectToDashboard() {
            window.location.href = @json(route('portal.dashboard'));
        }

        var checks = 0;
        var internetEnabled = {{ $ip?->internet_enabled ? 'true' : 'false' }};

        function onInternetEnabled() {
            if (statusWaiting) statusWaiting.classList.add('hidden');
            if (statusOK) statusOK.classList.remove('hidden');
            checkDns();
            attemptIpv6Detection(3, redirectToDashboard);
        }

        function checkStatus() {
            checks++;
            var timeout = 2000;
            if (checks > 20) {
                timeout = 30000;
            } else if (checks > 4) {
                timeout = 10000;
            }

            fetch('/status')
                .then(function(response) { return response.json(); })
                .then(function(data) {
                    if (data.internetEnabled === true) {
                        if (!internetEnabled) {
                            internetEnabled = true;
                            onInternetEnabled();
                        }
                    } else {
                        setTimeout(checkStatus, timeout);
                    }
                })
                .catch(function(error) {
                    console.error('Error fetching status:', error);
                    setTimeout(checkStatus, timeout);
                });
        }

        function checkDns() {
            @if($dnsCheckUrl)
            var dnsUrl = @json($dnsCheckUrl).replace('{uuid}', uuid());
            fetch(dnsUrl)
                .then(function(response) { return response.ok ? response.json() : null; })
                .then(function(data) {
                    if (data && data.server !== 'event') {
                        document.getElementById('dns-warning').classList.remove('hidden');
                    }
                })
                .catch(function() {});
            @endif
        }

        if (internetEnabled) {
            onInternetEnabled();
        }

        @if(!$ip?->internet_enabled && !Auth::user()->internet_blocked)
        setTimeout(checkStatus, 2000);
        @endif
    });
    </script>
@endsection
