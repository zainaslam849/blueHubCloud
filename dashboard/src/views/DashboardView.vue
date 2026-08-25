<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { userApi } from "../api/user";
import { auth } from "../composables/useAuth";
import { useCreditBalance } from "../composables/useCreditBalance";

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
    status: string; // complete | partial | paused | insufficient_credits
    report_available: boolean;
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
    category_count: number;
};

type TopCategory = { name: string; count: number; percent: number };

type DashboardData = {
    company: { id: number; name: string; timezone: string; status: string } | null;
    call_limit: CallLimit | null;
    weekly_history: WeeklyHistory[];
    recent_reports: RecentReport[];
    credit_balance: number;
    top_categories: TopCategory[];
    message?: string;
};

const { state: creditState, refresh: refreshCredits } = useCreditBalance();

const loading = ref(true);
const data = ref<DashboardData | null>(null);
const error = ref<string | null>(null);
const processingId = ref<number | null>(null);
const toast = ref<string | null>(null);
let toastTimer: ReturnType<typeof setTimeout> | null = null;

const firstName = computed(() => (auth.state.user?.name ?? "").split(/\s+/)[0] || "there");

const greeting = computed(() => {
    const hour = new Date().getHours();
    if (hour < 12) return "Good morning";
    if (hour < 18) return "Good afternoon";
    return "Good evening";
});

const callsAnalysedThisWeek = computed(() => {
    const latest = data.value?.weekly_history?.[0];
    return latest?.calls_fetched ?? 0;
});

const callsAnalysedTrend = computed(() => {
    const hist = data.value?.weekly_history ?? [];
    if (hist.length < 2) return null;
    const latest = hist[0];
    const prev = hist[1];
    if (!latest || !prev || !prev.calls_fetched) return null;
    const change = ((latest.calls_fetched - prev.calls_fetched) / prev.calls_fetched) * 100;
    return Math.round(change * 10) / 10;
});

const minutesTranscribed = computed(() => data.value?.recent_reports?.[0]?.minutes_consumed ?? null);

const reportsReadyCount = computed(
    () => data.value?.recent_reports?.filter((r) => r.status === "completed").length ?? 0,
);

const setupSteps = computed(() => {
    const company = data.value?.company;
    return [
        { key: "connected", label: "Phone system connected", done: company?.status === "active" },
        { key: "report", label: "First report generated", done: (data.value?.recent_reports?.length ?? 0) > 0 },
        { key: "topup", label: "Turn on auto top-up", done: creditState.autoTopupEnabled, to: { name: "billing" } },
    ];
});
const setupDoneCount = computed(() => setupSteps.value.filter((s) => s.done).length);

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

onMounted(() => {
    load();
    refreshCredits();
});
</script>

