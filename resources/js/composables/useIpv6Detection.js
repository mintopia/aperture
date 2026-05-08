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
        if (!response.ok) return;
        const token = await response.text();
        if (!token || !token.trim()) return;
        await fetch('/ipv6', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ token: token.trim() }),
        });
    } catch {
        // IPv6 detection is best-effort
    }
}

export function useIpv6Detection(endpointTemplate, intervalMs = 120000) {
    let timer = null;

    onMounted(() => {
        if (!endpointTemplate) return;

        detectAndSubmitIpv6(endpointTemplate);

        timer = setInterval(() => {
            detectAndSubmitIpv6(endpointTemplate);
        }, intervalMs);
    });

    onUnmounted(() => {
        if (timer !== null) {
            clearInterval(timer);
            timer = null;
        }
    });

    return { detectAndSubmitIpv6 };
}
