import { onMounted, onUnmounted } from 'vue';

function generateUuid() {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
        return crypto.randomUUID();
    }
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
        const r = (Math.random() * 16) | 0;
        return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
    });
}

async function detectAndSubmitIpv6(endpointTemplate) {
    const endpoint = endpointTemplate.replace('{uuid}', generateUuid());
    try {
        const response = await fetch(endpoint);
        if (!response.ok) return null;
        const data = await response.json();
        if (!data.token || !data.token.trim()) return null;
        const postResponse = await fetch('/ipv6', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ token: data.token.trim() }),
        });
        if (!postResponse.ok) return null;
        return await postResponse.json();
    } catch {
        return null;
    }
}

export function useIpv6Detection(endpointTemplate, options = {}) {
    const { onDetected, intervalMs = 120000 } = options;
    let timer = null;

    async function runDetection() {
        const result = await detectAndSubmitIpv6(endpointTemplate);
        if (result && onDetected) {
            onDetected(result);
        }
    }

    onMounted(() => {
        if (!endpointTemplate) return;

        runDetection();

        timer = setInterval(runDetection, intervalMs);
    });

    onUnmounted(() => {
        if (timer !== null) {
            clearInterval(timer);
            timer = null;
        }
    });

    return { detectAndSubmitIpv6 };
}
