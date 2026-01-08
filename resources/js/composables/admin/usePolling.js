import { onBeforeUnmount, ref, watch } from "vue";

function sleep(ms) {
    return new Promise((r) => setTimeout(r, ms));
}

export function usePolling(fetcher, options = {}) {
    const enabled = options.enabled ?? ref(true);

    const intervalMs = options.intervalMs ?? 5000;
    const immediate = options.immediate ?? true;

    const pauseWhenHidden = options.pauseWhenHidden ?? true;

    const backoff = {
        initialMs: options.backoffInitialMs ?? 1500,
        maxMs: options.backoffMaxMs ?? 20000,
    };

    const running = ref(false);
    const error = ref(null);
    const lastUpdatedAt = ref(null);

    let stopped = false;
    let currentBackoff = backoff.initialMs;

    async function tick() {
        if (stopped) return;
        if (typeof enabled?.value === "boolean" && !enabled.value) return;

        if (
            pauseWhenHidden &&
            typeof document !== "undefined" &&
            document.hidden
        ) {
            return;
        }

        running.value = true;
        error.value = null;

        try {
            await fetcher();
            lastUpdatedAt.value = new Date();
            currentBackoff = backoff.initialMs;
        } catch (e) {
            error.value = e;
            currentBackoff = Math.min(backoff.maxMs, currentBackoff * 1.6);
        } finally {
            running.value = false;
        }
    }

    async function loop() {
        if (immediate) {
            await tick();
        }

        while (!stopped) {
            const wait = error.value ? currentBackoff : intervalMs;
            await sleep(wait);
            await tick();
        }
    }

    function start() {
        if (stopped) return;
        if (running.value) return;

        // Fire-and-forget loop
        loop();
    }

    function stop() {
        stopped = true;
    }

    onBeforeUnmount(() => {
        stop();
    });

    // Restart polling when enabled flips true.
    watch(
        () => (typeof enabled?.value === "boolean" ? enabled.value : true),
        (v) => {
            if (!v) return;
            if (!stopped) start();
        },
        { immediate: true }
    );

    return {
        running,
        error,
        lastUpdatedAt,
        start,
        stop,
    };
}
