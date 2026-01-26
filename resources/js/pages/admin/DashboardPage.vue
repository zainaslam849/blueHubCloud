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
import { computed, onMounted } from "vue";

import ActivityRow from "../../components/admin/ActivityRow.vue";
import KpiCard from "../../components/admin/KpiCard.vue";
import MetricRow from "../../components/admin/MetricRow.vue";
import PanelCard from "../../components/admin/PanelCard.vue";
import StatusBadge from "../../components/admin/StatusBadge.vue";

import EmptyState from "../../components/admin/EmptyState.vue";
import ActivityRowSkeleton from "../../components/admin/dashboard/ActivityRowSkeleton.vue";
import KpiCardSkeleton from "../../components/admin/dashboard/KpiCardSkeleton.vue";
import MetricRowSkeleton from "../../components/admin/dashboard/MetricRowSkeleton.vue";

import { useAdminDashboard } from "../../composables/admin/useAdminDashboard";

const {
    loading,
    error,
    load,
    kpis,
    headerBadges,
    queueMetrics,
    recentActivity,
} = useAdminDashboard();

onMounted(() => {
    load();
});

const queueMeta = computed(() =>
    loading.value ? "Loading…" : "Last 15 minutes",
);
</script>
