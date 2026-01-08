import { onBeforeUnmount, ref } from "vue";

function requestIdle(cb) {
    if (typeof requestIdleCallback !== "undefined") {
        return requestIdleCallback(cb);
    }

    return setTimeout(
        () => cb({ timeRemaining: () => 0, didTimeout: true }),
        0
    );
}

function cancelIdle(id) {
    if (typeof cancelIdleCallback !== "undefined") {
        cancelIdleCallback(id);
        return;
    }

    clearTimeout(id);
}

// Non-blocking pattern for heavy client-side work.
// Useful for large datasets: transform/sort/group in chunks without freezing UI.
export function useChunkedWork() {
    const running = ref(false);
    const progress = ref(0);

    let idleId = null;
    let cancelled = false;

    async function run(items, handler, options = {}) {
        const chunkSize = options.chunkSize ?? 200;

        cancelled = false;
        running.value = true;
        progress.value = 0;

        const list = Array.isArray(items) ? items : [];
        const total = list.length;
        let index = 0;

        return new Promise((resolve, reject) => {
            const step = async () => {
                if (cancelled) {
                    running.value = false;
                    return resolve({ cancelled: true });
                }

                try {
                    const end = Math.min(total, index + chunkSize);
                    for (; index < end; index++) {
                        await handler(list[index], index);
                    }

                    progress.value = total === 0 ? 1 : index / total;

                    if (index >= total) {
                        running.value = false;
                        return resolve({ cancelled: false });
                    }

                    idleId = requestIdle(step);
                } catch (e) {
                    running.value = false;
                    return reject(e);
                }
            };

            idleId = requestIdle(step);
        });
    }

    function cancel() {
        cancelled = true;
        if (idleId) {
            cancelIdle(idleId);
            idleId = null;
        }
        running.value = false;
    }

    onBeforeUnmount(() => {
        cancel();
    });

    return {
        running,
        progress,
        run,
        cancel,
    };
}
