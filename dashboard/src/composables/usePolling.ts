import { onMounted, onUnmounted, ref, type Ref } from "vue";

export type PollingOptions = {
    enabled?: Ref<boolean>;
    initialDelayMs?: number;
    intervalMs: number;
    maxIntervalMs?: number;
    jitterRatio?: number; // 0.1 => +/-10%
    pauseWhenHidden?: boolean;
};

function withJitter(ms: number, jitterRatio: number): number {
    const jitter = ms * jitterRatio;
    const min = ms - jitter;
    const max = ms + jitter;
    return Math.round(min + Math.random() * (max - min));
}

export function usePolling(
    task: () => Promise<"continue" | "stop">,
    options: PollingOptions
) {
    const enabled = options.enabled ?? ref(true);
    const running = ref(false);

    const baseIntervalMs = options.intervalMs;
    const maxIntervalMs =
        options.maxIntervalMs ?? Math.max(baseIntervalMs, 60_000);
    const jitterRatio = options.jitterRatio ?? 0.1;
    const pauseWhenHidden = options.pauseWhenHidden ?? true;

    let timer: number | null = null;
    let stopped = false;

    const currentIntervalMs = ref(baseIntervalMs);

    function clearTimer() {
        if (timer !== null) {
            window.clearTimeout(timer);
            timer = null;
        }
    }

    function stop() {
        stopped = true;
        running.value = false;
        clearTimer();
    }

    function resetBackoff() {
        currentIntervalMs.value = baseIntervalMs;
    }

    function backoff() {
        currentIntervalMs.value = Math.min(
            maxIntervalMs,
            Math.round(currentIntervalMs.value * 1.5)
        );
    }

    async function tick() {
        if (stopped || !enabled.value) {
            stop();
            return;
        }

        if (pauseWhenHidden && document.hidden) {
            timer = window.setTimeout(
                tick,
                withJitter(currentIntervalMs.value, jitterRatio)
            );
            return;
        }

        running.value = true;

        try {
            const res = await task();
            if (res === "stop") {
                stop();
                return;
            }

            // success: reset backoff
            resetBackoff();
        } catch {
            // error: backoff so we don't overload API
            backoff();
        } finally {
            running.value = false;
        }

        timer = window.setTimeout(
            tick,
            withJitter(currentIntervalMs.value, jitterRatio)
        );
    }

    function start() {
        stopped = false;
        clearTimer();
        const initial = options.initialDelayMs ?? 0;
        timer = window.setTimeout(tick, initial);
    }

    onMounted(() => {
        if (enabled.value) start();
    });

    onUnmounted(() => {
        stop();
    });

    return {
        running,
        enabled,
        start,
        stop,
        resetBackoff,
        backoff,
        currentIntervalMs,
    };
}
