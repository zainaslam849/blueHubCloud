<script setup>
import { computed, onMounted, ref, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import adminApi from "../../router/admin/api";

// ── Types (JSDoc) ─────────────────────────────────────────────────────────
/** @type {import('vue').Ref<null|object>} */
const report = ref(null);
const loading = ref(true);
const error   = ref("");

const route  = useRoute();
const router = useRouter();

// ── Computed: header subtitle ─────────────────────────────────────────────
const subtitleText = computed(() => {
    if (loading.value && !report.value) return "Week of Loading…";
    const formatted = report.value?.header?.week_range?.formatted;
    if (formatted) return `Week of ${formatted}`;
    const start = report.value?.header?.week_range?.start;
    const end   = report.value?.header?.week_range?.end;
    if (start && end) return `Week of ${start} to ${end}`;
    return "Weekly Report";
});

// ── Computed: Executive Summary HTML ──────────────────────────────────────
const summaryHtml = computed(() => {
    const raw = report.value?.executive_summary || "";
    const escaped = raw
        .replace(/&/g, "&amp;").replace(/</g, "&lt;")
        .replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#39;");
    return escaped.replace(/\*\*(.+?)\*\*/g, "<strong>$1</strong>").replace(/\n/g, "<br>");
});

// ── Computed: Quantitative ────────────────────────────────────────────────
const missedRate = computed(() => {
    const t = report.value?.metrics?.total_calls || 0;
    const m = report.value?.metrics?.missed_calls || 0;
    if (t === 0) return 0;
    return Math.round((m / t) * 100 * 10) / 10;
});

// ── Computed: Category Breakdowns ─────────────────────────────────────────
const bd = computed(() => report.value?.category_breakdowns ?? null);

const hasCategories   = computed(() => bd.value && Object.keys(bd.value.counts ?? {}).length > 0);
const hasSubCategories = computed(() => {
    const d = bd.value?.details;
    if (!d) return false;
    return Object.values(d).some(c => c.sub_categories && Object.keys(c.sub_categories).length > 0);
});
const hasTopDids     = computed(() => (bd.value?.top_dids?.length ?? 0) > 0);
const hasHourlyData  = computed(() => bd.value?.hourly_distribution && Object.keys(bd.value.hourly_distribution).length > 0);
const hasSampleCalls = computed(() => {
    const d = bd.value?.details;
    if (!d) return false;
    return Object.values(d).some(c => c.sample_calls?.length > 0);
});

const totalCategorizedCalls = computed(() => {
    const counts = bd.value?.counts ?? {};
    return Object.values(counts).reduce((s, c) => s + c, 0);
});
const categorizedCalls = computed(() =>
    Number(bd.value?.totals?.categorized_calls ?? totalCategorizedCalls.value) || 0
);
const reportTotalCalls = computed(() => {
    const v = bd.value?.totals?.report_total_calls;
    if (v === null || v === undefined) return null;
    const n = Number(v);
    return Number.isFinite(n) ? n : null;
});

const sortedCategories = computed(() => {
    const counts = bd.value?.counts ?? {};
    const total  = totalCategorizedCalls.value;
    return Object.entries(counts)
        .map(([name, count]) => ({ name, count, percent: total > 0 ? Math.round(count / total * 1000) / 10 : 0 }))
        .sort((a, b) => b.count - a.count);
});

const categoriesWithSubs = computed(() => {
    const details = bd.value?.details ?? {};
    return Object.entries(details)
        .filter(([, c]) => c.sub_categories && Object.keys(c.sub_categories).length > 0)
        .map(([name, c]) => {
            const subTotal = Object.values(c.sub_categories).reduce((s, v) => s + v, 0);
            return {
                name, count: c.count,
                subCategories: Object.entries(c.sub_categories)
                    .map(([sn, sc]) => ({ name: sn, count: sc, percent: subTotal > 0 ? Math.round(sc / subTotal * 1000) / 10 : 0 }))
                    .sort((a, b) => b.count - a.count),
            };
        })
        .sort((a, b) => b.count - a.count);
});

const categoriesWithSamples = computed(() => {
    const details = bd.value?.details ?? {};
    return Object.entries(details)
        .filter(([, c]) => c.sample_calls?.length > 0)
        .map(([name, c]) => ({ name, samples: c.sample_calls }))
        .sort((a, b) => b.samples.length - a.samples.length);
});

const hourlyData = computed(() => {
    const dist = bd.value?.hourly_distribution ?? {};
    const maxCount = Math.max(...Object.values(dist).map(Number), 1);
    const peakThreshold = maxCount * 0.7;
    return Array.from({ length: 24 }, (_, hour) => ({
        hour,
        label: fmtHour(hour),
        count: dist[hour] || 0,
        isPeak: (dist[hour] || 0) >= peakThreshold,
    }));
});

// ── Computed: Insights ─────────────────────────────────────────────────────
const hasOpportunities   = computed(() => (report.value?.insights?.ai_opportunities?.length ?? 0) > 0);
const hasRecommendations = computed(() => (report.value?.insights?.recommendations?.length ?? 0) > 0);
const hasInsights        = computed(() => hasOpportunities.value || hasRecommendations.value);

// ── Computed: Advanced Views ───────────────────────────────────────────────
const adv            = computed(() => report.value?.advanced_views ?? null);
const company        = computed(() => adv.value?.company_dashboard ?? {});
const companySummary = computed(() => company.value.summary ?? {});
const trend          = computed(() => company.value.trend_vs_last_period ?? { has_previous: false });
const ringGroups     = computed(() => adv.value?.ring_group_dashboard ?? []);
const extensions     = computed(() => adv.value?.extension_leaderboard ?? []);
const scorecards     = computed(() => adv.value?.extension_scorecards ?? []);
const drilldown      = computed(() => adv.value?.category_drilldown ?? []);
const hasAdvanced    = computed(() =>
    (company.value.top_categories?.length ?? 0) > 0 ||
    ringGroups.value.length > 0 || extensions.value.length > 0 ||
    scorecards.value.length > 0 || drilldown.value.length > 0
);

// ── Formatters ────────────────────────────────────────────────────────────
function fmtNum(v) {
    const n = Number(v);
    return Number.isFinite(n) ? n.toLocaleString() : "—";
}
function fmtDateTime(iso) {
    if (!iso) return "—";
    const d = new Date(iso);
    return Number.isFinite(d.getTime()) ? d.toLocaleString() : "—";
}
function fmtDate(iso) {
    if (!iso) return "—";
    const d = new Date(iso);
    return Number.isFinite(d.getTime()) ? d.toLocaleDateString() : "—";
}
function fmtHour(hour) {
    if (hour === 0) return "12a";
    if (hour === 12) return "12p";
    return hour < 12 ? `${hour}a` : `${hour - 12}p`;
}
function fmtHourLong(hour) {
    if (hour === 0) return "12am";
    if (hour === 12) return "12pm";
    return hour < 12 ? `${hour}am` : `${hour - 12}pm`;
}
function fmtTotalDuration(seconds) {
    const s = Number(seconds);
    if (!Number.isFinite(s) || s < 0) return "—";
    const h = Math.floor(s / 3600);
    const m = Math.floor((s % 3600) / 60);
    return h > 0 ? `${h}h ${m}m` : `${m}m`;
}
function fmtAvgDuration(seconds) {
    const s = Number(seconds);
    if (!Number.isFinite(s) || s < 0) return "";
    return `${s} seconds`;
}
function statusVariant(status) {
    const s = String(status || "").toLowerCase();
    if (s === "completed") return "v--active";
    if (s === "failed")    return "v--failed";
    return "v--processing";
}
function pbxAccountLabel(pbx) {
    if (!pbx) return "—";
    return pbx.display || pbx.name || (pbx.server_id ? `Server ${pbx.server_id}` : "—");
}
function opportunityVariant(type) {
    return type === "automation_candidate" ? "v--active" : "v--processing";
}
function formatOpportunityType(type) {
    const map = { automation_candidate: "Automation Candidate", sub_category_highlight: "Sub-category Focus" };
    return map[type] ?? type;
}
function formatRecommendationType(type) {
    const map = { low_answer_rate: "Answer Rate Alert", high_missed_calls: "Missed Calls Alert", peak_hours: "Peak Hours", after_hours_volume: "After-Hours Volume" };
    return map[type] ?? type;
}
function recommendationClass(type) {
    const map = { low_answer_rate: "wRecCard--warning", high_missed_calls: "wRecCard--warning", peak_hours: "wRecCard--info", after_hours_volume: "wRecCard--info" };
    return map[type] ?? "";
}
function trendLabel(dir, pct) {
    if (dir > 0) return `Up ${pct ?? 0}%`;
    if (dir < 0) return `Down ${Math.abs(pct ?? 0)}%`;
    return "Stable";
}
function trendSparkline(daily) {
    if (!daily || typeof daily !== "object") return "—";
    const vals = Object.values(daily).map(Number).filter(v => Number.isFinite(v));
    if (!vals.length) return "—";
    const bars = "▁▂▃▄▅▆▇█";
    const max = Math.max(...vals, 1);
    return vals.slice(-10).map(v => bars[Math.min(bars.length - 1, Math.floor(v / max * (bars.length - 1)))]).join("");
}
function topCategoriesLabel(cats) {
    if (!Array.isArray(cats) || !cats.length) return "—";
    return cats.slice(0, 3).map(c => c?.category_name || `#${c?.category_id ?? "?"}`).join(", ");
}
function getSortedBreakdown(breakdown, topN) {
    if (!breakdown || typeof breakdown !== "object") return {};
    return Object.fromEntries(Object.entries(breakdown).sort(([, a], [, b]) => Number(b) - Number(a)).slice(0, topN));
}
function priorityBadgeStyle(priority) {
    const styles = { high: "background:#fee2e2;color:#dc2626", medium: "background:#fef9c3;color:#b45309", low: "background:#dcfce7;color:#16a34a" };
    const base = styles[priority?.toLowerCase?.()] ?? "background:#f3f4f6;color:#6b7280";
    return `${base};border-radius:4px;padding:1px 6px;font-size:0.8em;font-weight:600`;
}

// ── Fetch ─────────────────────────────────────────────────────────────────
async function fetchReport() {
    const id = route.params.id;
    loading.value = true;
    error.value   = "";
    try {
        const res    = await adminApi.get(`/weekly-call-reports/${id}`);
        report.value = res?.data?.data ?? null;
    } catch (e) {
        report.value = null;
        const s = e?.response?.status;
        error.value = s === 404 ? "Report not found." : s === 403 ? "You do not have permission to view this report." : "Failed to load report.";
    } finally {
        loading.value = false;
    }
}

function goBack() { router.push({ name: "admin.weeklyReports" }); }

watch(() => route.params.id, () => { fetchReport(); });
onMounted(() => { fetchReport(); });
</script>

<template>
    <div class="wPage admin-container">
        <!-- ── Header ──────────────────────────────────────────────── -->
        <header class="wHeader">
            <div class="wHeader__left">
                <div class="wHeader__icon">
                    <svg viewBox="0 0 24 24" fill="none">
                        <rect x="3" y="3" width="18" height="18" rx="2" stroke="currentColor" stroke-width="1.5"/>
                        <path d="M7 8h10M7 12h10M7 16h5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                    </svg>
                </div>
                <div class="wHeader__content">
                    <div class="wHeader__breadcrumb">
                        <router-link :to="{ name: 'admin.weeklyReports' }" class="wBreadLink">Reports</router-link>
                        <span class="wBreadSep">/</span>
                        <span>Weekly Report</span>
                    </div>
                    <h1 class="wHeader__title">{{ report?.header?.company?.name || report?.header?.company_name || "Weekly Report" }}</h1>
                    <p class="wHeader__subtitle">{{ subtitleText }}</p>
                </div>
            </div>

            <div class="wHeader__stats">
                <div class="wHeader__stat">
                    <div class="wHeader__statLabel">Total Calls</div>
                    <div class="wHeader__statValue">{{ report?.metrics?.total_calls || "—" }}</div>
                </div>
                <div class="wHeader__stat">
                    <div class="wHeader__statLabel">Answer Rate</div>
                    <div class="wHeader__statValue">{{ report?.metrics?.answer_rate || "—" }}%</div>
                </div>
            </div>

            <div class="wHeader__actions">
                <button type="button" class="wBtn wBtn--ghost" @click="goBack">
                    <svg viewBox="0 0 24 24" fill="none" class="wBtn__icon"><path d="M19 12H5M5 12L12 19M5 12L12 5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    Back
                </button>
                <button type="button" class="wBtn wBtn--ghost" :disabled="loading" @click="fetchReport">
                    <svg viewBox="0 0 24 24" fill="none" class="wBtn__icon"><path d="M20 12a8 8 0 1 1-2.34-5.66" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><path d="M20 4v6h-6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    Refresh
                </button>
                <a v-if="!loading && report?.exports?.pdf_available" class="wBtn wBtn--primary" target="_blank" rel="noopener noreferrer">
                    <svg viewBox="0 0 24 24" fill="none" class="wBtn__icon"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    Export PDF
                </a>
            </div>
        </header>

        <div v-if="error" class="wAlert wAlert--error">{{ error }}</div>

        <!-- ── Skeleton ───────────────────────────────────────────── -->
        <template v-if="loading">
            <div v-for="n in 5" :key="n" class="wCard"><div class="wSkelLines"></div></div>
        </template>

        <template v-else-if="report">
            <!-- AI Incomplete warning -->
            <div v-if="report.header?.ai_incomplete" class="wAlert wAlert--warn">
                This report is incomplete — {{ report.header.ai_incomplete_call_count }} calls could not be AI-processed due to credit limits.
            </div>

            <!-- ── 1. Report Information ──────────────────────────────────── -->
            <div class="wCard">
                <div class="wCard__title">Report Information</div>
                <div class="wKvGrid wKvGrid--3col">
                    <div class="wKv"><div class="wKv__k">Company</div><div class="wKv__v">{{ report.header?.company?.name || "—" }}</div></div>
                    <div class="wKv"><div class="wKv__k">PBX Account</div><div class="wKv__v">{{ pbxAccountLabel(report.header?.pbx_account) }}</div></div>
                    <div class="wKv"><div class="wKv__k">Report ID</div><div class="wKv__v wMono">#{{ report.header?.id || "—" }}</div></div>
                    <div class="wKv"><div class="wKv__k">Week Range</div><div class="wKv__v">{{ report.header?.week_range?.formatted || "—" }}</div></div>
                    <div class="wKv"><div class="wKv__k">Period</div><div class="wKv__v wMono">{{ report.header?.week_range?.start }} to {{ report.header?.week_range?.end }}</div></div>
                    <div class="wKv">
                        <div class="wKv__k">Status</div>
                        <div class="wKv__v">
                            <span class="wBadge" :class="statusVariant(report.header?.status)">{{ (report.header?.status || "pending").toUpperCase() }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── 2. Executive Summary ───────────────────────────────────── -->
            <div class="wCard">
                <div class="wCard__title">Executive Summary</div>
                <div v-if="!report.executive_summary" class="wEmpty">
                    <div class="wEmpty__title">No summary available</div>
                    <div class="wEmpty__sub">Executive summary has not been generated for this report.</div>
                </div>
                <p v-else class="wProse" v-html="summaryHtml"></p>
            </div>

            <!-- ── 3. Quantitative Analysis ──────────────────────────────── -->
            <div class="wCard">
                <div class="wCard__title">Quantitative Analysis</div>
                <div class="wCard__desc">Key performance metrics for the reporting period</div>

                <div class="wMetricsGrid">
                    <div class="wMetricCard">
                        <div class="wMetricCard__header">
                            <span class="wMetricCard__icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg></span>
                            <span class="wMetricCard__label">Total Calls</span>
                        </div>
                        <div class="wMetricCard__value">{{ fmtNum(report.metrics?.total_calls) }}</div>
                    </div>
                    <div class="wMetricCard wMetricCard--success">
                        <div class="wMetricCard__header">
                            <span class="wMetricCard__icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg></span>
                            <span class="wMetricCard__label">Answered</span>
                        </div>
                        <div class="wMetricCard__value">{{ fmtNum(report.metrics?.answered_calls) }}</div>
                        <div class="wMetricCard__sub">{{ report.metrics?.answer_rate || 0 }}% answer rate</div>
                    </div>
                    <div class="wMetricCard wMetricCard--warning">
                        <div class="wMetricCard__header">
                            <span class="wMetricCard__icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><line x1="1" y1="1" x2="23" y2="23"/><path d="M16.72 11.06A10.94 10.94 0 0 1 19 12.55"/><path d="M5 12.55a10.94 10.94 0 0 1 5.17-2.39"/><path d="M10.71 5.05A16 16 0 0 1 22.58 9"/><path d="M1.42 9a15.91 15.91 0 0 1 4.7-2.88"/><path d="M8.53 16.11a6 6 0 0 1 6.95 0"/><line x1="12" y1="20" x2="12.01" y2="20"/></svg></span>
                            <span class="wMetricCard__label">Missed</span>
                        </div>
                        <div class="wMetricCard__value">{{ fmtNum(report.metrics?.missed_calls) }}</div>
                        <div class="wMetricCard__sub">{{ missedRate }}% of total</div>
                    </div>
                    <div class="wMetricCard">
                        <div class="wMetricCard__header">
                            <span class="wMetricCard__icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg></span>
                            <span class="wMetricCard__label">Transcribed</span>
                        </div>
                        <div class="wMetricCard__value">{{ fmtNum(report.metrics?.calls_with_transcription) }}</div>
                        <div class="wMetricCard__sub">{{ report.metrics?.transcription_rate || 0 }}% coverage</div>
                    </div>
                    <div class="wMetricCard">
                        <div class="wMetricCard__header">
                            <span class="wMetricCard__icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></span>
                            <span class="wMetricCard__label">Avg Duration</span>
                        </div>
                        <div class="wMetricCard__value">{{ report.metrics?.avg_call_duration_formatted || "—" }}</div>
                        <div class="wMetricCard__sub">{{ fmtAvgDuration(report.metrics?.avg_call_duration_seconds) }}</div>
                    </div>
                    <div class="wMetricCard">
                        <div class="wMetricCard__header">
                            <span class="wMetricCard__icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></span>
                            <span class="wMetricCard__label">Total Duration</span>
                        </div>
                        <div class="wMetricCard__value">{{ fmtTotalDuration(report.metrics?.total_call_duration_seconds) }}</div>
                    </div>
                </div>

                <div class="wTimeRange">
                    <div class="wKv"><div class="wKv__k">First Call</div><div class="wKv__v wMono">{{ fmtDateTime(report.metrics?.first_call_at) }}</div></div>
                    <div class="wKv"><div class="wKv__k">Last Call</div><div class="wKv__v wMono">{{ fmtDateTime(report.metrics?.last_call_at) }}</div></div>
                </div>
            </div>

            <!-- ── 4. Call Endpoints ──────────────────────────────────────── -->
            <div class="wCard">
                <div class="wCard__title">Call Endpoints (From / To)</div>
                <div class="wCard__desc">Real From/To values returned by PBX API for calls in this report</div>
                <div v-if="!report.call_endpoints?.length" class="wEmpty">
                    <div class="wEmpty__title">No From/To endpoint data yet</div>
                    <div class="wEmpty__sub">This report currently has no calls with non-null From/To fields.</div>
                </div>
                <div v-else class="wTableWrap">
                    <table class="wTable">
                        <thead><tr><th>Started At</th><th>From</th><th>To</th><th>Status</th><th class="wTh--num">Duration (s)</th></tr></thead>
                        <tbody>
                            <tr v-for="row in report.call_endpoints" :key="row.call_id">
                                <td>{{ fmtDateTime(row.started_at) }}</td>
                                <td>{{ row.from || "—" }}</td>
                                <td>{{ row.to || "—" }}</td>
                                <td>{{ row.status || "—" }}</td>
                                <td class="wTd--num wMono">{{ Number(row.duration_seconds || 0) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ── 5. Category Breakdowns ────────────────────────────────── -->
            <div class="wCard">
                <div class="wCard__title">Category Breakdowns</div>
                <div class="wCard__desc">Call distribution by category and sub-category</div>

                <div v-if="!hasCategories" class="wEmpty">
                    <div class="wEmpty__title">No category data</div>
                    <div class="wEmpty__sub">No calls have been categorized for this period.</div>
                </div>

                <template v-else>
                    <!-- Category Summary -->
                    <div class="wSection">
                        <h4 class="wSection__title">Category Summary</h4>
                        <p class="wMuted" style="margin: 0 0 8px">
                            Percentages are based on categorized calls ({{ categorizedCalls }})
                            <template v-if="reportTotalCalls !== null"> out of total calls ({{ reportTotalCalls }}).</template>
                        </p>
                        <div class="wTableWrap">
                            <table class="wTable">
                                <thead><tr><th>Category</th><th class="wTh--num">Calls</th><th class="wTh--num">% of Total</th></tr></thead>
                                <tbody>
                                    <tr v-for="cat in sortedCategories" :key="cat.name">
                                        <td>{{ cat.name }}</td>
                                        <td class="wTd--num wMono">{{ fmtNum(cat.count) }}</td>
                                        <td class="wTd--num">
                                            <span class="wPctBar">
                                                <span class="wPctBar__fill" :style="{ width: cat.percent + '%' }"></span>
                                                <span class="wPctBar__label">{{ cat.percent }}%</span>
                                            </span>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Sub-category Details -->
                    <div v-if="hasSubCategories" class="wSection">
                        <h4 class="wSection__title">Sub-category Details</h4>
                        <div class="wSubCatGrid">
                            <div v-for="cat in categoriesWithSubs" :key="cat.name" class="wSubCatCard">
                                <div class="wSubCatCard__header">
                                    <span class="wSubCatCard__name">{{ cat.name }}</span>
                                    <span class="wSubCatCard__count">{{ cat.count }} calls</span>
                                </div>
                                <table class="wTable wTable--mini">
                                    <tbody>
                                        <tr v-for="sub in cat.subCategories" :key="sub.name">
                                            <td>{{ sub.name }}</td>
                                            <td class="wTd--num wMono">{{ sub.count }}</td>
                                            <td class="wTd--num">{{ sub.percent }}%</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Top DIDs -->
                    <div v-if="hasTopDids" class="wSection">
                        <h4 class="wSection__title">Top DIDs</h4>
                        <div class="wTableWrap">
                            <table class="wTable">
                                <thead><tr><th>DID</th><th class="wTh--num">Calls</th></tr></thead>
                                <tbody>
                                    <tr v-for="(did, i) in report.category_breakdowns.top_dids" :key="i">
                                        <td class="wMono">{{ did.did }}</td>
                                        <td class="wTd--num wMono">{{ fmtNum(did.calls) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Hourly Distribution -->
                    <div v-if="hasHourlyData" class="wSection">
                        <h4 class="wSection__title">Hourly Distribution</h4>
                        <div class="wHourlyGrid">
                            <div v-for="h in hourlyData" :key="h.hour" class="wHourlyCell" :class="{ 'wHourlyCell--peak': h.isPeak }">
                                <span class="wHourlyCell__hour">{{ h.label }}</span>
                                <span class="wHourlyCell__count">{{ h.count }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Sample Calls -->
                    <div v-if="hasSampleCalls" class="wSection">
                        <h4 class="wSection__title">Sample Calls by Category</h4>
                        <div v-for="cat in categoriesWithSamples" :key="cat.name" class="wSampleSection">
                            <h5 class="wSampleSection__title">{{ cat.name }}</h5>
                            <div class="wSampleList">
                                <div v-for="(sample, idx) in cat.samples" :key="idx" class="wSampleCall">
                                    <div class="wSampleCall__meta">
                                        <span class="wMono">{{ fmtDate(sample.date) }}</span>
                                        <span v-if="sample.did" class="wSampleCall__tag">DID: {{ sample.did }}</span>
                                        <span v-if="sample.src" class="wSampleCall__tag">From: {{ sample.src }}</span>
                                    </div>
                                    <div class="wSampleCall__transcript">{{ sample.transcript }}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </template>
            </div>

            <!-- ── 6. Insights & Recommendations ────────────────────────── -->
            <div class="wCard">
                <div class="wCard__title">Insights &amp; Recommendations</div>
                <div class="wCard__desc">AI opportunities and actionable recommendations</div>

                <div v-if="!hasInsights" class="wEmpty">
                    <div class="wEmpty__title">No insights available</div>
                    <div class="wEmpty__sub">Not enough data to generate insights for this period.</div>
                </div>

                <template v-else>
                    <!-- AI Opportunities -->
                    <div v-if="hasOpportunities" class="wSection">
                        <h4 class="wSection__title">
                            <span class="wSection__icon wSection__icon--opp">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                            </span>
                            AI &amp; Automation Opportunities
                        </h4>
                        <div class="wInsightList">
                            <div v-for="(opp, idx) in report.insights.ai_opportunities" :key="idx" class="wInsightCard wInsightCard--opp">
                                <div class="wInsightCard__header">
                                    <span class="wBadge" :class="opportunityVariant(opp.type)">{{ formatOpportunityType(opp.type) }}</span>
                                    <span v-if="opp.category" class="wInsightCard__category">{{ opp.category }}</span>
                                </div>
                                <div class="wInsightCard__body">
                                    <p class="wInsightCard__reason">{{ opp.reason }}</p>
                                    <div class="wInsightMetrics">
                                        <div v-if="opp.call_count" class="wInsightMetric">
                                            <span class="wInsightMetric__label">Calls</span>
                                            <span class="wInsightMetric__value">{{ fmtNum(opp.call_count) }}</span>
                                        </div>
                                        <div v-if="opp.percentage" class="wInsightMetric">
                                            <span class="wInsightMetric__label">Share</span>
                                            <span class="wInsightMetric__value">{{ opp.percentage }}%</span>
                                        </div>
                                        <div v-if="opp.top_sub_category" class="wInsightMetric">
                                            <span class="wInsightMetric__label">Top Sub-category</span>
                                            <span class="wInsightMetric__value">
                                                {{ opp.top_sub_category }}
                                                <span class="wInsightMetric__sub">({{ opp.top_sub_category_count }} calls, {{ opp.top_sub_category_percentage }}%)</span>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Recommendations -->
                    <div v-if="hasRecommendations" class="wSection">
                        <h4 class="wSection__title">
                            <span class="wSection__icon wSection__icon--rec">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M14 9V5a3 3 0 0 0-3-3l-4 9v11h11.28a2 2 0 0 0 2-1.7l1.38-9a2 2 0 0 0-2-2.3zM7 22H4a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2h3"/></svg>
                            </span>
                            Recommendations
                        </h4>
                        <div class="wRecList">
                            <div v-for="(rec, idx) in report.insights.recommendations" :key="idx" class="wRecCard" :class="recommendationClass(rec.type)">
                                <div class="wRecCard__icon">
                                    <svg v-if="rec.type === 'low_answer_rate'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                                    <svg v-else-if="rec.type === 'high_missed_calls'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                                    <svg v-else-if="rec.type === 'after_hours_volume'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
                                    <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                </div>
                                <div class="wRecCard__content">
                                    <div class="wRecCard__type">{{ formatRecommendationType(rec.type) }}</div>
                                    <p class="wRecCard__message">{{ rec.message }}</p>
                                    <div v-if="rec.hours?.length" class="wRecCard__tags">
                                        <span v-for="h in rec.hours" :key="h" class="wRecTag">{{ fmtHourLong(h) }}</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </template>
            </div>

            <!-- ── 7. Automation Priority Views ──────────────────────────── -->
            <div class="wCard">
                <div class="wCard__title">Automation Priority Views</div>
                <div class="wCard__desc">Company, ring group, extension, and category drill-down dashboards</div>

                <div v-if="!hasAdvanced" class="wEmpty">
                    <div class="wEmpty__title">No advanced analytics yet</div>
                    <div class="wEmpty__sub">Run weekly report generation with categorized calls to populate these views.</div>
                </div>

                <div v-else class="wSections">
                    <!-- 1) Company Dashboard -->
                    <div class="wSection">
                        <h4 class="wSection__title">1) Company Dashboard</h4>
                        <div class="wKvGrid">
                            <div class="wKv"><div class="wKv__k">Total Calls</div><div class="wKv__v wMono">{{ companySummary.total_calls ?? 0 }}</div></div>
                            <div class="wKv"><div class="wKv__k">Total Minutes</div><div class="wKv__v wMono">{{ companySummary.total_minutes ?? 0 }}</div></div>
                            <div class="wKv"><div class="wKv__k">Missed Calls</div><div class="wKv__v wMono">{{ companySummary.missed_calls ?? 0 }}</div></div>
                            <div class="wKv">
                                <div class="wKv__k">Trend vs Last Period</div>
                                <div class="wKv__v">
                                    <span v-if="trend.has_previous">
                                        Calls {{ trend.calls_delta > 0 ? '+' : '' }}{{ trend.calls_delta ?? 0 }}
                                        <span v-if="trend.calls_delta_pct !== null" class="wMuted">({{ trend.calls_delta_pct > 0 ? '+' : '' }}{{ trend.calls_delta_pct }}%)</span>
                                    </span>
                                    <span v-else>—</span>
                                </div>
                            </div>
                        </div>

                        <div class="wSubsection">
                            <h5 class="wSubsection__title">Top Categories</h5>
                            <div class="wTableWrap" v-if="(company.top_categories || []).length">
                                <table class="wTable">
                                    <thead><tr><th>Category</th><th class="wTh--num">Calls</th></tr></thead>
                                    <tbody>
                                        <tr v-for="row in company.top_categories" :key="row.name">
                                            <td>{{ row.name }}</td>
                                            <td class="wTd--num wMono">{{ row.count }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <div v-else class="wMuted">No category data</div>
                        </div>

                        <div v-if="(company.peak_missed_times || []).length" class="wSubsection">
                            <h5 class="wSubsection__title">Peak Missed Call Times</h5>
                            <div class="wTableWrap">
                                <table class="wTable">
                                    <thead><tr><th>Hour</th><th class="wTh--num">Missed Calls</th></tr></thead>
                                    <tbody>
                                        <tr v-for="peak in company.peak_missed_times" :key="peak.hour_label">
                                            <td class="wMono">{{ peak.hour_label }}</td>
                                            <td class="wTd--num wMono">{{ peak.missed_count }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="wSubsection">
                            <h5 class="wSubsection__title">Top Automation Opportunities</h5>
                            <div class="wTableWrap" v-if="(company.top_automation_opportunities || []).length">
                                <table class="wTable">
                                    <thead><tr><th>Category</th><th>Priority</th><th class="wTh--num">Calls</th><th class="wTh--num">Minutes</th></tr></thead>
                                    <tbody>
                                        <tr v-for="row in company.top_automation_opportunities" :key="`${row.category_id}-${row.priority}`">
                                            <td>{{ row.category_name || `Category #${row.category_id}` }}</td>
                                            <td><span :style="priorityBadgeStyle(row.priority)">{{ (row.priority || "").toUpperCase() }}</span></td>
                                            <td class="wTd--num wMono">{{ row.total_calls }}</td>
                                            <td class="wTd--num wMono">{{ row.total_minutes }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <div v-else class="wMuted">No automation candidates yet</div>
                        </div>
                    </div>

                    <!-- 2) Ring Group Dashboard -->
                    <div class="wSection">
                        <h4 class="wSection__title">2) Ring Group Dashboard</h4>
                        <div class="wTableWrap" v-if="ringGroups.length">
                            <table class="wTable">
                                <thead>
                                    <tr><th>Ring Group / Queue</th><th class="wTh--num">Calls</th><th class="wTh--num">Missed</th><th class="wTh--num">Abandoned</th><th class="wTh--num">Minutes</th><th>Time Sink Categories</th><th class="wTh--num">Score</th></tr>
                                </thead>
                                <tbody>
                                    <tr v-for="row in ringGroups" :key="row.ring_group">
                                        <td>
                                            <div style="font-weight:500">{{ row.ring_group_name || row.ring_group }}</div>
                                            <div v-if="(row.top_categories||[]).length" style="font-size:0.82em;color:var(--admin-muted,#6b7280);margin-top:2px">{{ row.top_categories.slice(0,2).map(c=>c.name).join(', ') }}</div>
                                        </td>
                                        <td class="wTd--num wMono">{{ row.total_calls }}</td>
                                        <td class="wTd--num wMono"><span :style="row.missed_calls>0?'color:#dc2626;font-weight:600':''">{{ row.missed_calls }}</span></td>
                                        <td class="wTd--num wMono">{{ row.abandoned_calls }}</td>
                                        <td class="wTd--num wMono">{{ row.total_minutes }}</td>
                                        <td style="font-size:0.85em">
                                            <span v-if="(row.time_sink_categories||[]).length">
                                                <span v-for="(cat,i) in (row.time_sink_categories||[]).slice(0,2)" :key="i">{{ cat.name }}{{ cat.minutes?` (${cat.minutes}m)`:'' }}{{ i<Math.min((row.time_sink_categories||[]).length,2)-1?', ':'' }}</span>
                                            </span>
                                            <span v-else class="wMuted">—</span>
                                        </td>
                                        <td class="wTd--num wMono">{{ row.automation_priority_score }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div v-else class="wMuted">
                            <p style="margin:0 0 8px">Ring group analytics unavailable</p>
                            <p style="margin:0;font-size:0.9em;line-height:1.4">This requires queue/department routing data from your PBX server. Please verify that your PBX is configured to capture and return queue assignments for calls.</p>
                        </div>
                    </div>

                    <!-- 3) Extension Leaderboard -->
                    <div class="wSection">
                        <h4 class="wSection__title">3) Extension Leaderboard</h4>
                        <div class="wTableWrap" v-if="extensions.length">
                            <table class="wTable">
                                <thead><tr><th>Extension</th><th class="wTh--num">Answered</th><th class="wTh--num">Minutes</th><th>Top 3 Categories</th><th class="wTh--num">Repetitive %</th><th class="wTh--num">Impact Score</th></tr></thead>
                                <tbody>
                                    <tr v-for="row in extensions" :key="row.extension">
                                        <td>{{ row.extension }}</td>
                                        <td class="wTd--num wMono">{{ row.calls_answered }}</td>
                                        <td class="wTd--num wMono">{{ row.total_minutes }}</td>
                                        <td>{{ topCategoriesLabel(row.top_categories) }}</td>
                                        <td class="wTd--num wMono">{{ row.repetitive_percentage }}</td>
                                        <td class="wTd--num wMono">{{ row.automation_impact_score }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div v-else class="wMuted">No extension performance data yet</div>
                    </div>

                    <!-- 4) Extension Scorecards -->
                    <div class="wSection">
                        <h4 class="wSection__title">4) Extension Scorecards</h4>
                        <div v-if="scorecards.length" class="wInsightList">
                            <div v-for="card in scorecards" :key="card.extension" class="wInsightCard">
                                <div class="wInsightCard__header"><strong>Extension {{ card.extension }}</strong></div>
                                <div class="wInsightCard__body">
                                    <div class="wMuted">Top automation candidates:</div>
                                    <ul><li v-for="(c,i) in card.top_automation_candidates||[]" :key="`${card.extension}-${i}`">{{ c.category_name || `Category #${c.category_id}` }}</li></ul>
                                    <div class="wMuted">Recommended actions:</div>
                                    <ul><li v-for="(a,i) in card.recommended_actions||[]" :key="`${card.extension}-a-${i}`">{{ a }}</li></ul>
                                    <div class="wMuted" style="margin-top:8px">Recent timeline:</div>
                                    <div class="wTableWrap" v-if="(card.timeline||[]).length">
                                        <table class="wTable wTable--mini">
                                            <thead><tr><th>Time</th><th class="wTh--num">Sec</th><th>Category</th></tr></thead>
                                            <tbody>
                                                <tr v-for="item in (card.timeline||[]).slice(0,8)" :key="`${card.extension}-t-${item.call_id}`">
                                                    <td class="wMono">{{ fmtDateTime(item.started_at) }}</td>
                                                    <td class="wTd--num wMono">{{ item.duration_seconds }}</td>
                                                    <td>{{ item.category_name || "—" }}</td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                    <div v-else class="wMuted">No timeline entries</div>
                                    <div class="wMuted" style="margin-top:8px">Top 5 examples:</div>
                                    <div v-if="(card.examples||[]).length">
                                        <div v-for="ex in card.examples" :key="`${card.extension}-e-${ex.call_id}`" style="margin-bottom:8px">
                                            <div class="wMono" style="font-size:0.85em">{{ fmtDateTime(ex.started_at) }} <a :href="ex.recording_or_transcript_link" style="margin-left:6px">Open</a></div>
                                            <div>{{ ex.snippet }}</div>
                                        </div>
                                    </div>
                                    <div v-else class="wMuted">No transcript examples</div>
                                </div>
                            </div>
                        </div>
                        <div v-else class="wMuted">No extension scorecards yet</div>
                    </div>

                    <!-- 5) Category Drill-down -->
                    <div class="wSection">
                        <h4 class="wSection__title">5) Category Drill-down</h4>
                        <div v-if="drilldown.length" class="wInsightList">
                            <div v-for="row in drilldown" :key="row.category_id" class="wInsightCard" style="margin-bottom:16px">
                                <div class="wInsightCard__header" style="display:flex;justify-content:space-between;align-items:baseline;flex-wrap:wrap;gap:8px">
                                    <strong>{{ row.category_name || `Category #${row.category_id}` }}</strong>
                                    <span class="wMono" style="font-size:0.85em;color:var(--admin-muted,#6b7280)">{{ row.total_calls }} calls · {{ row.total_minutes ?? 0 }}m</span>
                                </div>
                                <div class="wInsightCard__body">
                                    <div class="wKvGrid" style="margin-top:8px">
                                        <div class="wKv">
                                            <div class="wKv__k">Top Extensions</div>
                                            <div class="wKv__v wMono" style="font-size:0.85em">
                                                <span v-if="Object.keys(row.extension_breakdown||{}).length">
                                                    <span v-for="(count,ext,i) in getSortedBreakdown(row.extension_breakdown,3)" :key="ext">{{ ext }} ({{ count }}){{ i < Object.keys(getSortedBreakdown(row.extension_breakdown,3)).length-1?', ':'' }}</span>
                                                </span>
                                                <span v-else class="wMuted">—</span>
                                            </div>
                                        </div>
                                        <div class="wKv">
                                            <div class="wKv__k">Ring Groups</div>
                                            <div class="wKv__v wMono" style="font-size:0.85em">
                                                <span v-if="Object.keys(row.ring_group_breakdown||{}).length">
                                                    <span v-for="(count,rg,i) in getSortedBreakdown(row.ring_group_breakdown,3)" :key="rg">{{ rg }} ({{ count }}){{ i < Object.keys(getSortedBreakdown(row.ring_group_breakdown,3)).length-1?', ':'' }}</span>
                                                </span>
                                                <span v-else class="wMuted">—</span>
                                            </div>
                                        </div>
                                        <div class="wKv">
                                            <div class="wKv__k">Trend</div>
                                            <div class="wKv__v">{{ trendLabel(row.trend_direction, row.trend_percentage_change) }} <span class="wMono" style="font-size:0.85em;margin-left:4px">{{ trendSparkline(row.daily_trend) }}</span></div>
                                        </div>
                                        <div class="wKv" style="grid-column:1/-1">
                                            <div class="wKv__k">Suggested Automations</div>
                                            <div class="wKv__v">
                                                <ul v-if="(row.suggested_automations||[]).length" style="margin:0;padding-left:16px">
                                                    <li v-for="(item,i) in row.suggested_automations" :key="i">
                                                        {{ typeof item==='object'?item.suggestion:item }}<span v-if="typeof item==='object'&&item.impact" style="color:var(--admin-muted,#6b7280);font-size:0.85em"> — {{ item.impact }}</span>
                                                    </li>
                                                </ul>
                                                <span v-else class="wMuted">—</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div v-else class="wMuted">No category drill-down analytics yet</div>
                    </div>
                </div>
            </div>
        </template>
    </div>
</template>

<style scoped>
/* ── Layout ─────────────────────────────────────────────────────────────── */
.wPage {
    display: flex; flex-direction: column; gap: 1rem;
    /* force width to parent, never expand beyond it */
    width: 100%; max-width: 100%;
    min-width: 0; overflow-x: hidden;
    box-sizing: border-box;
}

/* ── Header ─────────────────────────────────────────────────────────────── */
.wHeader {
    display: flex; align-items: flex-start; justify-content: space-between;
    gap: 1rem; flex-wrap: wrap;
    padding: 1.25rem 1.5rem;
    background: var(--bg-surface, #fff);
    border: 1px solid var(--border-default, rgba(15,23,42,0.12));
    border-radius: 14px;
    /* prevent overflow from long company names */
    min-width: 0; overflow: hidden;
}
.wHeader__left {
    display: flex; align-items: flex-start; gap: 1rem;
    flex: 1 1 0; min-width: 0; /* allows shrinking so title wraps */
}
.wHeader__content { min-width: 0; flex: 1; }
.wHeader__icon {
    width: 44px; height: 44px; border-radius: 10px; flex-shrink: 0;
    background: var(--accent-soft, rgba(37,99,235,0.1));
    color: var(--accent-primary, #2563eb);
    display: grid; place-items: center;
}
.wHeader__icon svg { width: 20px; height: 20px; }
.wHeader__breadcrumb {
    display: flex; align-items: center; gap: 6px;
    font-size: 0.82rem; opacity: 0.65; margin-bottom: 4px;
    flex-wrap: wrap;
}
.wBreadLink { font-size: 0.82rem; color: var(--accent-primary, #2563eb); text-decoration: none; }
.wBreadLink:hover { text-decoration: underline; }
.wBreadSep { opacity: 0.4; }
.wHeader__title {
    font-size: 1.25rem; font-weight: 800; margin: 0 0 2px;
    /* long company names must wrap, not overflow */
    word-break: break-word; overflow-wrap: break-word;
}
.wHeader__subtitle { font-size: 0.85rem; opacity: 0.65; margin: 0; word-break: break-word; }
.wHeader__stats { display: flex; gap: 1rem; align-items: flex-start; flex-shrink: 0; }
.wHeader__stat {
    padding: 0.5rem 1rem; border-radius: 8px; text-align: center; min-width: 90px;
    background: var(--accent-soft, rgba(37,99,235,0.08));
}
.wHeader__statLabel { font-size: 0.7rem; opacity: 0.6; text-transform: uppercase; letter-spacing: 0.05em; }
.wHeader__statValue { font-size: 1rem; font-weight: 700; margin-top: 2px; }
.wHeader__actions   { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; flex-shrink: 0; }

/* ── Buttons ─────────────────────────────────────────────────────────────── */
.wBtn {
    display: inline-flex; align-items: center; gap: 6px; height: 34px; padding: 0 12px;
    border-radius: 8px; font-size: 0.82rem; font-weight: 600; border: none; cursor: pointer;
    white-space: nowrap; text-decoration: none;
}
.wBtn--ghost   { background: transparent; color: inherit; border: 1px solid var(--border-default, rgba(15,23,42,0.12)); }
.wBtn--ghost:hover:not(:disabled) { background: var(--bg-surface-2, #f2f5fb); }
.wBtn--primary { background: var(--accent-primary, #2563eb); color: #fff; }
.wBtn--primary:hover { filter: brightness(1.08); }
.wBtn:disabled { opacity: 0.45; cursor: not-allowed; }
.wBtn__icon    { width: 14px; height: 14px; flex-shrink: 0; }

/* ── Alerts ─────────────────────────────────────────────────────────────── */
.wAlert { padding: 10px 14px; border-radius: 8px; font-size: 0.875rem; word-break: break-word; }
.wAlert--error { background: rgba(239,68,68,0.1); border: 1px solid #ef4444; color: #ef4444; }
.wAlert--warn  { background: rgba(245,158,11,0.1); border: 1px solid #f59e0b; color: #b45309; }

/* ── Cards ──────────────────────────────────────────────────────────────── */
.wCard {
    background: var(--bg-surface, #fff);
    border: 1px solid var(--border-default, rgba(15,23,42,0.12));
    border-radius: 14px; padding: 1.25rem;
    display: flex; flex-direction: column; gap: 16px;
    /* prevent content from overflowing card bounds */
    min-width: 0; overflow: hidden;
}
.wCard__title { font-size: 1rem; font-weight: 700; margin: 0; word-break: break-word; }
.wCard__desc  { font-size: 0.82rem; opacity: 0.55; margin-top: -8px; }

/* ── KV Grid ────────────────────────────────────────────────────────────── */
.wKvGrid       { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 12px; }
.wKvGrid--3col { grid-template-columns: repeat(3, 1fr); }
.wKv    { display: flex; flex-direction: column; gap: 4px; min-width: 0; }
.wKv__k { font-size: 0.72rem; font-weight: 600; opacity: 0.55; text-transform: uppercase; letter-spacing: 0.04em; }
.wKv__v { font-size: 0.9rem; word-break: break-word; overflow-wrap: break-word; }
.wMono  { font-family: ui-monospace, monospace; font-size: 0.82rem; }
.wMuted { opacity: 0.6; font-size: 0.875rem; }

/* ── Badge ──────────────────────────────────────────────────────────────── */
.wBadge { padding: 2px 9px; border-radius: 999px; font-size: 0.72rem; font-weight: 700; display: inline-block; white-space: nowrap; }
.wBadge.v--active     { background: rgba(16,185,129,0.14); color: #059669; }
.wBadge.v--failed     { background: rgba(239,68,68,0.14);  color: #dc2626; }
.wBadge.v--processing { background: rgba(37,99,235,0.14);  color: #2563eb; }

/* ── Prose ──────────────────────────────────────────────────────────────── */
.wProse { font-size: 0.9rem; line-height: 1.7; margin: 0; word-break: break-word; overflow-wrap: break-word; }

/* ── Metric Cards ───────────────────────────────────────────────────────── */
.wMetricsGrid { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 12px; }
.wMetricCard  {
    border: 1px solid var(--border-default, rgba(15,23,42,0.12));
    border-radius: 10px; padding: 14px; background: var(--bg-surface, #fff);
    min-width: 0;
}
.wMetricCard--success { background: rgba(16,185,129,0.05); border-color: rgba(16,185,129,0.2); }
.wMetricCard--warning { background: rgba(245,158,11,0.05); border-color: rgba(245,158,11,0.2); }
.wMetricCard__header  { display: flex; align-items: center; gap: 8px; margin-bottom: 8px; }
.wMetricCard__icon    {
    width: 28px; height: 28px; border-radius: 7px; flex-shrink: 0;
    background: var(--accent-soft, rgba(37,99,235,0.1)); color: var(--accent-primary, #2563eb);
    display: grid; place-items: center;
}
.wMetricCard__icon svg { width: 14px; height: 14px; }
.wMetricCard--success .wMetricCard__icon { background: rgba(16,185,129,0.12); color: #059669; }
.wMetricCard--warning .wMetricCard__icon { background: rgba(245,158,11,0.12); color: #b45309; }
.wMetricCard__label { font-size: 0.78rem; font-weight: 600; opacity: 0.65; }
.wMetricCard__value { font-size: 1.6rem; font-weight: 800; line-height: 1; }
.wMetricCard__sub   { font-size: 0.75rem; opacity: 0.6; margin-top: 4px; }

/* ── Time Range ─────────────────────────────────────────────────────────── */
.wTimeRange { display: flex; gap: 2rem; flex-wrap: wrap; padding-top: 4px; border-top: 1px solid var(--border-default, rgba(15,23,42,0.12)); }

/* ── Tables ─────────────────────────────────────────────────────────────── */
.wTableWrap {
    overflow-x: auto; border-radius: 8px;
    border: 1px solid var(--border-default, rgba(15,23,42,0.12));
    /* -webkit-overflow-scrolling for iOS momentum scroll */
    -webkit-overflow-scrolling: touch;
}
.wTable     { width: 100%; border-collapse: collapse; font-size: 0.875rem; }
.wTable th  {
    background: var(--bg-surface-2, #f2f5fb); font-size: 0.72rem; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.04em; opacity: 0.7;
    padding: 8px 12px; text-align: left; white-space: nowrap;
    border-bottom: 1px solid var(--border-default, rgba(15,23,42,0.12));
}
.wTable td  { padding: 8px 12px; border-bottom: 1px solid var(--border-default, rgba(15,23,42,0.12)); }
.wTable tbody tr:last-child td { border-bottom: none; }
.wTable tbody tr:hover td { background: var(--bg-surface-2, #f2f5fb); }
.wTable--mini     { font-size: 0.82rem; }
.wTable--mini td  { padding: 5px 8px; }
.wTh--num { text-align: right !important; }
.wTd--num { text-align: right; }

/* ── Percent Bar ────────────────────────────────────────────────────────── */
.wPctBar        { display: inline-flex; align-items: center; gap: 8px; width: 120px; }
.wPctBar__fill  { height: 6px; border-radius: 3px; background: rgba(37,99,235,0.55); flex-shrink: 0; }
.wPctBar__label { font-size: 0.75rem; opacity: 0.7; white-space: nowrap; }

/* ── Section headers ─────────────────────────────────────────────────────── */
.wSections { display: flex; flex-direction: column; gap: 20px; }
.wSection   {
    display: flex; flex-direction: column; gap: 12px;
    padding-top: 16px; border-top: 1px solid var(--border-default, rgba(15,23,42,0.12));
    min-width: 0;
}
.wSection:first-child { border-top: none; padding-top: 0; }
.wSection__title {
    font-size: 0.9rem; font-weight: 700; margin: 0;
    display: flex; align-items: center; gap: 8px;
    word-break: break-word;
}
.wSection__icon  { width: 22px; height: 22px; border-radius: 6px; display: grid; place-items: center; flex-shrink: 0; }
.wSection__icon--opp { background: rgba(16,185,129,0.12); color: #059669; }
.wSection__icon--rec { background: var(--accent-soft, rgba(37,99,235,0.1)); color: var(--accent-primary, #2563eb); }
.wSection__icon svg  { width: 12px; height: 12px; }
.wSubsection        { display: flex; flex-direction: column; gap: 8px; margin-top: 4px; }
.wSubsection__title { font-size: 0.82rem; font-weight: 700; opacity: 0.75; margin: 0; }

/* ── Sub-category cards ─────────────────────────────────────────────────── */
.wSubCatGrid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 12px; }
.wSubCatCard { border: 1px solid var(--border-default, rgba(15,23,42,0.12)); border-radius: 10px; overflow: hidden; min-width: 0; }
.wSubCatCard__header { display: flex; justify-content: space-between; align-items: center; padding: 8px 12px; background: var(--bg-surface-2, #f2f5fb); gap: 8px; }
.wSubCatCard__name   { font-weight: 600; font-size: 0.875rem; word-break: break-word; min-width: 0; }
.wSubCatCard__count  { font-size: 0.78rem; opacity: 0.6; white-space: nowrap; flex-shrink: 0; }

/* ── Hourly grid ─────────────────────────────────────────────────────────── */
.wHourlyGrid { display: flex; flex-wrap: wrap; gap: 6px; }
.wHourlyCell {
    display: flex; flex-direction: column; align-items: center; gap: 2px;
    min-width: 40px; padding: 6px 8px; border-radius: 8px;
    background: var(--bg-surface-2, #f2f5fb);
    border: 1px solid var(--border-default, rgba(15,23,42,0.12));
    font-size: 0.72rem;
}
.wHourlyCell--peak {
    background: var(--accent-soft, rgba(37,99,235,0.1));
    border-color: var(--accent-border, rgba(37,99,235,0.22));
    color: var(--accent-primary, #2563eb);
}
.wHourlyCell__hour  { opacity: 0.65; }
.wHourlyCell__count { font-weight: 700; font-size: 0.82rem; }

/* ── Sample calls ───────────────────────────────────────────────────────── */
.wSampleSection        { display: flex; flex-direction: column; gap: 8px; }
.wSampleSection__title { font-size: 0.82rem; font-weight: 600; opacity: 0.7; margin: 0; }
.wSampleList  { display: flex; flex-direction: column; gap: 8px; }
.wSampleCall  {
    border: 1px solid var(--border-default, rgba(15,23,42,0.12));
    border-radius: 8px; padding: 10px 12px; min-width: 0; overflow: hidden;
}
.wSampleCall__meta       { display: flex; align-items: center; gap: 10px; margin-bottom: 6px; flex-wrap: wrap; }
.wSampleCall__tag        { font-size: 0.72rem; padding: 1px 7px; border-radius: 999px; background: var(--bg-surface-2, #f2f5fb); border: 1px solid var(--border-default, rgba(15,23,42,0.12)); white-space: nowrap; }
.wSampleCall__transcript { font-size: 0.82rem; line-height: 1.5; opacity: 0.8; word-break: break-word; }

/* ── Insight cards ──────────────────────────────────────────────────────── */
.wInsightList { display: flex; flex-direction: column; gap: 12px; }
.wInsightCard { border: 1px solid var(--border-default, rgba(15,23,42,0.12)); border-radius: 10px; overflow: hidden; min-width: 0; }
.wInsightCard--opp { border-color: rgba(16,185,129,0.3); }
.wInsightCard__header {
    display: flex; align-items: center; gap: 10px; padding: 10px 14px;
    background: var(--bg-surface-2, #f2f5fb);
    border-bottom: 1px solid var(--border-default, rgba(15,23,42,0.12));
    flex-wrap: wrap;
}
.wInsightCard__category { font-size: 0.82rem; font-weight: 600; opacity: 0.8; word-break: break-word; }
.wInsightCard__body     { padding: 12px 14px; display: flex; flex-direction: column; gap: 10px; }
.wInsightCard__reason   { margin: 0; font-size: 0.875rem; line-height: 1.5; word-break: break-word; }
.wInsightMetrics { display: flex; gap: 16px; flex-wrap: wrap; }
.wInsightMetric  { display: flex; flex-direction: column; gap: 2px; }
.wInsightMetric__label { font-size: 0.7rem; font-weight: 600; opacity: 0.55; text-transform: uppercase; letter-spacing: 0.04em; }
.wInsightMetric__value { font-size: 0.875rem; font-weight: 600; word-break: break-word; }
.wInsightMetric__sub   { font-size: 0.78rem; opacity: 0.6; }

/* ── Recommendation cards ───────────────────────────────────────────────── */
.wRecList { display: flex; flex-direction: column; gap: 10px; }
.wRecCard {
    border: 1px solid var(--border-default, rgba(15,23,42,0.12));
    border-radius: 10px; padding: 12px 14px;
    display: flex; flex-direction: row; gap: 12px; align-items: flex-start;
    min-width: 0;
}
.wRecCard--warning { background: rgba(245,158,11,0.06); border-color: rgba(245,158,11,0.3); }
.wRecCard--info    { background: rgba(37,99,235,0.06);  border-color: rgba(37,99,235,0.22); }
.wRecCard__icon    {
    width: 32px; height: 32px; border-radius: 8px; flex-shrink: 0;
    display: grid; place-items: center;
    background: var(--accent-soft, rgba(37,99,235,0.1));
    color: var(--accent-primary, #2563eb);
}
.wRecCard--warning .wRecCard__icon { background: rgba(245,158,11,0.12); color: #b45309; }
.wRecCard__icon svg { width: 16px; height: 16px; }
.wRecCard__content  { display: flex; flex-direction: column; gap: 4px; flex: 1; min-width: 0; }
.wRecCard__type     { font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; opacity: 0.65; }
.wRecCard__message  { margin: 0; font-size: 0.875rem; line-height: 1.5; word-break: break-word; }
.wRecCard__tags     { display: flex; gap: 6px; flex-wrap: wrap; }
.wRecTag { font-size: 0.72rem; padding: 2px 8px; border-radius: 999px; background: var(--bg-surface-2, #f2f5fb); border: 1px solid var(--border-default, rgba(15,23,42,0.12)); }

/* ── Empty ──────────────────────────────────────────────────────────────── */
.wEmpty        { padding: 2rem; text-align: center; }
.wEmpty__title { font-size: 0.9rem; font-weight: 600; margin-bottom: 4px; }
.wEmpty__sub   { font-size: 0.82rem; opacity: 0.55; }

/* ── Skeleton ───────────────────────────────────────────────────────────── */
.wSkelLines { display: flex; flex-direction: column; gap: 10px; }
.wSkelLines::before, .wSkelLines::after {
    content: ''; height: 16px; border-radius: 6px;
    background: var(--bg-soft, rgba(15,23,42,0.04));
    animation: wPulse 1.2s ease-in-out infinite;
}
@keyframes wPulse { 0%,100% { opacity: 1; } 50% { opacity: 0.4; } }

/* ── Responsive ─────────────────────────────────────────────────────────── */

/* Tablet / large mobile */
@media (max-width: 768px) {
    .wHeader {
        flex-direction: column;
        padding: 1rem;
    }
    .wHeader__left   { width: 100%; }
    .wHeader__stats  { width: 100%; }
    .wHeader__stat   { flex: 1; }
    .wHeader__actions { width: 100%; }

    .wKvGrid--3col { grid-template-columns: 1fr 1fr; }
    .wMetricsGrid  { grid-template-columns: 1fr 1fr; }
    .wPctBar { width: 80px; }
    .wCard   { padding: 1rem; }
}

/* Small mobile */
@media (max-width: 520px) {
    .wHeader { padding: 0.875rem; gap: 0.75rem; }
    .wHeader__title    { font-size: 1.05rem; }
    .wHeader__icon     { width: 36px; height: 36px; }
    .wHeader__icon svg { width: 16px; height: 16px; }

    .wHeader__stats  { flex-direction: row; gap: 0.5rem; }
    .wHeader__stat   { min-width: 0; padding: 0.375rem 0.75rem; }
    .wHeader__statValue { font-size: 0.9rem; }

    .wKvGrid--3col { grid-template-columns: 1fr; }
    .wMetricsGrid  { grid-template-columns: 1fr 1fr; }
    .wCard  { padding: 0.875rem; gap: 12px; }

    /* metric values slightly smaller on tiny screens */
    .wMetricCard__value { font-size: 1.35rem; }

    /* hourly cells slightly smaller */
    .wHourlyCell { min-width: 36px; padding: 5px 6px; }

    /* sub-category grid single column */
    .wSubCatGrid { grid-template-columns: 1fr; }

    /* percent bar narrower */
    .wPctBar { width: 60px; }
}

/* Extra-small (≤360px phones) */
@media (max-width: 360px) {
    .wMetricsGrid { grid-template-columns: 1fr; }
    .wHeader__stats { flex-direction: column; gap: 0.5rem; }
    .wHeader__stat  { width: 100%; }
}
</style>
