<template>
    <div class="admin-container admin-dashboard">
        <header class="admin-dashboard__header">
            <div>
                <p class="admin-dashboard__kicker">Overview</p>
                <h1 class="admin-dashboard__title">Admin Dashboard</h1>
            </div>

            <div class="admin-dashboard__status">
                <StatusBadge
                    v-for="b in headerBadges"
                    :key="b.key"
                    :variant="b.variant"
                >
                    {{ b.label }}
                </StatusBadge>
            </div>
        </header>

        <section class="admin-kpiGrid" aria-label="Key performance indicators">
            <template v-if="loading">
                <KpiCardSkeleton v-for="i in 3" :key="i" />
            </template>

            <template v-else-if="kpis.length === 0">
                <EmptyState
                    title="No KPI data yet"
                    description="Once calls, jobs, and users are tracked, the overview cards will appear here."
                />
            </template>

            <template v-else>
                <KpiCard
                    v-for="card in kpis"
                    :key="card.key"
                    :label="card.label"
                    :value="card.value"
                    :hint="card.hint"
                    :badge-label="card.badgeLabel"
                    :badge-variant="card.badgeVariant"
                    :icon="card.icon"
                />
            </template>
        </section>

        <PanelCard title="Quick actions" meta="Test pipeline">
            <div
                style="
                    display: flex;
                    flex-wrap: wrap;
                    gap: 12px;
                    align-items: center;
                "
            >
                <BaseButton
                    variant="primary"
                    size="sm"
                    :loading="pipelineRunning"
                    :disabled="pipelineRunning"
                    @click="runPipeline"
                >
                    <template v-if="pipelineRunning">Running…</template>
                    <template v-else>Run full AI pipeline</template>
                </BaseButton>
                <span v-if="pipelineSuccess" class="admin-muted">
                    {{ pipelineSuccess }}
                </span>
                <span v-if="pipelineError" class="admin-error">
                    {{ pipelineError }}
                </span>
            </div>
            <div
                style="
                    display: grid;
                    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
                    gap: 12px;
                    margin-top: 12px;
                "
            >
                <div>
                    <label class="admin-label"
                        >Company <span style="color: #e74c3c">*</span></label
                    >
                    <select
                        v-model="pipelineCompanyId"
                        class="admin-input"
                        required
                    >
                        <option value="">-- Select Company --</option>
                        <option
                            v-for="company in companies"
                            :key="company.id"
                            :value="company.id"
                        >
                            {{ company.name }}
                        </option>
                    </select>
                </div>
                <div>
                    <label class="admin-label">Range (days)</label>
                    <input
                        v-model.number="pipelineRangeDays"
                        type="number"
                        min="1"
                        max="365"
                        class="admin-input"
                    />
                </div>
                <div>
                    <label class="admin-label">Summary limit</label>
                    <input
                        v-model.number="pipelineSummarizeLimit"
                        type="number"
                        min="1"
                        max="5000"
                        class="admin-input"
                    />
                </div>
                <div>
                    <label class="admin-label">Categorize limit</label>
                    <input
                        v-model.number="pipelineCategorizeLimit"
                        type="number"
                        min="1"
                        max="5000"
                        class="admin-input"
                    />
                </div>
            </div>
            <p class="admin-muted" style="margin-top: 10px">
                This queues ingest → summaries → category generation →
                categorization → reports. Keep workers running to process jobs.
            </p>
        </PanelCard>

        <PanelCard title="System status" meta="Live health checks">
            <div
                v-if="systemStatusLoading"
                class="admin-muted"
                style="padding: 6px 0"
            >
                Checking system health…
            </div>
            <div v-else class="admin-metricRows">
                <MetricRow
                    label="Scheduler"
                    :value="
                        systemStatus?.scheduler?.ok ? 'Running' : 'Not running'
                    "
                    :status-label="
                        systemStatus?.scheduler?.ok ? 'Active' : 'Failed'
                    "
                    :status-variant="
                        systemStatus?.scheduler?.ok ? 'active' : 'failed'
                    "
                />
                <MetricRow
                    label="Queue worker"
                    :value="
                        systemStatus?.queue_worker?.ok
                            ? 'Running'
                            : 'Not running'
                    "
                    :status-label="
                        systemStatus?.queue_worker?.ok ? 'Active' : 'Failed'
                    "
                    :status-variant="
                        systemStatus?.queue_worker?.ok ? 'active' : 'failed'
                    "
                />
                <MetricRow
                    label="PBX ingest"
                    :value="
                        systemStatus?.pbx_ingest_enabled
                            ? 'Enabled'
                            : 'Disabled'
                    "
                    :status-label="
                        systemStatus?.pbx_ingest_enabled ? 'Active' : 'Failed'
                    "
                    :status-variant="
                        systemStatus?.pbx_ingest_enabled ? 'active' : 'failed'
                    "
                />
                <MetricRow
                    label="AI settings"
                    :value="
                        systemStatus?.ai_settings_enabled
                            ? 'Enabled'
                            : 'Disabled'
                    "
                    :status-label="
                        systemStatus?.ai_settings_enabled ? 'Active' : 'Failed'
                    "
                    :status-variant="
                        systemStatus?.ai_settings_enabled ? 'active' : 'failed'
                    "
                />
                <MetricRow
                    label="Report AI"
                    :value="
                        systemStatus?.reports_ai_enabled
                            ? 'Enabled'
                            : 'Disabled'
                    "
                    :status-label="
                        systemStatus?.reports_ai_enabled ? 'Active' : 'Failed'
                    "
                    :status-variant="
                        systemStatus?.reports_ai_enabled ? 'active' : 'failed'
                    "
                />
            </div>
        </PanelCard>

        <section class="admin-dashboard__grid">
            <PanelCard title="Queue health" :meta="queueMeta">
                <template v-if="loading">
                    <div class="admin-metricRows">
                        <MetricRowSkeleton v-for="i in 3" :key="i" />
                    </div>
                </template>

                <template v-else-if="queueMetrics.length === 0">
                    <EmptyState
                        title="No queue telemetry"
                        description="When workers and jobs are running, live queue health will show up here."
                    />
                </template>

                <template v-else>
                    <div class="admin-metricRows">
                        <MetricRow
                            v-for="row in queueMetrics"
                            :key="row.key"
                            :label="row.label"
                            :value="row.value"
                            :status-label="row.statusLabel"
                            :status-variant="row.statusVariant"
                        />
                    </div>
                </template>
            </PanelCard>

            <PanelCard title="Recent activity" meta="Latest events">
                <template v-if="loading">
                    <div class="admin-activity">
                        <ActivityRowSkeleton v-for="i in 3" :key="i" />
                    </div>
                </template>

                <template v-else-if="recentActivity.length === 0">
                    <EmptyState
                        title="No activity yet"
                        description="Once calls and jobs flow through the system, you’ll see the latest events here."
                    />
                </template>

                <template v-else>
                    <div class="admin-activity">
                        <ActivityRow
                            v-for="row in recentActivity"
                            :key="row.id"
                            :title="row.title"
                            :sub="row.sub"
                            :status-label="row.statusLabel"
                            :status-variant="row.statusVariant"
                            :time="row.time"
                        />
                    </div>
                </template>
            </PanelCard>
        </section>

        <p v-if="error" class="admin-dashboard__error" role="status">
            {{ error }}
        </p>
    </div>