<template>
    <div class="page">
        <template v-if="loading">
            <!-- Header skeleton -->
            <div class="dashHead">
                <div>
                    <div class="sk sk-title"></div>
                    <div class="sk sk-sub"></div>
                </div>
                <div class="sk sk-btn"></div>
            </div>

            <!-- Stat cards skeleton -->
            <section class="statGrid">
                <div class="statCard" v-for="i in 4" :key="i">
                    <div class="sk sk-label"></div>
                    <div class="sk sk-value"></div>
                    <div class="sk sk-hint"></div>
                </div>
            </section>

            <!-- Setup strip skeleton -->
            <div class="setupStrip">
                <div class="setupStrip__label">
                    <div class="sk sk-label" style="width: 110px"></div>
                    <div class="sk sk-hint" style="width: 60px; margin-top: 6px"></div>
                </div>
                <div class="setupStrip__steps">
                    <div class="sk sk-step" v-for="i in 3" :key="i"></div>
                </div>
            </div>

            <!-- Two-column body skeleton -->
            <div class="dashGrid">
                <div class="panel">
                    <div class="panel__head">
                        <div>
                            <div class="sk sk-label" style="width: 130px"></div>
                            <div class="sk sk-hint" style="width: 180px; margin-top: 6px"></div>
                        </div>
                    </div>
                    <div class="histList">
                        <div class="histRow" v-for="i in 4" :key="i">
                            <div class="sk sk-cell"></div>
                            <div class="sk sk-cell sk-cell--sm"></div>
                            <div class="sk sk-cell sk-cell--sm"></div>
                            <div class="sk sk-pill"></div>
                            <div class="sk sk-cell sk-cell--btn"></div>
                        </div>
                    </div>
                </div>

                <div class="dashSide">
                    <div class="panel">
                        <div class="sk sk-label" style="width: 140px"></div>
                        <div class="sk sk-hint" style="width: 170px; margin-top: 6px; margin-bottom: 14px"></div>
                        <div class="catList">
                            <div class="catRow" v-for="i in 4" :key="i">
                                <div class="sk sk-cell" style="margin-bottom: 6px"></div>
                                <div class="sk sk-bar"></div>
                            </div>
                        </div>
                    </div>
                    <div class="panel">
                        <div class="sk sk-label" style="width: 110px; margin-bottom: 12px"></div>
                        <div class="reportList">
                            <div class="reportRow" v-for="i in 3" :key="i">
                                <div class="sk sk-icon"></div>
                                <div style="flex: 1">
                                    <div class="sk sk-cell" style="width: 60%; margin-bottom: 5px"></div>
                                    <div class="sk sk-cell sk-cell--sm" style="width: 40%"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </template>

        <div v-else-if="error" class="errorBanner">{{ error }}</div>

        <div v-else-if="data?.message && !data.company" class="infoBanner">{{ data.message }}</div>

        <template v-else-if="data">
            <!-- Header -->
            <div class="dashHead">
                <div>
                    <h1 class="dashHead__title">{{ greeting }}, {{ firstName }}</h1>
                    <p class="dashHead__sub">Here's what happened across your phone lines last week.</p>
                </div>
                <RouterLink :to="{ name: 'select-plan' }" class="dashHead__cta">
                    <svg viewBox="0 0 24 24" fill="none"><path d="M12 5v14m7-7H5" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/></svg>
                    Buy credits
                </RouterLink>
            </div>

            <!-- Stat cards -->
            <section class="statGrid">
                <div class="statCard">
                    <div class="statCard__label">
                        <svg viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="8.4" stroke="currentColor" stroke-width="1.9"/><path d="M12 8.3v7.4M8.3 12h7.4" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/></svg>
                        Credit balance
                    </div>
                    <div class="statCard__value">{{ data.credit_balance.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }}</div>
                    <div class="statCard__hint" :class="creditState.autoTopupEnabled ? 'statCard__hint--ok' : 'statCard__hint--warn'">
                        Auto top-up is {{ creditState.autoTopupEnabled ? 'on' : 'off' }}
                    </div>
                </div>

                <div class="statCard">
                    <div class="statCard__label">
                        <svg viewBox="0 0 24 24" fill="none"><path d="M5 4h4l2 5-2.5 1.5a12 12 0 0 0 5 5L15 13l5 2v4a1.6 1.6 0 0 1-1.8 1.6A16.5 16.5 0 0 1 3.4 5.8 1.6 1.6 0 0 1 5 4Z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/></svg>
                        Calls analysed
                    </div>
                    <div class="statCard__value">{{ callsAnalysedThisWeek.toLocaleString() }}</div>
                    <div v-if="callsAnalysedTrend !== null" class="statCard__hint" :class="callsAnalysedTrend >= 0 ? 'statCard__hint--ok' : 'statCard__hint--warn'">
                        {{ callsAnalysedTrend >= 0 ? '▲' : '▼' }} {{ Math.abs(callsAnalysedTrend) }}% vs last week
                    </div>
                    <div v-else class="statCard__hint">This week so far</div>
                </div>

                <div class="statCard">
                    <div class="statCard__label">
                        <svg viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="8.6" stroke="currentColor" stroke-width="1.7"/><path d="M12 7.4V12l3 1.9" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>
                        Minutes transcribed
                    </div>
                    <div class="statCard__value">{{ minutesTranscribed != null ? minutesTranscribed.toLocaleString() : '—' }}</div>
                    <div class="statCard__hint">Latest completed report</div>
                </div>

                <div class="statCard">
                    <div class="statCard__label">
                        <svg viewBox="0 0 24 24" fill="none"><path d="M5 20V10m4.7 10V4m4.6 16v-7m4.7 7V8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                        Reports ready
                    </div>
                    <div class="statCard__value">{{ reportsReadyCount }}</div>
                    <RouterLink v-if="reportsReadyCount > 0" :to="{ name: 'reports' }" class="statCard__hint statCard__hint--link">View latest report →</RouterLink>
                    <div v-else class="statCard__hint">None yet</div>
                </div>
            </section>

            <!-- Setup checklist -->
            <div v-if="setupDoneCount < setupSteps.length" class="setupStrip">
                <div class="setupStrip__label">
                    <div class="setupStrip__title">Finish setting up</div>
                    <div class="setupStrip__sub">{{ setupDoneCount }} of {{ setupSteps.length }} done</div>
                </div>
                <div class="setupStrip__steps">
                    <template v-for="step in setupSteps" :key="step.key">
                        <RouterLink
                            v-if="!step.done && step.to"
                            :to="step.to"
                            class="setupStep setupStep--todo"
                        >
                            <svg viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="8.6" stroke="currentColor" stroke-width="1.8" stroke-dasharray="2.6 2.6"/></svg>
                            <span>{{ step.label }}</span>
                        </RouterLink>
                        <div v-else class="setupStep" :class="step.done ? 'setupStep--done' : 'setupStep--todo'">
                            <svg v-if="step.done" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" fill="currentColor"/><path d="m8 12.2 2.7 2.6L16 9.5" stroke="var(--color-surface)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            <svg v-else viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="8.6" stroke="currentColor" stroke-width="1.8" stroke-dasharray="2.6 2.6"/></svg>
                            <span>{{ step.label }}</span>
                        </div>
                    </template>
                </div>
            </div>

            <!-- Legacy call-limit banner (only for companies still on the old monthly limit) -->
            <div
                v-if="data.call_limit && data.call_limit.monthly_call_limit != null && (data.call_limit.period_completed || (data.call_limit.remaining ?? 0) <= 0)"
                class="warnBanner"
            >
                <strong>{{ data.call_limit.period_completed ? 'Your limit period has ended.' : 'You have reached your monthly call limit.' }}</strong>
                Contact your administrator to {{ data.call_limit.period_completed ? 'renew your plan' : 'increase your limit' }} so blocked weeks can be processed.
            </div>

            <!-- Two-column body -->
            <div class="dashGrid">
                <!-- Weekly activity -->
                <div class="panel">
                    <div class="panel__head">
                        <div>
                            <div class="panel__title">Weekly activity</div>
                            <div class="panel__sub">Calls pulled from your PBX, week by week</div>
                        </div>
                        <RouterLink :to="{ name: 'reports' }" class="panel__link">See all</RouterLink>
                    </div>

                    <div v-if="data.weekly_history.length === 0" class="empty">
                        No weekly activity yet. The weekly pipeline runs automatically; processed weeks will appear here.
                    </div>
                    <div v-else class="histList">
                        <div class="histRow histRow--head">
                            <span>Week</span>
                            <span>Fetched</span>
                            <span>Blocked</span>
                            <span>Status</span>
                            <span></span>
                        </div>
                        <div v-for="w in data.weekly_history" :key="w.id" class="histRow">
                            <span data-label="Week">{{ fmtDate(w.week_start_date) }} → {{ fmtDate(w.week_end_date) }}</span>
                            <span class="mono" data-label="Fetched">{{ w.calls_fetched.toLocaleString() }}</span>
                            <span class="mono" :class="w.calls_blocked > 0 ? 'warn' : 'muted'" data-label="Blocked">{{ w.calls_blocked.toLocaleString() }}</span>
                            <span data-label="Status"><span class="pill" :class="`pill--${w.status}`">{{ statusLabel(w.status) }}</span></span>
                            <span class="histRow__action">
                                <template v-if="weekAction(w).kind === 'done'">
                                    <RouterLink :to="{ name: 'reports' }" class="histLink">View report →</RouterLink>
                                </template>
                                <template v-else-if="weekAction(w).kind === 'credits'">
                                    <span class="histActions">
                                        <RouterLink :to="{ name: 'select-plan' }" class="histBtn histBtn--credits">
                                            Add Credits
                                        </RouterLink>
                                        <button
                                            class="histBtn histBtn--muted"
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
                            then click "Run Again" (or wait for the pipeline to run automatically) to catch up.
                        </div>
                    </div>
                </div>

                <!-- Right column -->
                <div class="dashSide">
                    <div class="panel">
                        <div class="panel__title">What callers wanted</div>
                        <div class="panel__sub">Top categories, most recent report</div>

                        <div v-if="data.top_categories.length === 0" class="empty" style="margin-top: 10px">
                            No categorised calls yet.
                        </div>
                        <div v-else class="catList">
                            <div v-for="cat in data.top_categories" :key="cat.name" class="catRow">
                                <div class="catRow__head">
                                    <span>{{ cat.name }}</span>
                                    <span class="mono muted">{{ cat.count }}</span>
                                </div>
                                <div class="catRow__bar"><div class="catRow__fill" :style="{ width: cat.percent + '%' }"></div></div>
                            </div>
                        </div>
                    </div>

                    <div class="panel">
                        <div class="panel__title">Recent reports</div>
                        <div v-if="data.recent_reports.length === 0" class="empty" style="margin-top: 10px">
                            No reports yet. Reports are generated weekly after calls are processed.
                        </div>
                        <div v-else class="reportList">
                            <RouterLink
                                v-for="r in data.recent_reports"
                                :key="r.id"
                                :to="{ name: 'report-detail', params: { id: r.id } }"
                                class="reportRow"
                            >
                                <div class="reportRow__icon">
                                    <svg viewBox="0 0 24 24" fill="none"><path d="M5 20V10m4.7 10V4m4.6 16v-7m4.7 7V8" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/></svg>
                                </div>
                                <div class="reportRow__body">
                                    <div class="reportRow__title">{{ fmtDate(r.week_start_date) }} – {{ fmtDate(r.week_end_date) }}</div>
                                    <div class="reportRow__sub">{{ r.total_calls }} calls · {{ r.category_count }} categories</div>
                                </div>
                                <svg class="reportRow__chevron" viewBox="0 0 24 24" fill="none"><path d="m9 6 6 6-6 6" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/></svg>
                            </RouterLink>
                        </div>
                    </div>
                </div>
            </div>
        </template>

        <Transition name="toast">
            <div v-if="toast" class="toast">{{ toast }}</div>
        </Transition>
    </div>
