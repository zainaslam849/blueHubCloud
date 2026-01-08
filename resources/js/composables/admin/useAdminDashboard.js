import { computed, onBeforeUnmount, ref } from "vue";

import { fetchAdminDashboard } from "../../router/admin/dashboard";

function formatCompactNumber(value) {
    const numberValue = Number(value);
    if (!Number.isFinite(numberValue)) return "—";
    return new Intl.NumberFormat(undefined, {
        notation: "compact",
        maximumFractionDigits: 1,
    }).format(numberValue);
}

function formatRelativeTime(isoString) {
    const time = new Date(isoString).getTime();
    if (!Number.isFinite(time)) return "—";

    const diffMs = Date.now() - time;
    const mins = Math.round(diffMs / 60000);

    if (mins < 1) return "just now";
    if (mins < 60) return `${mins}m ago`;

    const hrs = Math.round(mins / 60);
    if (hrs < 24) return `${hrs}h ago`;

    const days = Math.round(hrs / 24);
    return `${days}d ago`;
}

export function useAdminDashboard() {
    const loading = ref(true);
    const error = ref("");
    const data = ref(null);

    let cancelled = false;

    async function load() {
        loading.value = true;
        error.value = "";

        try {
            const result = await fetchAdminDashboard();
            if (cancelled) return;
            data.value = result;
        } catch (e) {
            if (cancelled) return;
            error.value = "Failed to load dashboard.";
            data.value = null;
        } finally {
            if (cancelled) return;
            loading.value = false;
        }
    }

    onBeforeUnmount(() => {
        cancelled = true;
    });

    const kpis = computed(() => {
        const k = data.value?.kpis;
        if (!k) return [];

        const list = [k.calls, k.recordings, k.jobs, k.users].filter(Boolean);

        return list.map((item) => ({
            key: item.key,
            label: item.label,
            value: formatCompactNumber(item.value),
            hint: item.period,
            badgeLabel:
                item.status === "active"
                    ? "Active"
                    : item.status === "processing"
                    ? "Processing"
                    : "Failed",
            badgeVariant: item.status,
            icon: item.key,
        }));
    });

    const headerBadges = computed(() => {
        const s = data.value?.statuses;
        if (!s) {
            return [
                { key: "active", label: "Active", variant: "active" },
                {
                    key: "processing",
                    label: "Processing",
                    variant: "processing",
                },
                { key: "failed", label: "Failed", variant: "failed" },
            ];
        }

        return [
            {
                key: "active",
                label: `Active (${s.active ?? 0})`,
                variant: "active",
            },
            {
                key: "processing",
                label: `Processing (${s.processing ?? 0})`,
                variant: "processing",
            },
            {
                key: "failed",
                label: `Failed (${s.failed ?? 0})`,
                variant: "failed",
            },
        ];
    });

    const queueMetrics = computed(() => {
        const q = data.value?.queue;
        if (!q) return [];

        return [
            {
                key: "workers",
                label: "Active workers",
                value: String(q.workers?.total ?? "—"),
                statusLabel: q.workers?.busy > 0 ? "Active" : "Processing",
                statusVariant: q.workers?.busy > 0 ? "active" : "processing",
            },
            {
                key: "running",
                label: "Jobs running",
                value: String(q.jobs?.running ?? "—"),
                statusLabel: "Processing",
                statusVariant: "processing",
            },
            {
                key: "failures",
                label: "Failures (15m)",
                value: String(q.failures?.last15m ?? "—"),
                statusLabel:
                    (q.failures?.last15m ?? 0) > 0 ? "Failed" : "Active",
                statusVariant:
                    (q.failures?.last15m ?? 0) > 0 ? "failed" : "active",
            },
        ];
    });

    const recentActivity = computed(() => {
        const items = data.value?.recentActivity;
        if (!Array.isArray(items)) return [];

        return items.map((it) => ({
            id: it.id,
            title: it.title,
            sub: it.description,
            statusLabel:
                it.status === "active"
                    ? "Active"
                    : it.status === "processing"
                    ? "Processing"
                    : "Failed",
            statusVariant: it.status,
            time: formatRelativeTime(it.occurredAt),
        }));
    });

    return {
        loading,
        error,
        data,
        load,
        kpis,
        headerBadges,
        queueMetrics,
        recentActivity,
    };
}
