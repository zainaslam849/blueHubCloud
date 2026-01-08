<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import Card from "../ui/Card.vue";
import ReportStatusBadge from "./ReportStatusBadge.vue";
import type { WeeklyReportSummary } from "../../api/reports";
import { listWeeklyReports } from "../../api/reports";
import { usePolling } from "../../composables/usePolling";
import { useReportUpdates } from "../../composables/useReportUpdates";

const loading = ref(true);
const error = ref<string | null>(null);
const reports = ref<WeeklyReportSummary[]>([]);

const TERMINAL = new Set(["completed", "ready", "done", "failed", "error"]);

const hasInProgress = computed(() => {
    return reports.value.some((r) => {
        const s = String(r.status ?? "").toLowerCase();
        return !TERMINAL.has(s);
    });
});

function weekLabel(r: WeeklyReportSummary): string {
    if (r.week) return r.week;
    if (r.week_start && r.week_end) return `${r.week_start} → ${r.week_end}`;
    return "—";
}

async function load() {
    loading.value = true;
    error.value = null;

    try {
        reports.value = await listWeeklyReports();
    } catch (e: any) {
        error.value = e?.response?.data?.message ?? "Failed to load reports";
        reports.value = [];
    } finally {
        loading.value = false;
    }
}

onMounted(load);

// Optional WebSocket: patch in-place updates when available.
const ws = useReportUpdates((update) => {
    reports.value = ws.applyUpdateToList(reports.value, update);
});

// Safe polling: only poll while any report is still processing.
usePolling(
    async () => {
        if (!hasInProgress.value) return "stop";
        const next = await listWeeklyReports();
        reports.value = next;
        return hasInProgress.value ? "continue" : "stop";
    },
    {
        intervalMs: 20_000,
        maxIntervalMs: 60_000,
        jitterRatio: 0.1,
        pauseWhenHidden: true,
        enabled: hasInProgress,
        initialDelayMs: 10_000,
    }
);
</script>

<template>
    <Card>
        <div v-if="loading" class="state">Loading weekly reports…</div>

        <div v-else-if="error" class="state error">{{ error }}</div>

        <div v-else-if="reports.length === 0" class="state">
            No weekly reports yet.
        </div>

        <div v-else class="tableWrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Week</th>
                        <th>Status</th>
                        <th>Generated</th>
                        <th style="width: 140px">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="r in reports" :key="r.id">
                        <td class="strong">
                            <router-link
                                :to="{
                                    name: 'report-detail',
                                    params: { id: r.id },
                                }"
                            >
                                {{ weekLabel(r) }}
                            </router-link>
                        </td>
                        <td>
                            <ReportStatusBadge :status="r.status" />
                        </td>
                        <td>{{ r.generated_at ?? "—" }}</td>
                        <td>
                            <router-link
                                class="btnLink"
                                :to="{
                                    name: 'report-detail',
                                    params: { id: r.id },
                                }"
                            >
                                View
                            </router-link>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </Card>
</template>

<style scoped>
.state {
    padding: var(--space-5);
    opacity: 0.85;
}

.state.error {
    opacity: 1;
}

.tableWrap {
    overflow: auto;
}

.table {
    width: 100%;
    border-collapse: collapse;
    text-align: left;
}

th,
td {
    padding: 12px 10px;
    border-bottom: 1px solid var(--border);
    vertical-align: middle;
}

th {
    font-size: 0.9rem;
    opacity: 0.75;
    font-weight: 700;
}

.strong {
    font-weight: 700;
}

.btnLink {
    display: inline-flex;
    padding: 8px 10px;
    border-radius: 10px;
    border: 1px solid var(--border);
    background: var(--surface-2);
}
</style>
