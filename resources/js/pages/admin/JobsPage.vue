<template>
    <div class="admin-container admin-page">
        <header class="admin-page__header">
            <div>
                <p class="admin-page__kicker">Operations</p>
                <h1 class="admin-page__title">Jobs / Queue</h1>
                <p class="admin-page__subtitle">
                    Monitor queued, running, and failed jobs across the system.
                </p>
            </div>
            <BaseButton
                variant="secondary"
                size="sm"
                :loading="loading"
                @click="load"
            >
                Refresh
            </BaseButton>
        </header>

        <section class="admin-dashboard__grid">
            <PanelCard title="Queue overview" :meta="queueMeta">
                <div v-if="loading" class="admin-metricRows">
                    <MetricRowSkeleton v-for="i in 3" :key="i" />
                </div>
                <div v-else class="admin-metricRows">
                    <MetricRow
                        label="Queue connection"
                        :value="overview.queue_connection || '—'"
                        status-label="Active"
                        status-variant="active"
                    />
                    <MetricRow
                        label="Queued jobs"
                        :value="String(overview.totals?.queued ?? '—')"
                        status-label="Processing"
                        status-variant="processing"
                    />
                    <MetricRow
                        label="Reserved jobs"
                        :value="String(overview.totals?.reserved ?? '—')"
                        status-label="Processing"
                        status-variant="processing"
                    />
                    <MetricRow
                        label="Failed jobs"
                        :value="String(overview.totals?.failed ?? '—')"
                        :status-label="
                            (overview.totals?.failed ?? 0) > 0
                                ? 'Failed'
                                : 'Active'
                        "
                        :status-variant="
                            (overview.totals?.failed ?? 0) > 0
                                ? 'failed'
                                : 'active'
                        "
                    />
                </div>
            </PanelCard>

            <PanelCard title="Queues" meta="By queue name">
                <BaseTable
                    :columns="queueColumns"
                    :rows="overview.queues"
                    :loading="loading"
                    empty-title="No queue data"
                    empty-description="No queued jobs found."
                />
            </PanelCard>
        </section>

        <section class="admin-card admin-card--glass">
            <h2 class="admin-card__headline">Queued jobs</h2>
            <BaseTable
                :columns="jobColumns"
                :rows="overview.jobs"
                :loading="loading"
                empty-title="No queued jobs"
                empty-description="There are no queued jobs right now."
            >
                <template #cell-created_at="{ value }">
                    <span class="admin-callsMono">{{ formatDate(value) }}</span>
                </template>
                <template #cell-available_at="{ value }">
                    <span class="admin-callsMono">{{ formatDate(value) }}</span>
                </template>
                <template #cell-reserved_at="{ value }">
                    <span class="admin-callsMono">{{ formatDate(value) }}</span>
                </template>
            </BaseTable>
        </section>

        <section class="admin-card admin-card--glass">
            <h2 class="admin-card__headline">Failed jobs</h2>
            <BaseTable
                :columns="failedColumns"
                :rows="overview.failed_jobs"
                :loading="loading"
                empty-title="No failed jobs"
                empty-description="No failures recorded."
            >
                <template #cell-failed_at="{ value }">
                    <span class="admin-callsMono">{{ formatDate(value) }}</span>
                </template>
            </BaseTable>
        </section>

        <p v-if="error" class="admin-dashboard__error" role="status">
            {{ error }}
        </p>
    </div>
</template>

<script setup>
import { computed, onMounted, ref } from "vue";

import { BaseButton, BaseTable } from "../../components/admin/base";
import MetricRow from "../../components/admin/MetricRow.vue";
import MetricRowSkeleton from "../../components/admin/dashboard/MetricRowSkeleton.vue";
import PanelCard from "../../components/admin/PanelCard.vue";

import adminApi from "../../router/admin/api";

const loading = ref(true);
const error = ref("");
const overview = ref({
    queue_connection: "",
    totals: {},
    queues: [],
    jobs: [],
    failed_jobs: [],
});

const queueColumns = [
    { key: "queue", label: "Queue" },
    { key: "queued", label: "Queued" },
    { key: "reserved", label: "Reserved" },
];

const jobColumns = [
    { key: "id", label: "ID" },
    { key: "name", label: "Job" },
    { key: "queue", label: "Queue" },
    { key: "attempts", label: "Attempts" },
    { key: "created_at", label: "Created" },
    { key: "available_at", label: "Available" },
    { key: "reserved_at", label: "Reserved" },
];

const failedColumns = [
    { key: "id", label: "ID" },
    { key: "queue", label: "Queue" },
    { key: "failed_at", label: "Failed at" },
    { key: "error", label: "Error" },
];

const queueMeta = computed(() => (loading.value ? "Loading…" : "Last update"));

onMounted(() => {
    load();
});

async function load() {
    loading.value = true;
    error.value = "";
    try {
        const res = await adminApi.get("/jobs/overview");
        overview.value = res?.data?.data ?? overview.value;
    } catch (e) {
        error.value = "Failed to load jobs overview.";
    } finally {
        loading.value = false;
    }
}

function formatDate(value) {
    if (!value) return "—";
    try {
        return new Date(value).toLocaleString();
    } catch (e) {
        return String(value);
    }
}
</script>
