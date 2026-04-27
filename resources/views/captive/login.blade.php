@extends('layouts.captive')

@section('title', 'Connect')

@section('content')
    <div class="text-center" data-testid="captive-login">
        @if (!empty($serviceUnavailable))
            <div
                data-testid="captive-config-error"
                class="rounded-md border border-[var(--color-danger)]/40 bg-[var(--color-danger)]/10 p-6 text-left"
            >
                <h1 class="font-heading text-xl font-bold tracking-tight text-[var(--color-text)]">Portal authentication unavailable</h1>
                <p class="mt-2 text-sm text-[var(--color-text-secondary)]">
                    Portal authentication is currently unavailable.
                    Please contact your administrator to configure the OAuth2 service.
                </p>
                <button
                    onclick="window.location.reload()"
                    class="mt-4 rounded-md bg-[var(--color-primary)] px-4 py-2 text-xs font-semibold text-white transition-colors hover:bg-[var(--color-primary-hover)]"
                >
                    Try Again
                </button>
            </div>
        @else
            {{-- Logo mark --}}
            @if($hasSiteLogo ?? false)
                <img src="{{ $siteLogoUrl }}" alt="{{ $siteTitle }}" class="mx-auto mb-4 h-10 w-10 rounded-[10px]" data-testid="captive-logo-image">
            @else
                <div class="mx-auto mb-4 inline-flex h-10 w-10 items-center justify-center rounded-[10px] bg-[var(--color-primary)]">
                    <span class="font-heading text-lg font-extrabold tracking-tight text-[var(--color-bg)]">A</span>
                </div>
            @endif

            <h1 class="font-heading text-2xl font-bold tracking-tight" style="font-variation-settings: 'opsz' 32;">Connect to Network</h1>
            <p class="mt-1.5 text-sm text-[var(--color-text-secondary)]">Scan the QR code or enter the code below</p>

            {{-- QR Code with accent border and glow --}}
            <div
                class="mx-auto my-7 h-[168px] w-[168px] rounded-[14px] border-2 border-[var(--color-primary)] p-1.5"
                style="box-shadow: 0 0 32px var(--color-glow), 0 0 64px oklch(from var(--color-glow) l c h / 0.06);"
                data-testid="captive-qr"
                id="qr-container"
            >
                <div class="flex h-full w-full items-center justify-center rounded-[10px] bg-white">
                    {!! $qrCode !!}
                </div>
            </div>

            {{-- Verification URL --}}
            <p class="text-xs text-[var(--color-text-muted)]">
                Visit <a href="{{ $verificationUri }}" target="_blank" class="text-[var(--color-primary)] underline decoration-[var(--color-primary)]/30 underline-offset-2 hover:decoration-[var(--color-primary)]">{{ $verificationUri }}</a>
            </p>

            {{-- User code --}}
            <div class="my-5">
                <p class="text-[10px] font-semibold uppercase tracking-[0.06em] text-[var(--color-text-muted)]">Enter this code</p>
                <div
                    class="mt-1 font-mono text-4xl font-bold tracking-[0.15em] text-[var(--color-primary)]"
                    data-testid="captive-user-code"
                    id="user-code"
                >
                    {{ $userCode }}
                </div>
            </div>

            {{-- Instructions --}}
            <div class="mt-7 rounded-md border border-[var(--color-border)] bg-[var(--color-surface-alt)] p-4 px-5 text-left" data-testid="captive-instructions">
                <p class="mb-2.5 text-[11px] font-bold uppercase tracking-[0.04em] text-[var(--color-text-muted)]">How to connect</p>
                <ol class="dispatch-steps flex flex-col gap-2 text-[13px] text-[var(--color-text-secondary)]">
                    <li class="flex items-start gap-2.5">
                        <span class="mt-px flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-[var(--color-surface)] font-mono text-[11px] font-semibold text-[var(--color-text-muted)]">1</span>
                        <span>Scan the QR code or visit the URL above on a device with internet</span>
                    </li>
                    <li class="flex items-start gap-2.5">
                        <span class="mt-px flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-[var(--color-surface)] font-mono text-[11px] font-semibold text-[var(--color-text-muted)]">2</span>
                        <span>Enter the code shown above when prompted</span>
                    </li>
                    <li class="flex items-start gap-2.5">
                        <span class="mt-px flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-[var(--color-surface)] font-mono text-[11px] font-semibold text-[var(--color-text-muted)]">3</span>
                        <span>Authorize the connection &mdash; you'll be connected automatically</span>
                    </li>
                </ol>
            </div>

            {{-- Status indicators --}}
            <div id="status" class="mt-6">
                <div id="status-pending" data-testid="captive-status-pending" class="flex items-center justify-center gap-2 text-[13px] text-[var(--color-text-secondary)]">
                    <span class="inline-block h-4 w-4 rounded-full border-2 border-[var(--color-border)] border-t-[var(--color-primary)] animate-spin"></span>
                    <span>Waiting for authorization&hellip;</span>
                </div>
                <div id="status-complete" data-testid="captive-status-complete" class="hidden flex items-center justify-center gap-2 text-[13px] font-semibold text-[var(--color-success)]">
                    <span>&#10003;</span>
                    <span>Authorized! Redirecting&hellip;</span>
                </div>
                <div id="status-expired" data-testid="captive-status-expired" class="hidden text-[13px] text-[var(--color-danger)]">
                    <p class="font-semibold">Your login code has expired</p>
                    <p class="mt-1 text-xs text-[var(--color-text-secondary)]">Please get a new code to continue.</p>
                    <button onclick="window.location.reload()" class="mt-3 rounded-md bg-[var(--color-primary)] px-4 py-2 text-xs font-semibold text-white transition-colors hover:bg-[var(--color-primary-hover)]" data-testid="captive-refresh">
                        Get New Code
                    </button>
                </div>
                <div id="status-error" data-testid="captive-status-error" class="hidden text-[13px] text-[var(--color-danger)]">
                    <p>An error occurred.</p>
                    <button onclick="window.location.reload()" class="mt-2 rounded-md bg-[var(--color-primary)] px-4 py-2 text-xs font-semibold text-white transition-colors hover:bg-[var(--color-primary-hover)]">
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