</template>

<style scoped>
.page { display: flex; flex-direction: column; gap: 18px; }

/* ── Header ──────────────────────────────────────────── */
.dashHead { display: flex; align-items: flex-end; justify-content: space-between; gap: 20px; flex-wrap: wrap; }
.dashHead__title { margin: 0; font-size: 1.9rem; font-weight: 700; letter-spacing: -0.015em; line-height: 1.15; }
.dashHead__sub { margin: 6px 0 0 0; color: var(--color-muted); font-size: 0.92rem; }
.dashHead__cta {
    display: inline-flex; align-items: center; gap: 8px; height: 40px; padding: 0 16px; border-radius: 10px;
    background: var(--color-primary); color: #fff; font-size: 0.88rem; font-weight: 600; text-decoration: none;
    box-shadow: 0 1px 2px rgba(29, 25, 69, 0.1);
}
.dashHead__cta:hover { text-decoration: none; filter: brightness(1.05); }
.dashHead__cta svg { width: 15px; height: 15px; }

/* ── Stat cards ──────────────────────────────────────── */
.statGrid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 14px; }
.statCard {
    background: var(--color-surface); border: 1px solid var(--color-border); border-radius: 14px; padding: 18px;
    box-shadow: var(--shadow-xs);
}
.statCard__label {
    display: flex; align-items: center; gap: 7px; color: var(--color-muted); font-size: 0.76rem; font-weight: 500;
}
.statCard__label svg { width: 14px; height: 14px; flex-shrink: 0; }
.statCard__value { font-size: 1.9rem; font-weight: 700; line-height: 1.15; margin-top: 10px; letter-spacing: -0.01em; }
.statCard__hint { display: block; margin-top: 8px; font-size: 0.76rem; color: var(--color-muted); text-decoration: none; }
.statCard__hint--ok { color: var(--color-success); }
.statCard__hint--warn { color: var(--color-warning); }
.statCard__hint--link { color: var(--color-primary); font-weight: 500; }
.statCard__hint--link:hover { text-decoration: underline; }

