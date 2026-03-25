<template>
    <div class="admin-container admin-page">
        <header class="admin-page__header">
            <div>
                <p class="admin-page__kicker">Operations</p>
                <h1 class="admin-page__title">Jobs / Queue</h1>
                <p class="admin-page__subtitle">
                    Monitor queued, running, and failed jobs across the system.
                </p>
                <p
                    v-if="showHorizonStatus"
                    class="horizon-status"
                    :class="`is-${horizonStatusClass}`"
                >
                    Horizon status: {{ horizonStatusLabel }}
                </p>
                <p
                    v-if="resumeFeedback.message"
                    class="resume-feedback"
                    :class="`is-${resumeFeedback.type}`"
                    role="status"
                >
                    {{ resumeFeedback.message }}
                </p>
                <p
                    v-if="workerHealthMessage"
                    class="worker-health"
                    :class="`is-${overview.worker_health?.level || 'ok'}`"
                    role="status"
                >
                    {{ workerHealthMessage }}
                </p>
                <p v-if="showWorkerStartHint" class="worker-hint" role="status">
                    Start workers:
                    {{ overview.worker_start_hint?.start_command }} | Check:
                    {{ overview.worker_start_hint?.status_command }}
                </p>
            </div>
            <div class="header-actions">
                <BaseButton
                    v-if="showStartWorkersButton"
                    variant="secondary"
                    size="sm"
                    :loading="startingWorkers"
                    :disabled="startingWorkers"
                    @click="startWorkers"
                >
                    Start Workers
                </BaseButton>
                <BaseButton
                    variant="secondary"
                    size="sm"
                    :loading="loading"
                    @click="load"
                >
                    Refresh
                </BaseButton>
            </div>
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
                    <span class="admin-callsMono"
                        >{{ row.range_from }} to {{ row.range_to }}</span
                    >
                </template>
                <template #cell-status="{ value }">
                    <span
                        class="pipeline-status"
                        :class="`is-${value || 'unknown'}`"
                        >{{ value || "unknown" }}</span
                    >
                </template>
                <template #cell-started_at="{ value }">
                    <span class="admin-callsMono">{{ formatDate(value) }}</span>
                </template>
                <template #cell-updated_at="{ value }">
                    <span class="admin-callsMono">{{ formatDate(value) }}</span>
                </template>
                <template #cell-finished_at="{ value }">
                    <span class="admin-callsMono">{{ formatDate(value) }}</span>
                </template>
                <template #cell-last_error="{ value }">
                    <span class="pipeline-error">{{ value || "—" }}</span>
                </template>
                <template #cell-transcript_signal="{ value }">
                    <span class="pipeline-transcript-signal">{{ value || "—" }}</span>
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

        <section
            v-if="hasPipelineDiagnostics"
            class="admin-card admin-card--glass"
        >
            <h2 class="admin-card__headline">Pipeline diagnostics</h2>
            <div class="pipeline-diagnostics-grid">
                <div class="pipeline-diagnostic-item">
                    <p class="pipeline-diagnostic-label">Active run</p>
                    <p class="pipeline-diagnostic-value">
                        #{{ overview.pipeline_diagnostics.run_id }}
                    </p>
                </div>
                <div class="pipeline-diagnostic-item">
                    <p class="pipeline-diagnostic-label">
                        Pending transcriptions
                    </p>
                    <p class="pipeline-diagnostic-value">
                        {{
                            overview.pipeline_diagnostics.pending_transcriptions
                        }}
                    </p>
                </div>
                <div class="pipeline-diagnostic-item pipeline-diagnostic-item--wide">
                    <p class="pipeline-diagnostic-label">Transcript signal</p>
                    <p class="pipeline-diagnostic-value pipeline-diagnostic-signal">
                        {{ overview.pipeline_diagnostics.transcript_signal || "—" }}
                    </p>
                </div>
                <div class="pipeline-diagnostic-item">
                    <p class="pipeline-diagnostic-label">Split retries</p>
                    <p class="pipeline-diagnostic-value">
                        {{ overview.pipeline_diagnostics.split_retries }}
                    </p>
                </div>
                <div class="pipeline-diagnostic-item">
                    <p class="pipeline-diagnostic-label">Discovery integrity</p>
                    <p
                        class="pipeline-diagnostic-value"
                        :class="
                            overview.pipeline_diagnostics
                                .strict_lossless_discovery
                                ? 'is-healthy'
                                : 'is-risk'
                        "
                    >
                        {{ discoveryIntegrityLabel }}
                    </p>
                </div>
            </div>
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
const startingWorkers = ref(false);
const resumeLoadingById = ref({});
const resumeFeedback = ref({
    type: "info",
    message: "",
});
const error = ref("");
const overview = ref({
    queue_connection: "",
    totals: {},
    queues: [],
    jobs: [],
    failed_jobs: [],
    pipeline_totals: {},
    pipeline_runs: [],
    pipeline_diagnostics: null,
    worker_health: {
        level: "ok",
        has_backlog: false,
        suspected_stalled: false,
        horizon_running: null,
        message: "",
    },
    worker_start_hint: {
        mode: "",
        status_command: "",
        start_command: "",
        restart_command: "",
    },
    horizon_status: {
        enabled: false,
        running: null,
    },
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
    { key: "transcript_signal", label: "Transcript signal" },
    { key: "resume_count", label: "Resumes" },
    { key: "started_at", label: "Started" },
    { key: "updated_at", label: "Updated" },
    { key: "finished_at", label: "Finished" },
    { key: "last_error", label: "Error" },
    { key: "actions", label: "Actions" },
];

const queueMeta = computed(() => (loading.value ? "Loading…" : "Last update"));

const showHorizonStatus = computed(() => {
    return Boolean(overview.value?.horizon_status?.enabled);
});

const horizonStatusLabel = computed(() => {
    const running = overview.value?.horizon_status?.running;
    if (running === true) return "Running";
    if (running === false) return "Stopped";
    return "Unknown";
});

const horizonStatusClass = computed(() => {
    const running = overview.value?.horizon_status?.running;
    if (running === true) return "running";
    if (running === false) return "stopped";
    return "unknown";
});

const showStartWorkersButton = computed(() => {
    return Boolean(
        overview.value?.horizon_status?.enabled &&
        overview.value?.horizon_status?.running !== true,
    );
});

const workerHealthMessage = computed(() => {
    const health = overview.value?.worker_health;
    if (!health || !health.message) {
        return "";
    }

    if (health.level === "warning" || health.level === "error") {
        return health.message;
    }

    return "";
});

const showWorkerStartHint = computed(() => {
    const health = overview.value?.worker_health;
    const hint = overview.value?.worker_start_hint;

    return Boolean(
        health?.suspected_stalled &&
        hint?.start_command &&
        hint?.status_command,
    );
});

const hasPipelineDiagnostics = computed(() => {
    return Boolean(overview.value?.pipeline_diagnostics?.run_id);
});

const discoveryIntegrityLabel = computed(() => {
    const diagnostics = overview.value?.pipeline_diagnostics;
    if (!diagnostics) {
        return "Unknown";
    }

    return diagnostics.strict_lossless_discovery
        ? "Strict-lossless"
        : "Unverified";
});

onMounted(() => {
    load();
});

async function load() {
    loading.value = true;
    error.value = "";
    try {
        const res = await adminApi.get("/jobs/overview");
        overview.value = res?.data?.data ?? overview.value;
        if (!resumeFeedback.value.message) {
            resumeFeedback.value = { type: "info", message: "" };
        }
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
        const res = await adminApi.post(
            `/jobs/pipelines/${pipelineRunId}/resume`,
        );
        const msg = res?.data?.message || "Resume request sent.";
        const alreadyQueued = Boolean(res?.data?.data?.already_queued);
        const requeued = Boolean(res?.data?.data?.requeued);
        const workerHealth = res?.data?.data?.worker_health;
        const workerHint = res?.data?.data?.worker_start_hint;

        if (workerHealth) {
            overview.value = {
                ...overview.value,
                worker_health: workerHealth,
                worker_start_hint:
                    workerHint || overview.value.worker_start_hint,
            };
        }

        resumeFeedback.value = {
            type: alreadyQueued && !requeued ? "info" : "success",
            message: msg,
        };
        await load();
    } catch (e) {
        const apiMessage = e?.response?.data?.message;
        resumeFeedback.value = {
            type: "error",
            message: apiMessage || "Failed to resume selected pipeline.",
        };
        error.value = "Failed to resume selected pipeline.";
    } finally {
        resumeLoadingById.value = {
            ...resumeLoadingById.value,
            [pipelineRunId]: false,
        };
    }
}

async function startWorkers() {
    startingWorkers.value = true;
    error.value = "";

    try {
        const res = await adminApi.post("/jobs/workers/start");
        const msg = res?.data?.message || "Worker start request sent.";
        const workerHealth = res?.data?.data?.worker_health;
        const workerHint = res?.data?.data?.worker_start_hint;
        const horizonRunning = res?.data?.data?.horizon_running;

        resumeFeedback.value = {
            type: horizonRunning === true ? "success" : "info",
            message: msg,
        };

        if (workerHealth || workerHint) {
            overview.value = {
                ...overview.value,
                worker_health: workerHealth || overview.value.worker_health,
                worker_start_hint:
                    workerHint || overview.value.worker_start_hint,
                horizon_status: {
                    ...(overview.value?.horizon_status || {}),
                    running:
                        typeof horizonRunning === "boolean"
                            ? horizonRunning
                            : overview.value?.horizon_status?.running,
                },
            };
        }

        await load();
    } catch (e) {
        const apiMessage = e?.response?.data?.message;
        resumeFeedback.value = {
            type: "error",
            message: apiMessage || "Failed to start workers.",
        };
        error.value = "Failed to start workers.";
    } finally {
        startingWorkers.value = false;
    }
}
</script>

<style scoped>
.header-actions {
    display: flex;
    align-items: center;
    gap: 8px;
}

.horizon-status {
    margin-top: 6px;
    font-size: 13px;
}

.horizon-status.is-running {
    color: #047857;
}

.horizon-status.is-stopped {
    color: #b45309;
}

.horizon-status.is-unknown {
    color: #1d4ed8;
}

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

.pipeline-transcript-signal {
    display: inline-block;
    max-width: 420px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.resume-feedback {
    margin-top: 8px;
    font-size: 13px;
}

.resume-feedback.is-success {
    color: #047857;
}

.resume-feedback.is-error {
    color: #b91c1c;
}

.resume-feedback.is-info {
    color: #1d4ed8;
}

.worker-health {
    margin-top: 6px;
    font-size: 13px;
}

.worker-health.is-warning {
    color: #92400e;
}

.worker-health.is-error {
    color: #b91c1c;
}

.worker-health.is-ok {
    color: #047857;
}

.worker-hint {
    margin-top: 4px;
    font-size: 12px;
    color: #7c2d12;
}

.pipeline-diagnostics-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 12px;
}

.pipeline-diagnostic-item {
    border: 1px solid var(--admin-border, #e5e7eb);
    border-radius: 10px;
    padding: 10px;
    background: var(--admin-surface-2, #f9fafb);
}

.pipeline-diagnostic-item--wide {
    grid-column: span 2;
}

.pipeline-diagnostic-label {
    margin: 0;
    font-size: 12px;
    color: var(--admin-text-muted, #6b7280);
}

.pipeline-diagnostic-value {
    margin: 4px 0 0;
    font-size: 18px;
    font-weight: 700;
}

.pipeline-diagnostic-signal {
    font-size: 14px;
    font-weight: 600;
    line-height: 1.4;
}

.pipeline-diagnostic-value.is-healthy {
    color: #047857;
}

.pipeline-diagnostic-value.is-risk {
    color: #b45309;
}
</style>
