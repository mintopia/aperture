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
                    class="mt-4 rounded-md bg-[var(--color-primary)] px-4 py-2 text-xs font-semibold text-[var(--color-accent-text)] transition-colors hover:bg-[var(--color-primary-hover)]"
                >
                    Try Again
                </button>
            </div>
        @else
            {{-- Logo mark --}}
            <div class="captive-reveal">
            @if($hasSiteLogo ?? false)
                <img src="{{ $siteLogoUrl }}" alt="{{ $siteTitle }}" class="mx-auto mb-5 h-24 w-24 rounded-xl" data-testid="captive-logo-image">
            @else
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" class="mx-auto mb-4 h-12 w-12 text-[var(--color-primary)]" aria-hidden="true" data-testid="captive-logo-icon">
                    <circle cx="12" cy="12" r="10" /><line x1="14.31" y1="8" x2="20.05" y2="17.94" /><line x1="9.69" y1="8" x2="21.17" y2="8" /><line x1="7.38" y1="12" x2="13.12" y2="2.06" /><line x1="9.69" y1="16" x2="3.95" y2="6.06" /><line x1="14.31" y1="16" x2="2.83" y2="16" /><line x1="16.62" y1="12" x2="10.88" y2="21.94" />
                </svg>
            @endif
            </div>

            <div class="captive-reveal">
                <h1 class="font-heading text-2xl font-bold tracking-tight" style="font-variation-settings: 'opsz' 32;">Connect to Network</h1>
                <p class="mt-1.5 text-sm text-[var(--color-text-secondary)]">Scan the QR code or enter the code below</p>
            </div>

            {{-- QR Code with accent border and glow --}}
            <div
                class="captive-reveal qr-glow mx-auto my-7 h-[168px] w-[168px] rounded-[14px] border-2 border-[var(--color-primary)] p-1.5"
                data-testid="captive-qr"
                id="qr-container"
            >
                <div class="flex h-full w-full items-center justify-center rounded-[10px] bg-white">
                    {!! $qrCode !!}
                </div>
            </div>

            {{-- Verification URL --}}
            <p class="captive-reveal text-xs text-[var(--color-text-muted)]">
                Visit <a href="{{ $verificationUri }}" target="_blank" class="text-[var(--color-primary)] underline decoration-[var(--color-primary)]/30 underline-offset-2 hover:decoration-[var(--color-primary)]">{{ $verificationUri }}</a>
            </p>

            {{-- User code --}}
            <div class="captive-reveal my-5">
                <p class="text-[10px] font-semibold uppercase tracking-[0.06em] text-[var(--color-text-muted)]">Enter this code</p>
                <div
                    class="mt-1 font-mono text-4xl font-bold tracking-[0.15em] text-[var(--color-primary)]"
                    data-testid="captive-user-code"
                    id="user-code"
                    aria-label="{{ $userCode }}"
                >
                    {{ $userCode }}
                </div>
            </div>

            {{-- Instructions --}}
            <div class="captive-reveal mt-7 rounded-md border border-[var(--color-border)] bg-[var(--color-surface-alt)] p-4 px-5 text-left" data-testid="captive-instructions">
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
            <div id="status" class="captive-reveal mt-6">
                <div id="status-pending" data-testid="captive-status-pending" class="flex items-center justify-center gap-2 text-[13px] text-[var(--color-text-secondary)]">
                    <span class="inline-block h-4 w-4 rounded-full border-2 border-[var(--color-border)] border-t-[var(--color-primary)] animate-spin"></span>
                    <span>Waiting for authorization&hellip;</span>
                </div>
                <div id="status-complete" data-testid="captive-status-complete" class="hidden text-center text-[13px] font-semibold text-[var(--color-success)]">
                    <div class="success-burst mx-auto mb-3">
                        <svg class="success-checkmark mx-auto h-10 w-10" viewBox="0 0 40 40" fill="none" aria-hidden="true">
                            <circle cx="20" cy="20" r="18" stroke="currentColor" stroke-width="1.5" fill="currentColor" fill-opacity="0.1" />
                            <path pathLength="1" d="M12 20L17.5 25.5L28 14" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    </div>
                    <span>Authorized! Redirecting&hellip;</span>
                </div>
                <div id="status-expired" data-testid="captive-status-expired" class="hidden text-[13px] text-[var(--color-danger)]">
                    <p class="font-semibold">Your login code has expired</p>
                    <p class="mt-1 text-xs text-[var(--color-text-secondary)]">Please get a new code to continue.</p>
                    <button onclick="window.location.reload()" class="mt-3 rounded-md bg-[var(--color-primary)] px-4 py-2 text-xs font-semibold text-[var(--color-accent-text)] transition-colors hover:bg-[var(--color-primary-hover)]" data-testid="captive-refresh">
                        Get New Code
                    </button>
                </div>
                <div id="status-error" data-testid="captive-status-error" class="hidden text-[13px] text-[var(--color-danger)]">
                    <p>An error occurred.</p>
                    <button onclick="window.location.reload()" class="mt-2 rounded-md bg-[var(--color-primary)] px-4 py-2 text-xs font-semibold text-[var(--color-accent-text)] transition-colors hover:bg-[var(--color-primary-hover)]">
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

            // Typewriter reveal for device code
            var codeEl = document.getElementById('user-code');
            if (codeEl && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                var codeText = codeEl.textContent.trim();
                codeEl.textContent = '';
                var chars = codeText.split('');
                chars.forEach(function(ch, i) {
                    setTimeout(function() {
                        var span = document.createElement('span');
                        span.textContent = ch;
                        span.style.display = 'inline-block';
                        span.style.animation = 'captive-fade-up 200ms cubic-bezier(0.16, 1, 0.3, 1) both';
                        codeEl.appendChild(span);
                    }, 300 + i * 70);
                });
            }

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
