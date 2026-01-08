import { computed, ref } from "vue";

import { usePolling } from "./usePolling";

// Generic job-status polling hook.
// You provide a fetcher so this works for queue jobs, AI jobs, media processing, etc.
//
// fetchStatuses signature:
//   async ({ ids }) => ({ [id]: { status, progress?, updatedAt?, meta? } })

export function useJobStatusPolling(options) {
    const jobIds = options.jobIds;
    const fetchStatuses = options.fetchStatuses;

    const enabled = options.enabled ?? ref(true);

    const statuses = ref({});

    async function fetchOnce() {
        const ids = Array.isArray(jobIds?.value) ? jobIds.value : jobIds;
        const list = (ids ?? []).filter(Boolean);

        if (list.length === 0) {
            statuses.value = {};
            return;
        }

        const data = await fetchStatuses({ ids: list });
        statuses.value = data ?? {};
    }

    const polling = usePolling(fetchOnce, {
        enabled,
        intervalMs: options.intervalMs ?? 4000,
        immediate: options.immediate ?? true,
        pauseWhenHidden: options.pauseWhenHidden ?? true,
        backoffInitialMs: options.backoffInitialMs ?? 1500,
        backoffMaxMs: options.backoffMaxMs ?? 20000,
    });

    const hasAny = computed(() => Object.keys(statuses.value || {}).length > 0);

    return {
        statuses,
        hasAny,
        enabled,
        ...polling,
    };
}
