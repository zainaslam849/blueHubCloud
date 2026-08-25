<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import Card from "../components/ui/Card.vue";
import PageHeader from "../components/ui/PageHeader.vue";
import { userApi } from "../api/user";

type CallLimit = {
    monthly_call_limit: number | null;
    call_limit_used: number;
    remaining: number | null;
    expires_at: string | null;
    period_completed: boolean;
};

type WeeklyHistory = {
    id: number;
    week_start_date: string;
    week_end_date: string;
    calls_available: number;
    calls_fetched: number;
    calls_blocked: number;
    status: string; // complete | partial | paused
    report_available: boolean;
};

type RecentReport = {
    id: number;
    week_start_date: string;
    week_end_date: string;
    status: string;
    total_calls: number;
    answered_calls: number;
    generated_at: string | null;
};

type DashboardData = {
    company: { id: number; name: string; timezone: string; status: string } | null;
    call_limit: CallLimit | null;
    weekly_history: WeeklyHistory[];
    recent_reports: RecentReport[];
    message?: string;
};

const loading = ref(true);
const data = ref<DashboardData | null>(null);
const error = ref<string | null>(null);
const processingId = ref<number | null>(null);
const toast = ref<string | null>(null);
let toastTimer: ReturnType<typeof setTimeout> | null = null;

const usagePct = computed(() => {
    const cl = data.value?.call_limit;
    if (!cl || cl.monthly_call_limit == null || cl.monthly_call_limit === 0) return 0;
    return Math.min(100, Math.round((cl.call_limit_used / cl.monthly_call_limit) * 100));
});

function showToast(msg: string) {
    toast.value = msg;
    if (toastTimer) clearTimeout(toastTimer);
    toastTimer = setTimeout(() => { toast.value = null; }, 3500);
}

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

// Smart button per week: action depends on state.
function weekAction(w: WeeklyHistory): { label: string; kind: "view" | "process" | "done" | "renew" | "credits"; disabled: boolean } {
    if (w.report_available && w.calls_blocked === 0 && w.status === "complete") {
        return { label: "Already available", kind: "done", disabled: true };
    }
    if (w.status === "insufficient_credits") {
        return { label: "Add Credits", kind: "credits", disabled: false };
    }
    if (w.status === "paused") {
        return { label: "Renew needed", kind: "renew", disabled: true };
    }
    if (w.calls_blocked > 0) {
        return { label: `Process remaining (${w.calls_blocked})`, kind: "process", disabled: false };
    }
    // Fetched something but no report yet, or nothing fetched — allow (re)processing.
    return { label: w.calls_fetched > 0 ? "Reprocess week" : "Fetch & process", kind: "process", disabled: false };
}

async function processWeek(w: WeeklyHistory) {
    if (processingId.value) return;
    processingId.value = w.id;
    try {
        const res = await userApi.post<{ message: string; status: string }>(
            `/weekly-fetches/${w.id}/process-remaining`,
        );
        showToast(res.data.message);
        await load();
    } catch (e) {
        const msg = (e as { response?: { data?: { message?: string } } })?.response?.data?.message;
        showToast(msg ?? "Could not start processing. Please try again.");
    } finally {
        processingId.value = null;
    }
}

function fmtDate(iso: string | null): string {
    if (!iso) return "—";
    return new Date(iso).toLocaleDateString(undefined, { month: "short", day: "numeric" });
}

function statusLabel(status: string): string {
    const map: Record<string, string> = {
        completed: "Completed", pending: "Pending", generating: "Generating",
        failed: "Failed", complete: "Complete", partial: "Partial", paused: "Paused",
        insufficient_credits: "Needs credits",
    };
    return map[status] ?? status;
}