/* ── Setup checklist ─────────────────────────────────── */
.setupStrip {
    background: var(--color-surface); border: 1px solid var(--color-border); border-radius: 14px;
    padding: 15px 18px; display: flex; align-items: center; gap: 18px; flex-wrap: wrap;
}
.setupStrip__label { min-width: 170px; }
.setupStrip__title { font-weight: 600; font-size: 0.92rem; }
.setupStrip__sub { font-size: 0.8rem; color: var(--color-muted); margin-top: 2px; }
.setupStrip__steps { display: flex; gap: 10px; flex: 1; flex-wrap: wrap; }
.setupStep {
    flex: 1 1 180px; display: flex; align-items: center; gap: 9px; padding: 10px 13px; border-radius: 10px;
    text-decoration: none; font-size: 0.84rem;
}
.setupStep svg { width: 17px; height: 17px; flex-shrink: 0; }
.setupStep--done { background: var(--color-success-soft); border: 1px solid var(--color-success-soft-border); color: var(--color-success); }
.setupStep--todo { background: var(--color-primary-soft); border: 1px solid var(--color-primary-soft-border); color: var(--color-primary); font-weight: 500; }
.setupStep--todo:hover { text-decoration: none; filter: brightness(0.97); }

/* ── Two-column body ─────────────────────────────────── */
.dashGrid { display: grid; grid-template-columns: minmax(0, 1.65fr) minmax(0, 1fr); gap: 14px; align-items: start; }
.dashSide { display: flex; flex-direction: column; gap: 14px; }

