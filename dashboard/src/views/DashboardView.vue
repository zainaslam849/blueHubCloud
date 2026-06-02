<script setup lang="ts">
import { onMounted, ref } from "vue";
import Card from "../components/ui/Card.vue";
import PageHeader from "../components/ui/PageHeader.vue";
import { userApi } from "../api/user";

type MinuteBalance = {
    plan_name: string | null;
    purchased_minutes: number;
    used_minutes: number;
    available_minutes: number;
};

type RecentReport = {
    id: number;
    week_start_date: string;
    week_end_date: string;
    status: string;
    total_calls: number;
    answered_calls: number;
    minutes_consumed: number | null;
    generated_at: string | null;
};

type DashboardData = {
    company: { id: number; name: string; timezone: string; status: string } | null;
    minute_balance: MinuteBalance | null;
    recent_reports: RecentReport[];
    message?: string;
};

const loading = ref(true);
const data = ref<DashboardData | null>(null);
const error = ref<string | null>(null);

async function load() {
    loading.value = true;
    error.value = null;
    try {
        const res = await userApi.get<DashboardData>("/dashboard");
        data.value = res.data;
    } catch (e) {
        error.value = e instanceof Error ? e.message : "Failed to load dashboard.";
    } finally {
        loading.value = false;
    }
}

onMounted(load);

function statusLabel(status: string): string {
    const map: Record<string, string> = {
        completed: "Completed",
        pending: "Pending",
        generating: "Generating",
        failed: "Failed",
        pending_minutes: "Awaiting minutes",
    };
    return map[status] ?? status;
}
</script>

<template>
    <div class="page">
        <PageHeader
            title="Dashboard"
            :description="data?.company ? `Welcome back — ${data.company.name}` : 'Your reporting overview'"
        />

        <div v-if="loading" class="skeleton-row">
            <div class="sk-card"></div>
            <div class="sk-card"></div>
            <div class="sk-card"></div>
        </div>

        <div v-else-if="error" class="errorBanner">{{ error }}</div>

        <div v-else-if="data?.message && !data.company" class="infoBanner">
            {{ data.message }}
        </div>

        <template v-else-if="data">
            <!-- Minute balance summary -->
            <section class="grid3" style="margin-bottom: var(--space-4)">
                <Card title="Available minutes">
                    <div class="bigStat">
                        {{ data.minute_balance ? data.minute_balance.available_minutes.toLocaleString() : '—' }}
                    </div>
                    <div class="statSub" v-if="data.minute_balance">
                        of {{ data.minute_balance.purchased_minutes.toLocaleString() }} purchased ·
                        {{ data.minute_balance.used_minutes.toLocaleString() }} used
                    </div>
                    <div class="statSub" v-else>No plan assigned yet.</div>
                </Card>

                <Card title="Plan">
                    <div class="bigStat">
                        {{ data.minute_balance?.plan_name ?? '—' }}
                    </div>
                    <div class="statSub">Contact admin to purchase more minutes.</div>
                </Card>

                <Card title="Company">
                    <div class="bigStat">{{ data.company?.name ?? '—' }}</div>
                    <div class="statSub">
                        <span :class="data.company?.status === 'active' ? 'ok' : 'warn'">
                            {{ data.company?.status ?? 'unassigned' }}
                        </span>
                    </div>
                </Card>
            </section>

            <!-- Recent reports -->
            <Card title="Recent reports" subtitle="Last 5 reports">
                <div v-if="data.recent_reports.length === 0" class="empty">
                    No reports yet. Reports are generated weekly after calls are processed.
                </div>
                <div v-else class="reportList">
                    <div class="reportRow reportRow--head">
                        <span>Week</span>
                        <span>Calls</span>
                        <span>Minutes</span>
                        <span>Status</span>
                    </div>
                    <div
                        v-for="r in data.recent_reports"
                        :key="r.id"
                        class="reportRow"
                    >
                        <span>{{ r.week_start_date }} → {{ r.week_end_date }}</span>
                        <span>{{ r.answered_calls }} / {{ r.total_calls }}</span>
                        <span>{{ r.minutes_consumed ?? '—' }}</span>
                        <span :class="r.status === 'completed' ? 'ok' : r.status === 'pending_minutes' ? 'warn' : ''">
                            {{ statusLabel(r.status) }}
                        </span>
                    </div>
                </div>
            </Card>
        </template>
    </div>
</template>

<style scoped>
.grid3 {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: var(--space-4);
}

.bigStat {
    font-size: 1.6rem;
    font-weight: 800;
    line-height: 1.2;
}

.statSub {
    margin-top: 6px;
    opacity: 0.7;
    font-size: 0.88rem;
}

.ok { color: #1f9d55; }
.warn { color: #b7791f; }

.errorBanner, .infoBanner {
    border-radius: 10px;
    padding: var(--space-4);
    margin-bottom: var(--space-4);
}

.errorBanner {
    background: color-mix(in srgb, #e53e3e 12%, transparent);
    border: 1px solid #e53e3e;
    color: #e53e3e;
}

.infoBanner {
    background: color-mix(in srgb, var(--color-primary) 10%, transparent);
    border: 1px solid var(--border);
}

.skeleton-row {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: var(--space-4);
    margin-bottom: var(--space-4);
}

.sk-card {
    height: 100px;
    border-radius: 12px;
    background: color-mix(in srgb, var(--color-text) 8%, transparent);
}

.reportList {
    display: grid;
    gap: 8px;
}

.reportRow {
    display: grid;
    grid-template-columns: 2fr 1fr 1fr 1fr;
    gap: var(--space-3);
    padding: 10px 12px;
    border: 1px solid var(--border);
    border-radius: 8px;
    font-size: 0.9rem;
}

.reportRow--head {
    font-weight: 700;
    background: var(--surface-2);
}

.empty {
    opacity: 0.65;
    font-size: 0.9rem;
}

@media (max-width: 960px) {
    .grid3 { grid-template-columns: 1fr; }
    .reportRow { grid-template-columns: 1fr; }
    .reportRow--head { display: none; }
}
</style>
