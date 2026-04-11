@extends('layouts.captive')

@section('title', 'Connecting')

@section('content')
    <div class="text-center" data-testid="captive-interstitial">
        <!-- Spinner -->
        <div class="mx-auto mb-4 h-12 w-12 rounded-full border-[3px] border-[var(--color-border)] border-t-[var(--color-success)] animate-spin" data-testid="interstitial-spinner"></div>

        <h1 class="font-heading text-xl font-bold sm:text-2xl">Granting Network Access</h1>
        <p class="mt-1 text-sm text-[var(--color-text-secondary)]" id="status-message">Please wait while we configure your connection…</p>

        <!-- Step indicators -->
        <div class="mx-auto mt-5 flex max-w-[240px] flex-col gap-2">
            <div class="flex items-center gap-2.5 text-xs" id="step-1" data-testid="interstitial-step-1">
                <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-[var(--color-success)]/10 text-[10px] text-[var(--color-success)]">✓</span>
                <span class="text-[var(--color-text-secondary)]">Identity verified</span>
            </div>
            <div class="flex items-center gap-2.5 text-xs" id="step-2" data-testid="interstitial-step-2">
                <span class="step-icon flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-[var(--color-primary)]/10 text-[10px] text-[var(--color-primary)] animate-pulse">●</span>
                <span class="font-semibold text-[var(--color-text)]">Configuring access</span>
            </div>
            <div class="flex items-center gap-2.5 text-xs" id="step-3" data-testid="interstitial-step-3">
                <span class="step-icon flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-[var(--color-surface-hover)] text-[10px] text-[var(--color-text-muted)]">○</span>
                <span class="text-[var(--color-text-muted)]">Connecting to network</span>
            </div>
        </div>

        <!-- Error state -->
        <div id="status-error" data-testid="interstitial-error" class="mt-6 hidden text-sm text-[var(--color-danger)]">
            <p>Something went wrong granting access.</p>
            <button onclick="window.location.reload()" class="mt-2 rounded-lg bg-[var(--color-primary)] px-4 py-2 text-xs font-semibold text-white transition-colors hover:bg-[var(--color-primary-hover)]">
                Retry
            </button>
        </div>
    </div>
@endsection

@section('scripts')
    <script>
        (function() {
            var attempts = 0;
            var maxAttempts = 30;

            function setStepDone(stepId) {
                var step = document.getElementById(stepId);
                var icon = step.querySelector('.step-icon');
                var text = step.querySelector('span:last-child');
                if (icon) {
                    icon.className = 'flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-[var(--color-success)]/10 text-[10px] text-[var(--color-success)]';
                    icon.textContent = '✓';
                }
                if (text) {
                    text.className = 'text-[var(--color-text-secondary)]';
                    text.style.fontWeight = 'normal';
                }
            }

            function setStepActive(stepId) {
                var step = document.getElementById(stepId);
                var icon = step.querySelector('.step-icon');
                var text = step.querySelector('span:last-child');
                if (icon) {
                    icon.className = 'step-icon flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-[var(--color-primary)]/10 text-[10px] text-[var(--color-primary)] animate-pulse';
                    icon.textContent = '●';
                }
                if (text) {
                    text.className = 'font-semibold text-[var(--color-text)]';
                }
            }

            function checkStatus() {
                if (attempts >= maxAttempts) {
                    document.getElementById('status-error').classList.remove('hidden');
                    return;
                }
                attempts++;

                if (attempts > 3) {
                    setStepDone('step-2');
                    setStepActive('step-3');
                }

                fetch('/status', {
                    headers: { 'Accept': 'application/json' }
                })
                .then(function(response) { return response.json(); })
                .then(function(data) {
                    if (data.allowed) {
                        setStepDone('step-2');
                        setStepDone('step-3');
                        document.getElementById('status-message').textContent = 'Connected! Redirecting…';
                        setTimeout(function() { window.location.href = '/'; }, 1000);
                    } else {
                        setTimeout(checkStatus, 2000);
                    }
                })
                .catch(function() {
                    setTimeout(checkStatus, 3000);
                });
            }

            setTimeout(checkStatus, 2000);
        })();
    </script>
@endsection
