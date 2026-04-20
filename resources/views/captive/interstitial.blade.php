@extends('layouts.captive')

@section('title', 'Connecting')

@section('content')
    <div class="text-center" data-testid="captive-interstitial">
        {{-- Animated spinner using accent color --}}
        <div
            class="mx-auto mb-5 h-[52px] w-[52px] rounded-full border-[3px] border-[var(--color-border)] border-t-[var(--color-primary)] animate-spin"
            data-testid="interstitial-spinner"
        ></div>

        <h1 class="font-heading text-2xl font-bold tracking-tight" style="font-variation-settings: 'opsz' 32;">Granting Network Access</h1>
        <p class="mt-1.5 text-sm text-[var(--color-text-secondary)]" id="status-message">Please wait while we configure your connection&hellip;</p>

        {{-- Step list --}}
        <div class="mx-auto mt-7 flex max-w-[240px] flex-col gap-2.5">
            {{-- Step 1: done --}}
            <div class="flex items-center gap-2.5 text-[13px]" id="step-1" data-testid="interstitial-step-1">
                <span class="flex h-[22px] w-[22px] shrink-0 items-center justify-center rounded-full bg-[var(--color-success)]/10 text-[11px] text-[var(--color-success)]">&#10003;</span>
                <span class="step-label text-[var(--color-text-secondary)]">Identity verified</span>
            </div>
            {{-- Step 2: active --}}
            <div class="flex items-center gap-2.5 text-[13px]" id="step-2" data-testid="interstitial-step-2">
                <span class="step-icon flex h-[22px] w-[22px] shrink-0 items-center justify-center rounded-full bg-[var(--color-accent-dim)] text-[11px] text-[var(--color-primary)] animate-pulse">&#9679;</span>
                <span class="step-label font-semibold text-[var(--color-text)]">Configuring access</span>
            </div>
            {{-- Step 3: pending --}}
            <div class="flex items-center gap-2.5 text-[13px]" id="step-3" data-testid="interstitial-step-3">
                <span class="step-icon flex h-[22px] w-[22px] shrink-0 items-center justify-center rounded-full bg-[var(--color-surface)] text-[11px] text-[var(--color-text-muted)]">&#9675;</span>
                <span class="step-label text-[var(--color-text-muted)]">Connecting to network</span>
            </div>
        </div>

        {{-- Error state with retry --}}
        <div id="status-error" data-testid="interstitial-error" class="mt-6 hidden text-[13px] text-[var(--color-danger)]">
            <p>Something went wrong granting access.</p>
            <button onclick="window.location.reload()" class="mt-2 rounded-md bg-[var(--color-primary)] px-4 py-2 text-xs font-semibold text-white transition-colors hover:bg-[var(--color-primary-hover)]">
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
                var text = step.querySelector('.step-label');
                if (icon) {
                    icon.className = 'flex h-[22px] w-[22px] shrink-0 items-center justify-center rounded-full bg-[var(--color-success)]/10 text-[11px] text-[var(--color-success)]';
                    icon.innerHTML = '&#10003;';
                }
                if (text) {
                    text.className = 'step-label text-[var(--color-text-secondary)]';
                    text.style.fontWeight = 'normal';
                }
            }

            function setStepActive(stepId) {
                var step = document.getElementById(stepId);
                var icon = step.querySelector('.step-icon');
                var text = step.querySelector('.step-label');
                if (icon) {
                    icon.className = 'step-icon flex h-[22px] w-[22px] shrink-0 items-center justify-center rounded-full bg-[var(--color-accent-dim)] text-[11px] text-[var(--color-primary)] animate-pulse';
                    icon.innerHTML = '&#9679;';
                }
                if (text) {
                    text.className = 'step-label font-semibold text-[var(--color-text)]';
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