.panel { background: var(--color-surface); border: 1px solid var(--color-border); border-radius: 14px; padding: 16px 18px; }
.panel__head { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; margin-bottom: 4px; }
.panel__title { font-weight: 600; font-size: 0.95rem; }
.panel__sub { font-size: 0.8rem; color: var(--color-muted); margin-top: 2px; }
.panel__link { font-size: 0.8rem; color: var(--color-primary); font-weight: 500; text-decoration: none; white-space: nowrap; }
.panel__link:hover { text-decoration: underline; }

/* ── Weekly activity table ───────────────────────────── */
.histList { display: flex; flex-direction: column; gap: 0; margin-top: 8px; }
.histRow {
    display: grid; grid-template-columns: 1.6fr 0.85fr 0.85fr 1.1fr 1.5fr; gap: 12px;
    padding: 12px 4px; align-items: center; font-size: 0.87rem; border-bottom: 1px solid var(--color-border);
}
.histRow--head { font-size: 0.7rem; font-weight: 600; letter-spacing: 0.04em; color: var(--color-muted); text-transform: uppercase; padding: 0 4px 8px; border-bottom: 1px solid var(--color-border); }
.histRow:last-child { border-bottom: none; }
.histRow__action { display: flex; justify-content: flex-end; }
.mono { font-family: var(--font-mono); }
.muted { color: var(--color-muted); }
.warn { color: var(--color-warning); }
.ok { color: var(--color-success); }