onMounted(load);
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

        <div v-else-if="data?.message && !data.company" class="infoBanner">{{ data.message }}</div>

        <template v-else-if="data">
            <!-- Call limit summary -->
            <section class="grid3" style="margin-bottom: var(--space-4)">
                <Card title="Calls remaining">
                    <div class="bigStat">
                        {{ data.call_limit && data.call_limit.monthly_call_limit != null
                            ? (data.call_limit.remaining ?? 0).toLocaleString()
                            : '∞' }}
                    </div>
                    <div class="statSub" v-if="data.call_limit && data.call_limit.monthly_call_limit != null">
                        {{ data.call_limit.call_limit_used.toLocaleString() }} of
                        {{ data.call_limit.monthly_call_limit.toLocaleString() }} used
                    </div>
                    <div class="statSub" v-else>Unlimited plan.</div>
                    <div v-if="data.call_limit && data.call_limit.monthly_call_limit != null" class="bar">
                        <div class="bar__fill" :style="{ width: usagePct + '%' }" :class="{ 'bar__fill--full': usagePct >= 100 }"></div>
                    </div>
                </Card>

                <Card title="Limit period">
                    <div class="bigStat bigStat--sm">
                        {{ data.call_limit?.expires_at ? fmtDate(data.call_limit.expires_at) : '—' }}
                    </div>
                    <div class="statSub">
                        <span v-if="data.call_limit?.period_completed" class="warn">Period ended — contact admin to renew</span>
                        <span v-else>Resets on expiry date</span>
                    </div>
                </Card>

                <Card title="Company">
                    <div class="bigStat bigStat--sm">{{ data.company?.name ?? '—' }}</div>
                    <div class="statSub">
                        <span :class="data.company?.status === 'active' ? 'ok' : 'warn'">
                            {{ data.company?.status ?? 'unassigned' }}
                        </span>
                    </div>
                </Card>
            </section>

            <!-- Limit reached / period ended banner -->
            <div
                v-if="data.call_limit && (data.call_limit.period_completed || (data.call_limit.monthly_call_limit != null && (data.call_limit.remaining ?? 0) <= 0))"
                class="warnBanner"
            >
                <strong>{{ data.call_limit.period_completed ? 'Your limit period has ended.' : 'You have reached your monthly call limit.' }}</strong>
                Contact your administrator to {{ data.call_limit.period_completed ? 'renew your plan' : 'increase your limit' }} so blocked weeks can be processed.
            </div>

            <!-- Weekly usage history -->
            <Card title="Weekly usage history" subtitle="Calls fetched per week and pending work">
                <div v-if="data.weekly_history.length === 0" class="empty">
                    No weekly activity yet. The weekly pipeline runs automatically; processed weeks will appear here.
                </div>
                <div v-else class="histList">
                    <div class="histRow histRow--head">
                        <span>Week</span>
                        <span>Fetched</span>
                        <span>Blocked</span>
                        <span>Status</span>
                        <span>Action</span>
                    </div>
                    <div v-for="w in data.weekly_history" :key="w.id" class="histRow">
                        <span>{{ fmtDate(w.week_start_date) }} → {{ fmtDate(w.week_end_date) }}</span>
                        <span>{{ w.calls_fetched.toLocaleString() }}<span class="muted" v-if="w.calls_available"> / {{ w.calls_available.toLocaleString() }}</span></span>
                        <span :class="w.calls_blocked > 0 ? 'warn' : ''">{{ w.calls_blocked.toLocaleString() }}</span>
                        <span><span class="pill" :class="`pill--${w.status}`">{{ statusLabel(w.status) }}</span></span>
                        <span>
                            <template v-if="weekAction(w).kind === 'done'">
                                <RouterLink :to="{ name: 'reports' }" class="histLink">View reports</RouterLink>
                            </template>
                            <template v-else-if="weekAction(w).kind === 'credits'">
                                <span class="histActions">
                                    <RouterLink :to="{ name: 'select-plan' }" class="histBtn histBtn--credits">
                                        Add Credits
                                    </RouterLink>
                                    <button
                                        class="histBtn"
                                        :disabled="processingId === w.id"
                                        @click="processWeek(w)"
                                    >
                                        {{ processingId === w.id ? 'Checking…' : 'Run Again' }}
                                    </button>
                                </span>
                            </template>
                            <template v-else>
                                <button
                                    class="histBtn"
                                    :class="{ 'histBtn--muted': weekAction(w).disabled }"
                                    :disabled="weekAction(w).disabled || processingId === w.id"
                                    @click="processWeek(w)"
                                >
                                    {{ processingId === w.id ? 'Starting…' : weekAction(w).label }}
                                </button>
                            </template>
                        </span>
                    </div>
                    <div v-if="data.weekly_history.some((w) => w.status === 'insufficient_credits')" class="creditsBanner">
                        Some weeks weren't processed because your company ran out of credits.
                        <RouterLink :to="{ name: 'select-plan' }">Add credits</RouterLink>
                        then click "Run Again" below (or wait for the pipeline to run automatically) to catch up.
                    </div>
                </div>
            </Card>

            <!-- Recent reports -->
            <Card title="Recent reports" subtitle="Last 5 weekly call reports" style="margin-top: var(--space-4)">
                <div v-if="data.recent_reports.length === 0" class="empty">
                    No reports yet. Reports are generated weekly after calls are processed.
                </div>
                <div v-else class="reportList">
                    <div class="reportRow reportRow--head">
                        <span>Week</span>
                        <span>Calls</span>
                        <span>Status</span>
                    </div>
                    <RouterLink
                        v-for="r in data.recent_reports"
                        :key="r.id"
                        :to="{ name: 'report-detail', params: { id: r.id } }"
                        class="reportRow reportRow--link"
                    >
                        <span>{{ r.week_start_date }} → {{ r.week_end_date }}</span>
                        <span>{{ r.answered_calls }} / {{ r.total_calls }}</span>
                        <span :class="r.status === 'completed' ? 'ok' : ''">{{ statusLabel(r.status) }}</span>
                    </RouterLink>
                </div>
            </Card>
        </template>

        <Transition name="toast">
            <div v-if="toast" class="toast">{{ toast }}</div>
        </Transition>
    </div>
</template>

