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
            <h2 class="admin-card__headline">Pipeline runs</h2>
            <BaseTable
                :columns="pipelineColumns"
                :rows="overview.pipeline_runs"
                :loading="loading"
                empty-title="No pipeline runs"
                empty-description="No pipelines have been started yet."
            >
                <template #cell-range="{ row }">
                    <span class="admin-callsMono">{{ row.range_from }} to {{ row.range_to }}</span>
                </template>
                <template #cell-status="{ value }">
                    <span class="pipeline-status" :class="`is-${value || 'unknown'}`">{{ value || 'unknown' }}</span>
                </template>
                <template #cell-started_at="{ value }">
                    <span class="admin-callsMono">{{ formatDate(value) }}</span>
                </template>
                <template #cell-finished_at="{ value }">
                    <span class="admin-callsMono">{{ formatDate(value) }}</span>
                </template>
                <template #cell-last_error="{ value }">
                    <span class="pipeline-error">{{ value || '—' }}</span>
                </template>
                <template #cell-actions="{ row }">
                    <BaseButton
                        size="sm"
                        variant="secondary"
                        :disabled="!row.can_resume"
                        :loading="isResuming(row.id)"
                        @click="resumePipeline(row.id)"
                    >
                        Resume
                    </BaseButton>
                </template>
            </BaseTable>
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
const resumeLoadingById = ref({});
const error = ref("");
const overview = ref({
    queue_connection: "",
    totals: {},
    queues: [],
    jobs: [],
    failed_jobs: [],
    pipeline_totals: {},
    pipeline_runs: [],
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

const pipelineColumns = [
    { key: "id", label: "Run ID" },
    { key: "company_name", label: "Company" },
    { key: "range", label: "Range" },
    { key: "status", label: "Status" },
    { key: "current_stage", label: "Current stage" },
    { key: "resume_count", label: "Resumes" },
    { key: "started_at", label: "Started" },
    { key: "finished_at", label: "Finished" },
    { key: "last_error", label: "Error" },
    { key: "actions", label: "Actions" },
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

function isResuming(id) {
    return Boolean(resumeLoadingById.value[id]);
}

async function resumePipeline(pipelineRunId) {
    if (!pipelineRunId) {
        return;
    }

    resumeLoadingById.value = {
        ...resumeLoadingById.value,
        [pipelineRunId]: true,
    };

    try {
        await adminApi.post(`/jobs/pipelines/${pipelineRunId}/resume`);
        await load();
    } catch (e) {
        error.value = "Failed to resume selected pipeline.";
    } finally {
        resumeLoadingById.value = {
            ...resumeLoadingById.value,
            [pipelineRunId]: false,
        };
    }
}
</script>

<style scoped>
.pipeline-status {
    display: inline-flex;
    align-items: center;
    border-radius: 999px;
    padding: 2px 8px;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    background: var(--admin-surface-2, #f3f4f6);
}

.pipeline-status.is-failed {
    background: rgba(220, 38, 38, 0.15);
    color: #dc2626;
}

.pipeline-status.is-running,
.pipeline-status.is-queued {
    background: rgba(37, 99, 235, 0.15);
    color: #2563eb;
}

.pipeline-status.is-completed {
    background: rgba(5, 150, 105, 0.15);
    color: #059669;
}

.pipeline-error {
    display: inline-block;
    max-width: 360px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
</style>