.pill { padding: 3px 9px; border-radius: 999px; font-size: 0.7rem; font-weight: 600; white-space: nowrap; }
.pill--complete { background: var(--color-success-soft); color: var(--color-success); }
.pill--completed { background: var(--color-success-soft); color: var(--color-success); }
.pill--partial { background: var(--color-warning-soft); color: var(--color-warning); }
.pill--paused { background: var(--color-error-soft); color: var(--color-error); }
.pill--insufficient_credits { background: var(--color-error-soft); color: var(--color-error); }

.histBtn {
    height: 30px; padding: 0 12px; border-radius: 8px; cursor: pointer; font-size: 0.78rem; font-weight: 600;
    background: var(--color-primary); color: #fff; border: none; white-space: nowrap;
    display: inline-flex; align-items: center; justify-content: center; text-decoration: none;
}
.histBtn:hover:not(:disabled) { filter: brightness(1.05); }
.histBtn:disabled { opacity: 0.5; cursor: not-allowed; }
.histBtn--muted { background: transparent; color: var(--color-text); border: 1px solid var(--color-border-strong); }
.histBtn--credits { background: var(--color-error); }
.histLink { font-size: 0.82rem; font-weight: 500; color: var(--color-primary); text-decoration: none; }
.histLink:hover { text-decoration: underline; }

.histActions { display: flex; gap: 6px; flex-wrap: wrap; justify-content: flex-end; }

.creditsBanner {
    margin-top: 10px; padding: 10px 12px; border-radius: 10px; font-size: 0.82rem; line-height: 1.5;
    background: var(--color-error-soft); border: 1px solid var(--color-error-soft-border);
}
.creditsBanner a { color: var(--color-primary); font-weight: 600; }

/* ── What callers wanted ─────────────────────────────── */
.catList { display: flex; flex-direction: column; gap: 12px; margin-top: 14px; }
.catRow__head { display: flex; justify-content: space-between; font-size: 0.84rem; margin-bottom: 6px; }
.catRow__bar { height: 6px; border-radius: 999px; background: var(--color-surface-2); overflow: hidden; }
.catRow__fill { height: 100%; border-radius: 999px; background: var(--color-primary); }

