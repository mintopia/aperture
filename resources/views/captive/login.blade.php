@extends('layouts.captive')

@section('title', 'Connect')

@section('content')
    <div class="text-center" data-testid="captive-login">
        @if (!empty($serviceUnavailable))
            <div
                data-testid="captive-config-error"
                class="rounded-xl border border-[var(--color-danger)]/40 bg-[var(--color-danger)]/10 p-6 text-left"
            >
                <h1 class="font-heading text-xl font-bold text-[var(--color-text)]">Portal authentication unavailable</h1>
                <p class="mt-2 text-sm text-[var(--color-text-secondary)]">
                    Portal authentication is currently unavailable.
                    Please contact your administrator to configure the OAuth2 service.
                </p>
                <button
                    onclick="window.location.reload()"
                    class="mt-4 rounded-lg bg-[var(--color-primary)] px-4 py-2 text-xs font-semibold text-white transition-colors hover:bg-[var(--color-primary-hover)]"
                >
                    Try Again
                </button>
            </div>
        @else
        <!-- Logo icon -->
        <div class="mx-auto mb-3 flex h-9 w-9 items-center justify-center rounded-lg bg-[var(--color-primary)]">
            <span class="font-heading text-sm font-bold text-white">A</span>
        </div>

        <h1 class="font-heading text-xl font-bold sm:text-2xl">Connect to Network</h1>
        <p class="mt-1 text-sm text-[var(--color-text-secondary)]">Scan the QR code or enter the code below</p>

        <!-- QR Code with glow -->
        <div class="mx-auto my-6 w-40 h-40 sm:w-[160px] sm:h-[160px] rounded-[14px] border-2 border-[var(--color-primary)] p-1 shadow-[0_0_24px_var(--color-glow)]" data-testid="captive-qr" id="qr-container">
            <div class="flex h-full w-full items-center justify-center rounded-[10px] bg-white">
                {!! $qrCode !!}
            </div>
        </div>

        <!-- Verification URL -->
        <p class="text-xs text-[var(--color-text-muted)]">
            Visit <a href="{{ $verificationUri }}" target="_blank" class="text-[var(--color-primary)] underline">{{ $verificationUri }}</a>
        </p>

        <!-- User Code -->
        <div class="my-5">
            <p class="text-xs font-bold uppercase tracking-wider text-[var(--color-text-muted)]">Enter this code</p>
            <div class="mt-1 font-mono text-3xl font-bold tracking-[0.2em] text-[var(--color-primary)] sm:text-4xl" data-testid="captive-user-code" id="user-code">
                {{ $userCode }}
            </div>
        </div>

        <!-- Instructions -->
        <div class="mt-5 rounded-lg bg-[var(--color-surface-alt)] p-4 text-left" data-testid="captive-instructions">
            <p class="text-xs font-semibold text-[var(--color-text-secondary)]">How to connect:</p>
            <ol class="mt-2 list-inside list-decimal space-y-1 text-xs text-[var(--color-text-muted)]">
                <li>Scan the QR code or visit the URL above on a device with internet</li>
                <li>Enter the code shown above when prompted</li>
                <li>Authorize the connection and you'll be connected automatically</li>
            </ol>
        </div>

        <!-- Status -->
        <div id="status" class="mt-5">
            <div id="status-pending" data-testid="captive-status-pending" class="flex items-center justify-center gap-2 text-sm text-[var(--color-text-secondary)]">
                <svg class="h-4 w-4 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                </svg>
                <span>Waiting for authorization…</span>
            </div>
            <div id="status-complete" data-testid="captive-status-complete" class="hidden text-sm font-semibold text-[var(--color-success)]">
                ✓ Authorized! Redirecting…
            </div>
            <div id="status-expired" data-testid="captive-status-expired" class="hidden text-sm text-[var(--color-danger)]">
                <p class="font-semibold">Your login code has expired</p>
                <p class="mt-1 text-xs text-[var(--color-text-secondary)]">Please get a new code to continue.</p>
                <button onclick="window.location.reload()" class="mt-3 rounded-lg bg-[var(--color-primary)] px-4 py-2 text-xs font-semibold text-white transition-colors hover:bg-[var(--color-primary-hover)]" data-testid="captive-refresh">
                    Get New Code
                </button>
            </div>
            <div id="status-error" data-testid="captive-status-error" class="hidden text-sm text-[var(--color-danger)]">
                <p>An error occurred.</p>
                <button onclick="window.location.reload()" class="mt-2 rounded-lg bg-[var(--color-primary)] px-4 py-2 text-xs font-semibold text-white transition-colors hover:bg-[var(--color-primary-hover)]">
                    Try Again
                </button>
            </div>
        </div>
        @endif
    </div>
@endsection

@section('scripts')
    @if (empty($serviceUnavailable))
    <script>
        (function() {
            var deviceCode = @json($deviceCode);
            var interval = Math.max({{ $interval }} * 1000, 3000);
            var expiresIn = Math.max({{ $expiresIn }}, 60);
            var expiresAt = Date.now() + (expiresIn * 1000);
            var polling = true;

            function showStatus(id) {
                ['pending', 'complete', 'expired', 'error'].forEach(function(s) {
                    document.getElementById('status-' + s).classList.add('hidden');
                });
                document.getElementById('status-' + id).classList.remove('hidden');
            }

            function poll() {
                if (!polling) return;

                if (Date.now() > expiresAt) {
                    showStatus('expired');
                    polling = false;
                    return;
                }

                fetch('/captive/poll/' + encodeURIComponent(deviceCode), {
                    headers: { 'Accept': 'application/json' }
                })
                .then(function(response) { return response.json(); })
                .then(function(data) {
                    if (data.status === 'complete') {
                        showStatus('complete');
                        polling = false;
                        window.location.href = data.redirect || '/';
                    } else if (data.status === 'expired') {
                        showStatus('expired');
                        polling = false;
                    } else {
                        setTimeout(poll, interval);
                    }
                })
                .catch(function() {
                    setTimeout(poll, interval * 2);
                });
            }

            setTimeout(poll, interval);
        })();
    </script>
    @endif
@endsection
