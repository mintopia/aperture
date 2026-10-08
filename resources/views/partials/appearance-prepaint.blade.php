{{-- Applies the per-browser appearance preferences before first paint so there is no flash. Keep in step with the useTransparency / useGlassSheenPreference / useAnimatedBackground / useBackgroundIntensity composables. --}}
<script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
    (function () {
        var root = document.documentElement;
        function read(key) {
            try { return localStorage.getItem(key); } catch (e) { return null; }
        }
        function media(query) {
            return !!(window.matchMedia && window.matchMedia(query).matches);
        }

        var transparency = read('reduceTransparency');
        if (transparency === '1' || (transparency === null && media('(prefers-reduced-transparency: reduce)'))) {
            root.setAttribute('data-transparency', 'reduced');
        }

        if (read('glassSheen') === '0') {
            root.setAttribute('data-sheen', 'off');
        }

        var animated = read('animatedBackground');
        if (animated === '0' || (animated === null && media('(prefers-reduced-motion: reduce)'))) {
            root.setAttribute('data-animated-bg', 'off');
        }

        var intensity = parseFloat(read('backgroundIntensity'));
        if (isFinite(intensity)) {
            intensity = Math.min(100, Math.max(0, Math.round(intensity)));
            root.style.setProperty('--bg-intensity', String(0.25 + (intensity / 100) * 1.5));
        }

        if (navigator.userAgentData && navigator.userAgentData.brands.some(function (b) { return b.brand === 'Chromium'; })) {
            root.setAttribute('data-refraction', '');
        }
    })();
</script>