</template>

<script setup>
import { computed, onMounted, ref } from "vue";

import ActivityRow from "../../components/admin/ActivityRow.vue";
import KpiCard from "../../components/admin/KpiCard.vue";
import MetricRow from "../../components/admin/MetricRow.vue";
import PanelCard from "../../components/admin/PanelCard.vue";
import StatusBadge from "../../components/admin/StatusBadge.vue";

import EmptyState from "../../components/admin/EmptyState.vue";
import ActivityRowSkeleton from "../../components/admin/dashboard/ActivityRowSkeleton.vue";
import KpiCardSkeleton from "../../components/admin/dashboard/KpiCardSkeleton.vue";
import MetricRowSkeleton from "../../components/admin/dashboard/MetricRowSkeleton.vue";
import { BaseButton } from "../../components/admin/base";

import { useAdminDashboard } from "../../composables/admin/useAdminDashboard";
import adminApi from "../../router/admin/api";

const {
    loading,
    error,
    load,
    kpis,
    headerBadges,
    queueMetrics,
    recentActivity,
} = useAdminDashboard();

const pipelineRunning = ref(false);
const pipelineError = ref("");
const pipelineSuccess = ref("");
const companies = ref([]);
const pipelineCompanyId = ref("");
const pipelineRangeDays = ref(30);
const pipelineSummarizeLimit = ref(500);
const pipelineCategorizeLimit = ref(500);
const systemStatus = ref(null);
const systemStatusLoading = ref(false);

onMounted(() => {
    load();
    loadCompanies();
    loadSystemStatus();
});

const queueMeta = computed(() =>
    loading.value ? "Loading…" : "Last 15 minutes",
);

async function runPipeline() {
    pipelineRunning.value = true;
    pipelineError.value = "";
    pipelineSuccess.value = "";

    // Validate company selection
    if (!pipelineCompanyId.value) {
        pipelineError.value = "Please select a company first.";
        pipelineRunning.value = false;
        return;
    }

    try {
        const payload = {
            company_id: parseInt(pipelineCompanyId.value),
            range_days: pipelineRangeDays.value,
            summarize_limit: pipelineSummarizeLimit.value,
            categorize_limit: pipelineCategorizeLimit.value,
        };

        const res = await adminApi.post("/pipeline/run", payload);
        const message = res?.data?.message || "Pipeline queued.";
        pipelineSuccess.value = message;
    } catch (e) {
        pipelineError.value =
            e?.response?.data?.message || "Failed to queue pipeline.";
    } finally {
        pipelineRunning.value = false;
    }
}

async function loadCompanies() {
    try {
        const res = await adminApi.get("/companies");
        companies.value = res?.data?.data ?? [];
    } catch (e) {
        companies.value = [];
    }
}

async function loadSystemStatus() {
    systemStatusLoading.value = true;
    try {
        const res = await adminApi.get("/system/status");
        systemStatus.value = res?.data?.data ?? null;
    } catch (e) {
        systemStatus.value = null;
    } finally {
        systemStatusLoading.value = false;
    }
}
</script>
