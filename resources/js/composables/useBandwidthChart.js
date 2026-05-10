import { ref, computed, onMounted, onUnmounted } from 'vue';

/**
 * Composable that encapsulates bandwidth fetch/poll/range-selection/chart-series logic.
 *
 * @param {string} endpoint - The API endpoint URL (without query string)
 * @param {string} [defaultRange='24h'] - The initial time range selection
 * @param {number} [pollInterval=30000] - Polling interval in ms; pass 0 to disable internal polling
 * @param {boolean} [enabled=true] - When false, skips initial fetch and polling (e.g. no IPs)
 * @returns {{ selectedRange, bandwidthData, bandwidthLoading, bandwidthError, chartSeries, selectRange, fetchBandwidth }}
 */
export function useBandwidthChart(endpoint, defaultRange = '24h', pollInterval = 30000, enabled = true) {
    const selectedRange = ref(defaultRange);
    const bandwidthData = ref({
        timestamps: [],
        download: [],
        upload: [],
        totalReceived: 0,
        totalSent: 0,
    });
    // Start loading only when enabled (a fetch will happen); if disabled, nothing to load
    const bandwidthLoading = ref(enabled);
    const bandwidthError = ref(false);

    const chartSeries = computed(() => {
        const { timestamps, download, upload } = bandwidthData.value;
        if (!timestamps.length) return [];
        return [
            {
                label: 'Download',
                color: 'var(--color-success)',
                fill: true,
                data: timestamps.map((ts, i) => ({ timestamp: Number(ts), value: download[i] ?? 0 })),
            },
            {
                label: 'Upload',
                color: 'var(--color-info)',
                fill: true,
                data: timestamps.map((ts, i) => ({ timestamp: Number(ts), value: upload[i] ?? 0 })),
            },
        ];
    });

    async function fetchBandwidth() {
        bandwidthLoading.value = true;
        bandwidthError.value = false;
        try {
            const response = await fetch(`${endpoint}?range=${selectedRange.value}`);
            if (response.ok) {
                bandwidthData.value = await response.json();
            } else {
                bandwidthError.value = true;
            }
        } catch (_e) {
            bandwidthError.value = true;
        } finally {
            bandwidthLoading.value = false;
        }
    }

    function selectRange(range) {
        selectedRange.value = range;
        fetchBandwidth();
    }

    let pollTimer = null;

    onMounted(() => {
        if (!enabled) return;
        fetchBandwidth();
        if (pollInterval > 0) {
            pollTimer = setInterval(fetchBandwidth, pollInterval);
        }
    });

    onUnmounted(() => {
        if (pollTimer !== null) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
    });

    return {
        selectedRange,
        bandwidthData,
        bandwidthLoading,
        bandwidthError,
        chartSeries,
        selectRange,
        fetchBandwidth,
    };
}