<style scoped>
.grid3 { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: var(--space-4); }
.bigStat { font-size: 1.6rem; font-weight: 800; line-height: 1.2; }
.bigStat--sm { font-size: 1.15rem; }
.statSub { margin-top: 6px; opacity: 0.7; font-size: 0.88rem; }
.ok { color: #1f9d55; }
.warn { color: #b7791f; }

.bar { margin-top: 10px; height: 6px; border-radius: 999px; background: var(--surface-2); overflow: hidden; }
.bar__fill { height: 100%; border-radius: 999px; background: var(--color-primary, #3b82f6); transition: width 0.3s; }
.bar__fill--full { background: #ef4444; }

.errorBanner, .infoBanner, .warnBanner {
    border-radius: 10px; padding: var(--space-4); margin-bottom: var(--space-4);
}
.errorBanner { background: color-mix(in srgb, #e53e3e 12%, transparent); border: 1px solid #e53e3e; color: #e53e3e; }
.infoBanner { background: color-mix(in srgb, var(--color-primary) 10%, transparent); border: 1px solid var(--border); }
.warnBanner { background: color-mix(in srgb, #f59e0b 12%, transparent); border: 1px solid color-mix(in srgb, #f59e0b 40%, transparent); font-size: 0.9rem; line-height: 1.5; }

.skeleton-row { display: grid; grid-template-columns: repeat(3, 1fr); gap: var(--space-4); margin-bottom: var(--space-4); }
.sk-card { height: 100px; border-radius: 12px; background: color-mix(in srgb, var(--color-text) 8%, transparent); }

.histList, .reportList { display: grid; gap: 8px; }
.histRow {
    display: grid; grid-template-columns: 2fr 1fr 1fr 1.1fr 1.6fr; gap: var(--space-3);
    padding: 10px 12px; border: 1px solid var(--border); border-radius: 8px; font-size: 0.9rem; align-items: center;
}
.histRow--head { font-weight: 700; background: var(--surface-2); }
.muted { opacity: 0.5; }

.pill { padding: 2px 9px; border-radius: 999px; font-size: 0.72rem; font-weight: 600; }
.pill--complete { background: color-mix(in srgb, #10b981 14%, transparent); color: #059669; }
.pill--partial { background: color-mix(in srgb, #f59e0b 16%, transparent); color: #b45309; }
.pill--paused { background: color-mix(in srgb, #ef4444 14%, transparent); color: #dc2626; }
.pill--insufficient_credits { background: color-mix(in srgb, #ef4444 14%, transparent); color: #dc2626; }

.histBtn {
    height: 30px; padding: 0 12px; border-radius: 7px; cursor: pointer; font-size: 0.8rem; font-weight: 600;
    background: var(--color-primary, #3b82f6); color: #fff; border: none; white-space: nowrap;
    display: inline-flex; align-items: center; justify-content: center; text-decoration: none;
}
.histBtn:hover:not(:disabled) { filter: brightness(1.05); }
.histBtn:disabled { opacity: 0.5; cursor: not-allowed; }
.histBtn--muted { background: var(--surface-2); color: var(--color-text); }
.histBtn--credits { background: #dc2626; }
.histLink { font-size: 0.82rem; font-weight: 600; color: var(--color-primary, #3b82f6); text-decoration: none; }
.histLink:hover { text-decoration: underline; }

.histActions { display: flex; gap: 6px; flex-wrap: wrap; }

.creditsBanner {
    margin-top: 4px; padding: 10px 12px; border-radius: 8px; font-size: 0.85rem;
    background: color-mix(in srgb, #ef4444 10%, transparent); border: 1px solid color-mix(in srgb, #ef4444 30%, transparent);
}
.creditsBanner a { color: var(--color-primary, #3b82f6); font-weight: 600; }

.reportRow { display: grid; grid-template-columns: 2fr 1fr 1fr; gap: var(--space-3); padding: 10px 12px; border: 1px solid var(--border); border-radius: 8px; font-size: 0.9rem; }
.reportRow--head { font-weight: 700; background: var(--surface-2); }
.reportRow--link { text-decoration: none; color: inherit; transition: background 0.12s; }
.reportRow--link:hover { background: var(--surface-2); }

.empty { opacity: 0.65; font-size: 0.9rem; }

.toast {
    position: fixed; bottom: 24px; left: 50%; transform: translateX(-50%);
    background: var(--color-text); color: #fff; padding: 11px 18px; border-radius: 10px;
    font-size: 0.85rem; font-weight: 500; box-shadow: 0 8px 24px rgba(0,0,0,.25); z-index: 9999; max-width: 90vw;
}
.toast-enter-active, .toast-leave-active { transition: opacity .2s, transform .2s; }
.toast-enter-from, .toast-leave-to { opacity: 0; transform: translateX(-50%) translateY(10px); }

@media (max-width: 960px) {
    .grid3 { grid-template-columns: 1fr; }
    .histRow, .reportRow { grid-template-columns: 1fr 1fr; }
    .histRow--head, .reportRow--head { display: none; }
}
</style>