/* ── Recent reports list ─────────────────────────────── */
.reportList { display: flex; flex-direction: column; gap: 2px; margin-top: 10px; }
.reportRow {
    display: flex; align-items: center; gap: 11px; padding: 9px 8px; margin: 0 -8px; border-radius: 9px;
    text-decoration: none; color: inherit; transition: background 0.12s;
}
.reportRow:hover { background: var(--color-surface-2); text-decoration: none; }
.reportRow__icon {
    width: 34px; height: 34px; border-radius: 9px; background: var(--color-primary-soft); color: var(--color-primary);
    display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.reportRow__icon svg { width: 16px; height: 16px; }
.reportRow__body { flex: 1; min-width: 0; }
.reportRow__title { font-size: 0.85rem; font-weight: 500; }
.reportRow__sub { font-size: 0.76rem; color: var(--color-muted); margin-top: 1px; }
.reportRow__chevron { width: 16px; height: 16px; color: var(--color-muted); flex-shrink: 0; }

.empty { opacity: 0.65; font-size: 0.9rem; }

.errorBanner, .infoBanner, .warnBanner {
    border-radius: 10px; padding: var(--space-4);
}
.errorBanner { background: var(--color-error-soft); border: 1px solid var(--color-error-soft-border); color: var(--color-error); }
.infoBanner { background: color-mix(in srgb, var(--color-primary) 10%, transparent); border: 1px solid var(--border); }
.warnBanner { background: var(--color-warning-soft); border: 1px solid var(--color-warning-soft-border); font-size: 0.9rem; line-height: 1.5; }

/* ── Skeletons ───────────────────────────────────────────
   Shown only while `loading` is true — no artificial minimum
   duration, they disappear the instant the real response lands. */
.sk {
    border-radius: 6px;
    background: linear-gradient(
        90deg,
        color-mix(in srgb, var(--color-text) 7%, transparent) 25%,
        color-mix(in srgb, var(--color-text) 13%, transparent) 37%,
        color-mix(in srgb, var(--color-text) 7%, transparent) 63%
    );
    background-size: 400% 100%;
    animation: skShimmer 1.4s ease-in-out infinite;
}

@keyframes skShimmer {
    0% { background-position: 100% 50%; }
    100% { background-position: 0 50%; }
}

.sk-title { width: 220px; height: 30px; margin-bottom: 8px; }
.sk-sub { width: 300px; height: 15px; }
.sk-btn { width: 130px; height: 40px; border-radius: 10px; }
.sk-label { width: 90px; height: 12px; }
.sk-value { width: 70px; height: 32px; margin-top: 10px; }
.sk-hint { width: 100px; height: 12px; margin-top: 8px; }
.sk-step { flex: 1 1 180px; height: 40px; border-radius: 10px; }
.sk-cell { width: 100%; height: 13px; }
.sk-cell--sm { width: 60%; }
.sk-cell--btn { width: 80px; height: 30px; border-radius: 8px; justify-self: end; }
.sk-pill { width: 70px; height: 20px; border-radius: 999px; }
.sk-bar { width: 100%; height: 6px; border-radius: 999px; }
.sk-icon { width: 34px; height: 34px; border-radius: 9px; flex-shrink: 0; }

@media (prefers-reduced-motion: reduce) {
    .sk { animation: none; }
}

.toast {
    position: fixed; bottom: 24px; left: 50%; transform: translateX(-50%);
    background: var(--color-text); color: var(--color-surface); padding: 11px 18px; border-radius: 10px;
    font-size: 0.85rem; font-weight: 500; box-shadow: 0 8px 24px rgba(0,0,0,.25); z-index: 9999; max-width: 90vw;
}
.toast-enter-active, .toast-leave-active { transition: opacity .2s, transform .2s; }
.toast-enter-from, .toast-leave-to { opacity: 0; transform: translateX(-50%) translateY(10px); }

@media (max-width: 1180px) {
    .dashGrid { grid-template-columns: 1fr; }
}

@media (max-width: 860px) {
    .statGrid { grid-template-columns: repeat(2, 1fr); }
}

@media (max-width: 640px) {
    .statGrid { grid-template-columns: repeat(2, 1fr); }
    .dashHead { flex-direction: column; align-items: flex-start; }
    .dashHead__cta { align-self: stretch; justify-content: center; }
    .setupStrip { flex-direction: column; align-items: stretch; }
    .setupStep { flex: 1 1 auto; }

    /* Weekly activity becomes a stack of cards instead of a table row. */
    .histRow--head { display: none; }
    .histRow {
        grid-template-columns: 1fr 1fr;
        row-gap: 8px;
        border: 1px solid var(--color-border);
        border-radius: 12px;
        padding: 12px 13px;
        margin-bottom: 10px;
    }
    .histRow:last-child { margin-bottom: 0; }
    .histRow > [data-label]::before {
        content: attr(data-label);
        display: block;
        font-size: 0.66rem;
        font-weight: 600;
        letter-spacing: 0.03em;
        text-transform: uppercase;
        color: var(--color-muted);
        margin-bottom: 2px;
    }
    .histRow > [data-label="Week"] { grid-column: 1 / -1; }
    .histRow__action { grid-column: 1 / -1; justify-content: flex-start; margin-top: 2px; }
    .histActions { width: 100%; }
    .histActions .histBtn { flex: 1; }
}
</style>
