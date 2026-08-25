<script setup lang="ts">
import { computed, onMounted, ref, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import { userApi } from "../api/user";

// ── Types ──────────────────────────────────────────────────────────────────
interface WeekRange { start: string; end: string; formatted: string; }
interface ReportHeader {
    id: number;
    company: { id: number; name: string };
    pbx_account: { id: number; name: string; display?: string; server_id?: number } | null;
    week_range: WeekRange;
    generated_at: string;
    status: string;
    ai_incomplete?: boolean;
    ai_incomplete_call_count?: number;
}
interface Metrics {
    total_calls: number;
    answered_calls: number;
    missed_calls: number;
    answer_rate: number;
    calls_with_transcription: number;
    transcription_rate: number;
    total_call_duration_seconds: number;
    avg_call_duration_seconds: number;
    avg_call_duration_formatted: string;
    first_call_at: string;
    last_call_at: string;
}
interface Breakdowns {
    counts: Record<string, number>;
    details: Record<string, any>;
    top_dids: { did: string; calls: number }[];
    hourly_distribution: Record<number, number>;
    totals?: { categorized_calls?: number; report_total_calls?: number | null };
}
interface Insight { type: string; category?: string; reason?: string; call_count?: number; percentage?: number; top_sub_category?: string; top_sub_category_count?: number; top_sub_category_percentage?: number; message?: string; hours?: number[]; }
interface AiSummary { ai_summary: string; recommendations: string[]; risks: string[]; automation_opportunities: string[]; }
interface Endpoint { call_id?: number; started_at?: string; from?: string; to?: string; status?: string; duration_seconds?: number; }
interface ReportData {
    header: ReportHeader;
    executive_summary: string;
    metrics: Metrics;
    category_breakdowns: Breakdowns;
    insights: { ai_opportunities: Insight[]; recommendations: Insight[] };
    ai_summary?: AiSummary;
    exports: { pdf_available: boolean; csv_available: boolean };
    call_endpoints?: Endpoint[];
    advanced_views?: any;
}

const route  = useRoute();
const router = useRouter();

const loading = ref(true);
const error   = ref("");
const report  = ref<ReportData | null>(null);

// ── Computed: Executive Summary ────────────────────────────────────────────
const summaryHtml = computed(() => {
    const raw = report.value?.executive_summary || "";
    const escaped = raw
        .replace(/&/g, "&amp;").replace(/</g, "&lt;")
        .replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#39;");
    return escaped.replace(/\*\*(.+?)\*\*/g, "<strong>$1</strong>").replace(/\n/g, "<br>");
});

// ── Computed: Header subtitle ──────────────────────────────────────────────
const subtitleText = computed(() => {
    const wr = report.value?.header?.week_range;
    if (!wr) return "";
    if (wr.formatted) return `Week of ${wr.formatted}`;
    if (wr.start && wr.end) return `Week of ${wr.start} to ${wr.end}`;
    return "";
});

// ── Computed: Quantitative ─────────────────────────────────────────────────
const missedRate = computed(() => {
    const t = report.value?.metrics?.total_calls || 0;
    const m = report.value?.metrics?.missed_calls || 0;
    if (t === 0) return 0;
    return Math.round((m / t) * 100 * 10) / 10;
});

// ── Computed: Category Breakdowns ─────────────────────────────────────────
const bd = computed(() => report.value?.category_breakdowns ?? null);

const hasCategories = computed(() => bd.value && Object.keys(bd.value.counts ?? {}).length > 0);
const hasSubCategories = computed(() => {
    const d = bd.value?.details;
    if (!d) return false;
    return Object.values(d).some((c: any) => c.sub_categories && Object.keys(c.sub_categories).length > 0);
});
const hasTopDids    = computed(() => (bd.value?.top_dids?.length ?? 0) > 0);
const hasHourlyData = computed(() => bd.value?.hourly_distribution && Object.keys(bd.value.hourly_distribution).length > 0);
const hasSampleCalls = computed(() => {
    const d = bd.value?.details;
    if (!d) return false;
    return Object.values(d).some((c: any) => c.sample_calls?.length > 0);
});

const totalCategorizedCalls = computed(() => {
    const counts = bd.value?.counts ?? {};
    return Object.values(counts).reduce((s, c) => s + (c as number), 0);
});
const categorizedCalls  = computed(() => Number(bd.value?.totals?.categorized_calls ?? totalCategorizedCalls.value) || 0);
const reportTotalCalls  = computed<number | null>(() => {
    const v = bd.value?.totals?.report_total_calls;
    if (v === null || v === undefined) return null;
    const n = Number(v);
    return Number.isFinite(n) ? n : null;
});

/**
 * Report breakdowns are keyed "{id}|{name}" (see GenerateWeeklyPbxReportsJob)
 * so categories stay distinct even if two share a name. Only the name is
 * meaningful to a reader, so strip the id for display while keeping the raw
 * key for :key uniqueness. Guarded on a numeric prefix so a category whose
 * name legitimately contains "|" is left intact.
 */
function labelFromKey(key: string): string {
    const raw = String(key ?? "");
    const sep = raw.indexOf("|");
    if (sep === -1) return raw;
    return /^\d+$/.test(raw.slice(0, sep)) ? raw.slice(sep + 1) : raw;
}

const sortedCategories = computed(() => {
    const counts = bd.value?.counts ?? {};
    const total  = totalCategorizedCalls.value;
    return Object.entries(counts)
        .map(([key, count]) => ({ key, name: labelFromKey(key), count: count as number, percent: total > 0 ? Math.round((count as number) / total * 1000) / 10 : 0 }))
        .sort((a, b) => b.count - a.count);
});

const categoriesWithSubs = computed(() => {
    const details = bd.value?.details ?? {};
    return Object.entries(details)
        .filter(([, c]: any) => c.sub_categories && Object.keys(c.sub_categories).length > 0)
        .map(([key, c]: any) => {
            const subTotal = Object.values(c.sub_categories as Record<string,number>).reduce((s, v) => s + v, 0);
            return {
                key, name: labelFromKey(key), count: c.count as number,
                subCategories: Object.entries(c.sub_categories as Record<string,number>)
                    .map(([sk, sc]) => ({ key: sk, name: labelFromKey(sk), count: sc, percent: subTotal > 0 ? Math.round(sc / subTotal * 1000) / 10 : 0 }))
                    .sort((a, b) => b.count - a.count),
            };
        })
        .sort((a, b) => b.count - a.count);
});

const categoriesWithSamples = computed(() => {
    const details = bd.value?.details ?? {};
    return Object.entries(details)
        .filter(([, c]: any) => c.sample_calls?.length > 0)
        .map(([key, c]: any) => ({ key, name: labelFromKey(key), samples: c.sample_calls }))
        .sort((a, b) => b.samples.length - a.samples.length);
});

const hourlyData = computed(() => {
    const dist = bd.value?.hourly_distribution ?? {};
    const maxCount = Math.max(...Object.values(dist).map(Number), 1);
    const peakThreshold = maxCount * 0.7;
    return Array.from({ length: 24 }, (_, hour) => ({
        hour,
        label: fmtHour(hour),
        count: (dist[hour] as number) || 0,
        isPeak: ((dist[hour] as number) || 0) >= peakThreshold,
    }));
});

// ── Computed: Insights ─────────────────────────────────────────────────────
const hasOpportunities   = computed(() => (report.value?.insights?.ai_opportunities?.length ?? 0) > 0);
const hasRecommendations = computed(() => (report.value?.insights?.recommendations?.length ?? 0) > 0);
const hasInsights        = computed(() => hasOpportunities.value || hasRecommendations.value);

// ── Computed: Advanced Views ───────────────────────────────────────────────
const adv         = computed(() => report.value?.advanced_views ?? null);
const company     = computed(() => adv.value?.company_dashboard ?? {});
const companySummary = computed(() => company.value.summary ?? {});
const trend       = computed(() => company.value.trend_vs_last_period ?? { has_previous: false });
const ringGroups  = computed(() => adv.value?.ring_group_dashboard ?? []);
const extensions  = computed(() => adv.value?.extension_leaderboard ?? []);
const scorecards  = computed(() => adv.value?.extension_scorecards ?? []);
const drilldown   = computed(() => adv.value?.category_drilldown ?? []);
const hasAdvanced = computed(() =>
    (company.value.top_categories?.length ?? 0) > 0 ||
    ringGroups.value.length > 0 || extensions.value.length > 0 ||
    scorecards.value.length > 0 || drilldown.value.length > 0
);

// ── Formatters ────────────────────────────────────────────────────────────
function fmtNum(v: any): string {
    const n = Number(v);
    return Number.isFinite(n) ? n.toLocaleString() : "—";
}
function fmtDateTime(iso: string | null | undefined): string {
    if (!iso) return "—";
    const d = new Date(iso);
    return Number.isFinite(d.getTime()) ? d.toLocaleString() : "—";
}
function fmtDate(iso: string | null | undefined): string {
    if (!iso) return "—";
    const d = new Date(iso);
    return Number.isFinite(d.getTime()) ? d.toLocaleDateString() : "—";
}
function fmtHour(hour: number): string {
    if (hour === 0) return "12a";
    if (hour === 12) return "12p";
    return hour < 12 ? `${hour}a` : `${hour - 12}p`;
}
function fmtHourLong(hour: number): string {
    if (hour === 0) return "12am";
    if (hour === 12) return "12pm";
    return hour < 12 ? `${hour}am` : `${hour - 12}pm`;
}
function fmtTotalDuration(seconds: any): string {
    const s = Number(seconds);
    if (!Number.isFinite(s) || s < 0) return "—";
    const h = Math.floor(s / 3600);
    const m = Math.floor((s % 3600) / 60);
    return h > 0 ? `${h}h ${m}m` : `${m}m`;
}
function fmtAvgDuration(seconds: any): string {
    const s = Number(seconds);
    if (!Number.isFinite(s) || s < 0) return "";
    return `${s} seconds`;
}
function statusVariant(status: string | undefined): string {
    const s = String(status || "").toLowerCase();
    if (s === "completed") return "active";
    if (s === "failed") return "failed";
    return "processing";
}
function pbxAccountLabel(pbx: ReportHeader["pbx_account"]): string {
    if (!pbx) return "—";
    return pbx.display || pbx.name || (pbx.server_id ? `Server ${pbx.server_id}` : "—");
}
function opportunityVariant(type: string): string {
    return type === "automation_candidate" ? "badge--active" : "badge--processing";
}
function formatOpportunityType(type: string): string {
    const map: Record<string, string> = { automation_candidate: "Automation Candidate", sub_category_highlight: "Sub-category Focus" };
    return map[type] ?? type;
}
function formatRecommendationType(type: string): string {
    const map: Record<string, string> = { low_answer_rate: "Answer Rate Alert", high_missed_calls: "Missed Calls Alert", peak_hours: "Peak Hours", after_hours_volume: "After-Hours Volume" };
    return map[type] ?? type;
}
function recommendationClass(type: string): string {
    const map: Record<string, string> = { low_answer_rate: "rRecCard--warning", high_missed_calls: "rRecCard--warning", peak_hours: "rRecCard--info", after_hours_volume: "rRecCard--info" };
    return map[type] ?? "";
}
function trendLabel(dir: any, pct: any): string {
    if (dir > 0) return `Up ${pct ?? 0}%`;
    if (dir < 0) return `Down ${Math.abs(pct ?? 0)}%`;
    return "Stable";
}
function trendSparkline(daily: any): string {
    if (!daily || typeof daily !== "object") return "—";
    const vals = Object.values(daily).map(Number).filter(v => Number.isFinite(v));
    if (!vals.length) return "—";
    const bars = "▁▂▃▄▅▆▇█";
    const max = Math.max(...vals, 1);
    return vals.slice(-10).map(v => bars[Math.min(bars.length - 1, Math.floor(v / max * (bars.length - 1)))]).join("");
}
function topCategoriesLabel(cats: any[]): string {
    if (!Array.isArray(cats) || !cats.length) return "—";
    return cats.slice(0, 3).map(c => c?.category_name || `#${c?.category_id ?? "?"}`).join(", ");
}
function getSortedBreakdown(breakdown: any, topN: number): Record<string, number> {
    if (!breakdown || typeof breakdown !== "object") return {};
    return Object.fromEntries(Object.entries(breakdown).sort(([, a], [, b]) => Number(b) - Number(a)).slice(0, topN)) as Record<string, number>;
}
function priorityBadgeStyle(priority: string): string {
    const styles: Record<string, string> = { high: "background:#fee2e2;color:#dc2626", medium: "background:#fef9c3;color:#b45309", low: "background:#dcfce7;color:#16a34a" };
    const base = styles[priority?.toLowerCase?.()] ?? "background:#f3f4f6;color:#6b7280";
    return `${base};border-radius:4px;padding:1px 6px;font-size:0.8em;font-weight:600`;
}

// ── Fetch ─────────────────────────────────────────────────────────────────
async function fetchReport() {
    const id = route.params.id as string;
    loading.value = true;
    error.value   = "";
    try {
        const res   = await userApi.get<{ data: ReportData }>(`/reports/${id}`);
        report.value = res.data?.data ?? null;
    } catch (e: any) {
        report.value = null;
        const s = e?.response?.status;
        error.value = s === 404 ? "Report not found." : s === 403 ? "Access denied." : "Failed to load report.";
    } finally {
        loading.value = false;
    }
}

function goBack() { router.push({ name: "reports" }); }

watch(() => route.params.id, () => { fetchReport(); });
onMounted(() => { fetchReport(); });
</script>

<template>
    <div class="rPage">
        <!-- ── Header ──────────────────────────────────────────────── -->
        <header class="rHeader">
            <div class="rHeader__left">
                <div class="rHeader__icon">
                    <svg viewBox="0 0 24 24" fill="none">
                        <rect x="3" y="3" width="18" height="18" rx="2" stroke="currentColor" stroke-width="1.5"/>
                        <path d="M7 8h10M7 12h10M7 16h5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                    </svg>
                </div>
                <div class="rHeader__content">
                    <div class="rHeader__breadcrumb">
                        <button type="button" class="rBreadLink" @click="goBack">Reports</button>
                        <span class="rBreadSep">/</span>
                        <span>Weekly Report</span>
                    </div>
                    <h1 class="rHeader__title">{{ report?.header?.company?.name || "Weekly Report" }}</h1>
                    <p class="rHeader__subtitle">{{ subtitleText }}</p>
                </div>
            </div>

            <div class="rHeader__stats">
                <div class="rHeader__stat">
                    <div class="rHeader__statLabel">Total Calls</div>
                    <div class="rHeader__statValue">{{ report?.metrics?.total_calls ?? "—" }}</div>
                </div>
                <div class="rHeader__stat">
                    <div class="rHeader__statLabel">Answer Rate</div>
                    <div class="rHeader__statValue">{{ report?.metrics?.answer_rate ?? "—" }}%</div>
                </div>
            </div>

            <div class="rHeader__actions">
                <button type="button" class="rBtn rBtn--ghost" @click="goBack">
                    <svg viewBox="0 0 24 24" fill="none" class="rBtn__icon"><path d="M19 12H5M5 12L12 19M5 12L12 5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    Back
                </button>
                <button type="button" class="rBtn rBtn--ghost" :disabled="loading" @click="fetchReport">
                    <svg viewBox="0 0 24 24" fill="none" class="rBtn__icon"><path d="M20 12a8 8 0 1 1-2.34-5.66" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><path d="M20 4v6h-6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    Refresh
                </button>
                <a
                    v-if="!loading && report?.exports?.pdf_available"
                    class="rBtn rBtn--primary"
                    target="_blank" rel="noopener noreferrer"
                >
                    <svg viewBox="0 0 24 24" fill="none" class="rBtn__icon"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    Export PDF
                </a>
            </div>
        </header>

        <div v-if="error" class="rAlert rAlert--error">{{ error }}</div>

        <!-- ── Skeleton ──────────────────────────────────────────────── -->
        <template v-if="loading">
            <div v-for="n in 5" :key="n" class="rCard">
                <div class="rSkelLines"></div>
            </div>
        </template>

        <template v-else-if="report">
            <!-- AI Incomplete warning -->
            <div v-if="report.header.ai_incomplete" class="rAlert rAlert--warn">
                This report is incomplete — {{ report.header.ai_incomplete_call_count }} calls could not be AI-processed due to credit limits.
            </div>

            <!-- ── 1. Report Information ──────────────────────────────────── -->
            <div class="rCard">
                <div class="rCard__title">Report Information</div>
                <div class="rKvGrid rKvGrid--3col">
                    <div class="rKv"><div class="rKv__k">Company</div><div class="rKv__v">{{ report.header.company?.name || "—" }}</div></div>
                    <div class="rKv"><div class="rKv__k">PBX Account</div><div class="rKv__v">{{ pbxAccountLabel(report.header.pbx_account) }}</div></div>
                    <div class="rKv"><div class="rKv__k">Report ID</div><div class="rKv__v rMono">#{{ report.header.id }}</div></div>
                    <div class="rKv"><div class="rKv__k">Week Range</div><div class="rKv__v">{{ report.header.week_range?.formatted || "—" }}</div></div>
                    <div class="rKv"><div class="rKv__k">Period</div><div class="rKv__v rMono">{{ report.header.week_range?.start }} to {{ report.header.week_range?.end }}</div></div>
                    <div class="rKv">
                        <div class="rKv__k">Status</div>
                        <div class="rKv__v">
                            <span class="rBadge" :class="statusVariant(report.header.status)">{{ (report.header.status || "pending").toUpperCase() }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── 2. Executive Summary ───────────────────────────────────── -->
            <div class="rCard">
                <div class="rCard__title">Executive Summary</div>
                <div v-if="!report.executive_summary" class="rEmpty">
                    <div class="rEmpty__title">No summary available</div>
                    <div class="rEmpty__sub">Executive summary has not been generated for this report.</div>
                </div>
                <p v-else class="rProse" v-html="summaryHtml"></p>
            </div>

            <!-- ── 3. Quantitative Analysis ──────────────────────────────── -->
            <div class="rCard">
                <div class="rCard__title">Quantitative Analysis</div>
                <div class="rCard__desc">Key performance metrics for the reporting period</div>

                <div class="rMetricsGrid">
                    <div class="rMetricCard">
                        <div class="rMetricCard__header">
                            <span class="rMetricCard__icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg></span>
                            <span class="rMetricCard__label">Total Calls</span>
                        </div>
                        <div class="rMetricCard__value">{{ fmtNum(report.metrics?.total_calls) }}</div>
                    </div>
                    <div class="rMetricCard rMetricCard--success">
                        <div class="rMetricCard__header">
                            <span class="rMetricCard__icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg></span>
                            <span class="rMetricCard__label">Answered</span>
                        </div>
                        <div class="rMetricCard__value">{{ fmtNum(report.metrics?.answered_calls) }}</div>
                        <div class="rMetricCard__sub">{{ report.metrics?.answer_rate || 0 }}% answer rate</div>
                    </div>
                    <div class="rMetricCard rMetricCard--warning">
                        <div class="rMetricCard__header">
                            <span class="rMetricCard__icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><line x1="1" y1="1" x2="23" y2="23"/><path d="M16.72 11.06A10.94 10.94 0 0 1 19 12.55"/><path d="M5 12.55a10.94 10.94 0 0 1 5.17-2.39"/><path d="M10.71 5.05A16 16 0 0 1 22.58 9"/><path d="M1.42 9a15.91 15.91 0 0 1 4.7-2.88"/><path d="M8.53 16.11a6 6 0 0 1 6.95 0"/><line x1="12" y1="20" x2="12.01" y2="20"/></svg></span>
                            <span class="rMetricCard__label">Missed</span>
                        </div>
                        <div class="rMetricCard__value">{{ fmtNum(report.metrics?.missed_calls) }}</div>
                        <div class="rMetricCard__sub">{{ missedRate }}% of total</div>
                    </div>
                    <div class="rMetricCard">
                        <div class="rMetricCard__header">
                            <span class="rMetricCard__icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg></span>
                            <span class="rMetricCard__label">Transcribed</span>
                        </div>
                        <div class="rMetricCard__value">{{ fmtNum(report.metrics?.calls_with_transcription) }}</div>
                        <div class="rMetricCard__sub">{{ report.metrics?.transcription_rate || 0 }}% coverage</div>
                    </div>
                    <div class="rMetricCard">
                        <div class="rMetricCard__header">
                            <span class="rMetricCard__icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></span>
                            <span class="rMetricCard__label">Avg Duration</span>
                        </div>
                        <div class="rMetricCard__value">{{ report.metrics?.avg_call_duration_formatted || "—" }}</div>
                        <div class="rMetricCard__sub">{{ fmtAvgDuration(report.metrics?.avg_call_duration_seconds) }}</div>
                    </div>
                    <div class="rMetricCard">
                        <div class="rMetricCard__header">
                            <span class="rMetricCard__icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></span>
                            <span class="rMetricCard__label">Total Duration</span>
                        </div>
                        <div class="rMetricCard__value">{{ fmtTotalDuration(report.metrics?.total_call_duration_seconds) }}</div>
                    </div>
                </div>

                <div class="rTimeRange">
                    <div class="rKv"><div class="rKv__k">First Call</div><div class="rKv__v rMono">{{ fmtDateTime(report.metrics?.first_call_at) }}</div></div>
                    <div class="rKv"><div class="rKv__k">Last Call</div><div class="rKv__v rMono">{{ fmtDateTime(report.metrics?.last_call_at) }}</div></div>
                </div>
            </div>

            <!-- ── 4. Call Endpoints ──────────────────────────────────────── -->
            <div class="rCard">
                <div class="rCard__title">Call Endpoints (From / To)</div>
                <div class="rCard__desc">Real From/To values returned by PBX API for calls in this report</div>
                <div v-if="!report.call_endpoints?.length" class="rEmpty">
                    <div class="rEmpty__title">No From/To endpoint data yet</div>
                    <div class="rEmpty__sub">This report currently has no calls with non-null From/To fields.</div>
                </div>
                <div v-else class="rTableWrap">
                    <table class="rTable">
                        <thead><tr><th>Started At</th><th>From</th><th>To</th><th>Status</th><th class="rTh--num">Duration (s)</th></tr></thead>
                        <tbody>
                            <tr v-for="row in report.call_endpoints" :key="row.call_id">
                                <td>{{ fmtDateTime(row.started_at) }}</td>
                                <td>{{ row.from || "—" }}</td>
                                <td>{{ row.to || "—" }}</td>
                                <td>{{ row.status || "—" }}</td>
                                <td class="rTd--num rMono">{{ Number(row.duration_seconds || 0) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ── 5. Category Breakdowns ────────────────────────────────── -->
            <div class="rCard">
                <div class="rCard__title">Category Breakdowns</div>
                <div class="rCard__desc">Call distribution by category and sub-category</div>

                <div v-if="!hasCategories" class="rEmpty">
                    <div class="rEmpty__title">No category data</div>
                    <div class="rEmpty__sub">No calls have been categorized for this period.</div>
                </div>

                <template v-else>
                    <!-- Category Summary Table -->
                    <div class="rSection">
                        <h4 class="rSection__title">Category Summary</h4>
                        <p class="rMuted" style="margin: 0 0 8px">
                            Percentages are based on categorized calls ({{ categorizedCalls }})
                            <template v-if="reportTotalCalls !== null"> out of total calls ({{ reportTotalCalls }}).</template>
                        </p>
                        <div class="rTableWrap">
                            <table class="rTable">
                                <thead><tr><th>Category</th><th class="rTh--num">Calls</th><th class="rTh--num">% of Total</th></tr></thead>
                                <tbody>
                                    <tr v-for="cat in sortedCategories" :key="cat.key">
                                        <td>{{ cat.name }}</td>
                                        <td class="rTd--num rMono">{{ fmtNum(cat.count) }}</td>
                                        <td class="rTd--num">
                                            <span class="rPctBar">
                                                <span class="rPctBar__fill" :style="{ width: cat.percent + '%' }"></span>
                                                <span class="rPctBar__label">{{ cat.percent }}%</span>
                                            </span>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Sub-category Details -->
                    <div v-if="hasSubCategories" class="rSection">
                        <h4 class="rSection__title">Sub-category Details</h4>
                        <div class="rSubCatGrid">
                            <div v-for="cat in categoriesWithSubs" :key="cat.key" class="rSubCatCard">
                                <div class="rSubCatCard__header">
                                    <span class="rSubCatCard__name">{{ cat.name }}</span>
                                    <span class="rSubCatCard__count">{{ cat.count }} calls</span>
                                </div>
                                <table class="rTable rTable--mini">
                                    <tbody>
                                        <tr v-for="sub in cat.subCategories" :key="sub.key">
                                            <td>{{ sub.name }}</td>
                                            <td class="rTd--num rMono">{{ sub.count }}</td>
                                            <td class="rTd--num">{{ sub.percent }}%</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Top DIDs -->
                    <div v-if="hasTopDids" class="rSection">
                        <h4 class="rSection__title">Top DIDs</h4>
                        <div class="rTableWrap">
                            <table class="rTable">
                                <thead><tr><th>DID</th><th class="rTh--num">Calls</th></tr></thead>
                                <tbody>
                                    <tr v-for="(did, i) in report.category_breakdowns.top_dids" :key="i">
                                        <td class="rMono">{{ did.did }}</td>
                                        <td class="rTd--num rMono">{{ fmtNum(did.calls) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Hourly Distribution -->
                    <div v-if="hasHourlyData" class="rSection">
                        <h4 class="rSection__title">Hourly Distribution</h4>
                        <div class="rHourlyGrid">
                            <div v-for="h in hourlyData" :key="h.hour" class="rHourlyCell" :class="{ 'rHourlyCell--peak': h.isPeak }">
                                <span class="rHourlyCell__hour">{{ h.label }}</span>
                                <span class="rHourlyCell__count">{{ h.count }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Sample Calls -->
                    <div v-if="hasSampleCalls" class="rSection">
                        <h4 class="rSection__title">Sample Calls by Category</h4>
                        <div v-for="cat in categoriesWithSamples" :key="cat.key" class="rSampleSection">
                            <h5 class="rSampleSection__title">{{ cat.name }}</h5>
                            <div class="rSampleList">
                                <div v-for="(sample, idx) in cat.samples" :key="idx" class="rSampleCall">
                                    <div class="rSampleCall__meta">
                                        <span class="rMono">{{ fmtDate(sample.date) }}</span>
                                        <span v-if="sample.did" class="rSampleCall__tag">DID: {{ sample.did }}</span>
                                        <span v-if="sample.src" class="rSampleCall__tag">From: {{ sample.src }}</span>
                                    </div>
                                    <div class="rSampleCall__transcript">{{ sample.transcript }}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </template>
            </div>

            <!-- ── 6. Insights & Recommendations ────────────────────────── -->
            <div class="rCard">
                <div class="rCard__title">Insights &amp; Recommendations</div>
                <div class="rCard__desc">AI opportunities and actionable recommendations</div>

                <div v-if="!hasInsights" class="rEmpty">
                    <div class="rEmpty__title">No insights available</div>
                    <div class="rEmpty__sub">Not enough data to generate insights for this period.</div>
                </div>

                <template v-else>
                    <!-- AI Opportunities -->
                    <div v-if="hasOpportunities" class="rSection">
                        <h4 class="rSection__title">
                            <span class="rSection__icon rSection__icon--opp">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                            </span>
                            AI &amp; Automation Opportunities
                        </h4>
                        <div class="rInsightList">
                            <div v-for="(opp, idx) in report.insights.ai_opportunities" :key="idx" class="rInsightCard rInsightCard--opp">
                                <div class="rInsightCard__header">
                                    <span class="rBadge" :class="opportunityVariant(opp.type as string)">{{ formatOpportunityType(opp.type as string) }}</span>
                                    <span v-if="opp.category" class="rInsightCard__category">{{ opp.category }}</span>
                                </div>
                                <div class="rInsightCard__body">
                                    <p class="rInsightCard__reason">{{ opp.reason }}</p>
                                    <div class="rInsightMetrics">
                                        <div v-if="opp.call_count" class="rInsightMetric">
                                            <span class="rInsightMetric__label">Calls</span>
                                            <span class="rInsightMetric__value">{{ fmtNum(opp.call_count) }}</span>
                                        </div>
                                        <div v-if="opp.percentage" class="rInsightMetric">
                                            <span class="rInsightMetric__label">Share</span>
                                            <span class="rInsightMetric__value">{{ opp.percentage }}%</span>
                                        </div>
                                        <div v-if="opp.top_sub_category" class="rInsightMetric">
                                            <span class="rInsightMetric__label">Top Sub-category</span>
                                            <span class="rInsightMetric__value">
                                                {{ opp.top_sub_category }}
                                                <span class="rInsightMetric__sub">({{ opp.top_sub_category_count }} calls, {{ opp.top_sub_category_percentage }}%)</span>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Recommendations -->
                    <div v-if="hasRecommendations" class="rSection">
                        <h4 class="rSection__title">
                            <span class="rSection__icon rSection__icon--rec">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M14 9V5a3 3 0 0 0-3-3l-4 9v11h11.28a2 2 0 0 0 2-1.7l1.38-9a2 2 0 0 0-2-2.3zM7 22H4a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2h3"/></svg>
                            </span>
                            Recommendations
                        </h4>
                        <div class="rRecList">
                            <div v-for="(rec, idx) in report.insights.recommendations" :key="idx" class="rRecCard" :class="recommendationClass(rec.type as string)">
                                <div class="rRecCard__icon">
                                    <!-- low_answer_rate -->
                                    <svg v-if="rec.type === 'low_answer_rate'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                                    <!-- high_missed_calls -->
                                    <svg v-else-if="rec.type === 'high_missed_calls'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                                    <!-- after_hours_volume -->
                                    <svg v-else-if="rec.type === 'after_hours_volume'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
                                    <!-- peak_hours (default) -->
                                    <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                </div>
                                <div class="rRecCard__content">
                                    <div class="rRecCard__type">{{ formatRecommendationType(rec.type as string) }}</div>
                                    <p class="rRecCard__message">{{ rec.message }}</p>
                                    <div v-if="rec.hours?.length" class="rRecCard__tags">
                                        <span v-for="h in rec.hours" :key="h" class="rRecTag">{{ fmtHourLong(h) }}</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </template>
            </div>

            <!-- ── 7. Automation Priority Views ──────────────────────────── -->
            <div class="rCard">
                <div class="rCard__title">Automation Priority Views</div>
                <div class="rCard__desc">Company, ring group, extension, and category drill-down dashboards</div>

                <div v-if="!hasAdvanced" class="rEmpty">
                    <div class="rEmpty__title">No advanced analytics yet</div>
                    <div class="rEmpty__sub">Run weekly report generation with categorized calls to populate these views.</div>
                </div>

                <div v-else class="rSections">
                    <!-- 1) Company Dashboard -->
                    <div class="rSection">
                        <h4 class="rSection__title">1) Company Dashboard</h4>
                        <div class="rKvGrid">
                            <div class="rKv"><div class="rKv__k">Total Calls</div><div class="rKv__v rMono">{{ companySummary.total_calls ?? 0 }}</div></div>
                            <div class="rKv"><div class="rKv__k">Total Minutes</div><div class="rKv__v rMono">{{ companySummary.total_minutes ?? 0 }}</div></div>
                            <div class="rKv"><div class="rKv__k">Missed Calls</div><div class="rKv__v rMono">{{ companySummary.missed_calls ?? 0 }}</div></div>
                            <div class="rKv">
                                <div class="rKv__k">Trend vs Last Period</div>
                                <div class="rKv__v">
                                    <span v-if="trend.has_previous">
                                        Calls {{ trend.calls_delta > 0 ? '+' : '' }}{{ trend.calls_delta ?? 0 }}
                                        <span v-if="trend.calls_delta_pct !== null" class="rMuted">({{ trend.calls_delta_pct > 0 ? '+' : '' }}{{ trend.calls_delta_pct }}%)</span>
                                    </span>
                                    <span v-else>—</span>
                                </div>
                            </div>
                        </div>

                        <div class="rSubsection">
                            <h5 class="rSubsection__title">Top Categories</h5>
                            <div class="rTableWrap" v-if="(company.top_categories || []).length">
                                <table class="rTable">
                                    <thead><tr><th>Category</th><th class="rTh--num">Calls</th></tr></thead>
                                    <tbody>
                                        <tr v-for="row in company.top_categories" :key="row.name">
                                            <td>{{ labelFromKey(row.name) }}</td>
                                            <td class="rTd--num rMono">{{ row.count }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <div v-else class="rMuted">No category data</div>
                        </div>

                        <div v-if="(company.peak_missed_times || []).length" class="rSubsection">
                            <h5 class="rSubsection__title">Peak Missed Call Times</h5>
                            <div class="rTableWrap">
                                <table class="rTable">
                                    <thead><tr><th>Hour</th><th class="rTh--num">Missed Calls</th></tr></thead>
                                    <tbody>
                                        <tr v-for="peak in company.peak_missed_times" :key="peak.hour_label">
                                            <td class="rMono">{{ peak.hour_label }}</td>
                                            <td class="rTd--num rMono">{{ peak.missed_count }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="rSubsection">
                            <h5 class="rSubsection__title">Top Automation Opportunities</h5>
                            <div class="rTableWrap" v-if="(company.top_automation_opportunities || []).length">
                                <table class="rTable">
                                    <thead><tr><th>Category</th><th>Priority</th><th class="rTh--num">Calls</th><th class="rTh--num">Minutes</th></tr></thead>
                                    <tbody>
                                        <tr v-for="row in company.top_automation_opportunities" :key="`${row.category_id}-${row.priority}`">
                                            <td>{{ row.category_name || `Category #${row.category_id}` }}</td>
                                            <td><span :style="priorityBadgeStyle(row.priority)">{{ (row.priority || "").toUpperCase() }}</span></td>
                                            <td class="rTd--num rMono">{{ row.total_calls }}</td>
                                            <td class="rTd--num rMono">{{ row.total_minutes }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <div v-else class="rMuted">No automation candidates yet</div>
                        </div>
                    </div>

                    <!-- 2) Ring Group Dashboard -->
                    <div class="rSection">
                        <h4 class="rSection__title">2) Ring Group Dashboard</h4>
                        <div class="rTableWrap" v-if="ringGroups.length">
                            <table class="rTable">
                                <thead>
                                    <tr><th>Ring Group / Queue</th><th class="rTh--num">Calls</th><th class="rTh--num">Missed</th><th class="rTh--num">Abandoned</th><th class="rTh--num">Minutes</th><th>Time Sink Categories</th><th class="rTh--num">Score</th></tr>
                                </thead>
                                <tbody>
                                    <tr v-for="row in ringGroups" :key="row.ring_group">
                                        <td>
                                            <div style="font-weight:500">{{ row.ring_group_name || row.ring_group }}</div>
                                            <div v-if="(row.top_categories||[]).length" style="font-size:0.82em;color:var(--color-muted,#6b7280);margin-top:2px">{{ row.top_categories.slice(0,2).map((c:any)=>labelFromKey(c.name)).join(", ") }}</div>
                                        </td>
                                        <td class="rTd--num rMono">{{ row.total_calls }}</td>
                                        <td class="rTd--num rMono"><span :style="row.missed_calls>0?'color:#dc2626;font-weight:600':''">{{ row.missed_calls }}</span></td>
                                        <td class="rTd--num rMono">{{ row.abandoned_calls }}</td>
                                        <td class="rTd--num rMono">{{ row.total_minutes }}</td>
                                        <td style="font-size:0.85em">
                                            <span v-if="(row.time_sink_categories||[]).length">
                                                <span v-for="(cat,i) in (row.time_sink_categories||[]).slice(0,2)" :key="i">{{ cat.name }}{{ cat.minutes?` (${cat.minutes}m)`:'' }}{{ (i as number)<Math.min((row.time_sink_categories||[]).length,2)-1?', ':'' }}</span>
                                            </span>
                                            <span v-else class="rMuted">—</span>
                                        </td>
                                        <td class="rTd--num rMono">{{ row.automation_priority_score }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div v-else class="rMuted">
                            <p style="margin:0 0 8px">Ring group analytics unavailable</p>
                            <p style="margin:0;font-size:0.9em;line-height:1.4">This requires queue/department routing data from your PBX server. Please verify that your PBX is configured to capture and return queue assignments for calls.</p>
                        </div>
                    </div>

                    <!-- 3) Extension Leaderboard -->
                    <div class="rSection">
                        <h4 class="rSection__title">3) Extension Leaderboard</h4>
                        <div class="rTableWrap" v-if="extensions.length">
                            <table class="rTable">
                                <thead><tr><th>Extension</th><th class="rTh--num" title="Inbound and internal calls handled by this extension">Answered (in)</th><th class="rTh--num" title="Outbound calls placed from this extension">Made (out)</th><th class="rTh--num">Minutes</th><th>Top 3 Categories</th><th class="rTh--num">Repetitive %</th><th class="rTh--num">Impact Score</th></tr></thead>
                                <tbody>
                                    <tr v-for="row in extensions" :key="row.extension">
                                        <td>{{ row.extension }}</td>
                                        <td class="rTd--num rMono">{{ row.calls_answered }}</td>
                                        <td class="rTd--num rMono">{{ row.calls_made ?? 0 }}</td>
                                        <td class="rTd--num rMono">{{ row.total_minutes }}</td>
                                        <td>{{ topCategoriesLabel(row.top_categories) }}</td>
                                        <td class="rTd--num rMono">{{ row.repetitive_percentage }}</td>
                                        <td class="rTd--num rMono">{{ row.automation_impact_score }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div v-else class="rMuted">No extension performance data yet</div>
                    </div>

                    <!-- 4) Extension Scorecards -->
                    <div class="rSection">
                        <h4 class="rSection__title">4) Extension Scorecards</h4>
                        <div v-if="scorecards.length" class="rInsightList">
                            <div v-for="card in scorecards" :key="card.extension" class="rInsightCard">
                                <div class="rInsightCard__header"><strong>Extension {{ card.extension }}</strong></div>
                                <div class="rInsightCard__body">
                                    <div class="rMuted">Top automation candidates:</div>
                                    <ul><li v-for="(c,i) in card.top_automation_candidates||[]" :key="`${card.extension}-${i}`">{{ c.category_name || `Category #${c.category_id}` }}</li></ul>
                                    <div class="rMuted">Recommended actions:</div>
                                    <ul><li v-for="(a,i) in card.recommended_actions||[]" :key="`${card.extension}-a-${i}`">{{ a }}</li></ul>
                                    <div class="rMuted" style="margin-top:8px">Recent timeline:</div>
                                    <div class="rTableWrap" v-if="(card.timeline||[]).length">
                                        <table class="rTable rTable--mini">
                                            <thead><tr><th>Time</th><th class="rTh--num">Sec</th><th>Category</th></tr></thead>
                                            <tbody>
                                                <tr v-for="item in (card.timeline||[]).slice(0,8)" :key="`${card.extension}-t-${item.call_id}`">
                                                    <td class="rMono">{{ fmtDateTime(item.started_at) }}</td>
                                                    <td class="rTd--num rMono">{{ item.duration_seconds }}</td>
                                                    <td>{{ item.category_name || "—" }}</td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                    <div v-else class="rMuted">No timeline entries</div>
                                    <div class="rMuted" style="margin-top:8px">Top 5 examples:</div>
                                    <div v-if="(card.examples||[]).length">
                                        <div v-for="ex in card.examples" :key="`${card.extension}-e-${ex.call_id}`" style="margin-bottom:8px">
                                            <div class="rMono" style="font-size:0.85em">{{ fmtDateTime(ex.started_at) }} <a :href="ex.recording_or_transcript_link" style="margin-left:6px">Open</a></div>
                                            <div>{{ ex.snippet }}</div>
                                        </div>
                                    </div>
                                    <div v-else class="rMuted">No transcript examples</div>
                                </div>
                            </div>
                        </div>
                        <div v-else class="rMuted">No extension scorecards yet</div>
                    </div>

                    <!-- 5) Category Drill-down -->
                    <div class="rSection">
                        <h4 class="rSection__title">5) Category Drill-down</h4>
                        <div v-if="drilldown.length" class="rInsightList">
                            <div v-for="row in drilldown" :key="row.category_id" class="rInsightCard" style="margin-bottom:16px">
                                <div class="rInsightCard__header" style="display:flex;justify-content:space-between;align-items:baseline;flex-wrap:wrap;gap:8px">
                                    <strong>{{ row.category_name || `Category #${row.category_id}` }}</strong>
                                    <span class="rMono" style="font-size:0.85em;color:var(--color-muted,#6b7280)">{{ row.total_calls }} calls · {{ row.total_minutes ?? 0 }}m</span>
                                </div>
                                <div class="rInsightCard__body">
                                    <div class="rKvGrid" style="margin-top:8px">
                                        <div class="rKv">
                                            <div class="rKv__k">Top Extensions</div>
                                            <div class="rKv__v rMono" style="font-size:0.85em">
                                                <span v-if="Object.keys(row.extension_breakdown||{}).length">
                                                    <span v-for="(count,ext,i) in getSortedBreakdown(row.extension_breakdown,3)" :key="ext">{{ ext }} ({{ count }}){{ i < Object.keys(getSortedBreakdown(row.extension_breakdown,3)).length-1?', ':'' }}</span>
                                                </span>
                                                <span v-else class="rMuted">—</span>
                                            </div>
                                        </div>
                                        <div class="rKv">
                                            <div class="rKv__k">Ring Groups</div>
                                            <div class="rKv__v rMono" style="font-size:0.85em">
                                                <span v-if="Object.keys(row.ring_group_breakdown||{}).length">
                                                    <span v-for="(count,rg,i) in getSortedBreakdown(row.ring_group_breakdown,3)" :key="rg">{{ rg }} ({{ count }}){{ i < Object.keys(getSortedBreakdown(row.ring_group_breakdown,3)).length-1?', ':'' }}</span>
                                                </span>
                                                <span v-else class="rMuted">—</span>
                                            </div>
                                        </div>
                                        <div class="rKv">
                                            <div class="rKv__k">Trend</div>
                                            <div class="rKv__v">{{ trendLabel(row.trend_direction, row.trend_percentage_change) }} <span class="rMono" style="font-size:0.85em;margin-left:4px">{{ trendSparkline(row.daily_trend) }}</span></div>
                                        </div>
                                        <div class="rKv" style="grid-column:1/-1">
                                            <div class="rKv__k">Suggested Automations</div>
                                            <div class="rKv__v">
                                                <ul v-if="(row.suggested_automations||[]).length" style="margin:0;padding-left:16px">
                                                    <li v-for="(item,i) in row.suggested_automations" :key="i">
                                                        {{ typeof item==='object'?item.suggestion:item }}<span v-if="typeof item==='object'&&item.impact" style="color:var(--color-muted,#6b7280);font-size:0.85em"> — {{ item.impact }}</span>
                                                    </li>
                                                </ul>
                                                <span v-else class="rMuted">—</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div v-else class="rMuted">No category drill-down analytics yet</div>
                    </div>
                </div>
            </div>
        </template>
    </div>
</template>

<style scoped>
/* ── Layout ─────────────────────────────────────────────────────────────── */
.rPage { display: flex; flex-direction: column; gap: var(--space-4, 1rem); min-width: 0; overflow-x: hidden; }

/* ── Header ─────────────────────────────────────────────────────────────── */
.rHeader {
    display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; flex-wrap: wrap;
    padding: 1.25rem 1.5rem;
    background: var(--surface, #fff); border: 1px solid var(--border, #e5e7eb); border-radius: 14px;
    min-width: 0; overflow: hidden;
}
.rHeader__left  { display: flex; align-items: flex-start; gap: 1rem; flex: 1 1 0; min-width: 0; }
.rHeader__content { min-width: 0; flex: 1; }
.rHeader__icon  {
    width: 44px; height: 44px; border-radius: 10px; flex-shrink: 0;
    background: color-mix(in srgb, var(--color-primary, #3b82f6) 12%, transparent);
    color: var(--color-primary, #3b82f6); display: grid; place-items: center;
}
.rHeader__icon svg { width: 20px; height: 20px; }
.rHeader__breadcrumb { display: flex; align-items: center; gap: 6px; font-size: 0.82rem; opacity: 0.65; margin-bottom: 4px; flex-wrap: wrap; }
.rBreadLink { background: none; border: none; cursor: pointer; font-size: 0.82rem; color: var(--color-primary, #3b82f6); padding: 0; }
.rBreadLink:hover { text-decoration: underline; }
.rBreadSep { opacity: 0.4; }
.rHeader__title    { font-size: 1.25rem; font-weight: 800; margin: 0 0 2px; word-break: break-word; overflow-wrap: break-word; }
.rHeader__subtitle { font-size: 0.85rem; opacity: 0.65; margin: 0; word-break: break-word; }
.rHeader__stats { display: flex; gap: 1rem; align-items: flex-start; flex-shrink: 0; }
.rHeader__stat  { padding: 0.5rem 1rem; border-radius: 8px; text-align: center; min-width: 90px; background: color-mix(in srgb, var(--color-primary, #3b82f6) 8%, transparent); }
.rHeader__statLabel { font-size: 0.7rem; opacity: 0.6; text-transform: uppercase; letter-spacing: 0.05em; }
.rHeader__statValue { font-size: 1rem; font-weight: 700; margin-top: 2px; }
.rHeader__actions   { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; flex-shrink: 0; }

/* ── Buttons ─────────────────────────────────────────────────────────────── */
.rBtn {
    display: inline-flex; align-items: center; gap: 6px; height: 34px; padding: 0 12px;
    border-radius: 8px; font-size: 0.82rem; font-weight: 600; border: none; cursor: pointer;
    white-space: nowrap; text-decoration: none;
}
.rBtn--ghost   { background: transparent; color: inherit; border: 1px solid var(--border, #e5e7eb); }
.rBtn--ghost:hover:not(:disabled) { background: var(--surface-2, #f3f4f6); }
.rBtn--primary { background: var(--color-primary, #3b82f6); color: #fff; }
.rBtn--primary:hover { filter: brightness(1.08); }
.rBtn:disabled { opacity: 0.45; cursor: not-allowed; }
.rBtn__icon    { width: 14px; height: 14px; flex-shrink: 0; }

/* ── Alerts ─────────────────────────────────────────────────────────────── */
.rAlert { padding: 10px 14px; border-radius: 8px; font-size: 0.875rem; }
.rAlert--error { background: color-mix(in srgb, #ef4444 10%, transparent); border: 1px solid #ef4444; color: #ef4444; }
.rAlert--warn  { background: color-mix(in srgb, #f59e0b 10%, transparent); border: 1px solid #f59e0b; color: #b45309; }

/* ── Cards ──────────────────────────────────────────────────────────────── */
.rCard {
    background: var(--surface, #fff); border: 1px solid var(--border, #e5e7eb);
    border-radius: 14px; padding: 1.25rem; display: flex; flex-direction: column; gap: 16px;
    min-width: 0; overflow: hidden;
}
.rCard__title { font-size: 1rem; font-weight: 700; margin: 0; word-break: break-word; }
.rCard__desc  { font-size: 0.82rem; opacity: 0.55; margin-top: -8px; }

/* ── KV Grid ────────────────────────────────────────────────────────────── */
.rKvGrid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 12px; }
.rKvGrid--3col { grid-template-columns: repeat(3, 1fr); }
.rKv { display: flex; flex-direction: column; gap: 4px; min-width: 0; }
.rKv__k { font-size: 0.72rem; font-weight: 600; opacity: 0.55; text-transform: uppercase; letter-spacing: 0.04em; }
.rKv__v { font-size: 0.9rem; word-break: break-word; overflow-wrap: break-word; }
.rMono  { font-family: ui-monospace, monospace; font-size: 0.82rem; }
.rMuted { opacity: 0.6; font-size: 0.875rem; }

/* ── Badge ──────────────────────────────────────────────────────────────── */
.rBadge { padding: 2px 9px; border-radius: 999px; font-size: 0.72rem; font-weight: 700; display: inline-block; white-space: nowrap; }
.rBadge.active, .badge--active { background: color-mix(in srgb, #10b981 14%, transparent); color: #059669; }
.rBadge.failed, .badge--failed { background: color-mix(in srgb, #ef4444 14%, transparent); color: #dc2626; }
.rBadge.processing, .badge--processing { background: color-mix(in srgb, var(--color-primary) 14%, transparent); color: var(--color-primary-hover, var(--color-primary)); }

/* ── Prose ──────────────────────────────────────────────────────────────── */
.rProse { font-size: 0.9rem; line-height: 1.7; margin: 0; word-break: break-word; overflow-wrap: break-word; }

/* ── Metric Cards ───────────────────────────────────────────────────────── */
.rMetricsGrid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
    gap: 12px;
}
.rMetricCard {
    border: 1px solid var(--border, #e5e7eb); border-radius: 10px; padding: 14px;
    background: var(--surface, #fff); min-width: 0;
}
.rMetricCard--success { background: color-mix(in srgb, #10b981 6%, var(--surface, #fff)); border-color: color-mix(in srgb, #10b981 20%, var(--border, #e5e7eb)); }
.rMetricCard--warning { background: color-mix(in srgb, #f59e0b 6%, var(--surface, #fff)); border-color: color-mix(in srgb, #f59e0b 20%, var(--border, #e5e7eb)); }
.rMetricCard__header  { display: flex; align-items: center; gap: 8px; margin-bottom: 8px; }
.rMetricCard__icon    { width: 28px; height: 28px; border-radius: 7px; background: color-mix(in srgb, var(--color-primary, #3b82f6) 10%, transparent); color: var(--color-primary, #3b82f6); display: grid; place-items: center; flex-shrink: 0; }
.rMetricCard__icon svg { width: 14px; height: 14px; }
.rMetricCard--success .rMetricCard__icon { background: color-mix(in srgb, #10b981 12%, transparent); color: #059669; }
.rMetricCard--warning .rMetricCard__icon { background: color-mix(in srgb, #f59e0b 12%, transparent); color: #b45309; }
.rMetricCard__label   { font-size: 0.78rem; font-weight: 600; opacity: 0.65; }
.rMetricCard__value   { font-size: 1.6rem; font-weight: 800; line-height: 1; }
.rMetricCard__sub     { font-size: 0.75rem; opacity: 0.6; margin-top: 4px; }

/* ── Time Range ─────────────────────────────────────────────────────────── */
.rTimeRange { display: flex; gap: 2rem; flex-wrap: wrap; padding-top: 4px; border-top: 1px solid var(--border, #e5e7eb); }

/* ── Tables ─────────────────────────────────────────────────────────────── */
.rTableWrap { overflow-x: auto; border-radius: 8px; border: 1px solid var(--border, #e5e7eb); }
.rTable { width: 100%; border-collapse: collapse; font-size: 0.875rem; }
.rTable th { background: var(--surface-2, #f9fafb); font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; opacity: 0.7; padding: 8px 12px; text-align: left; border-bottom: 1px solid var(--border, #e5e7eb); }
.rTable td { padding: 8px 12px; border-bottom: 1px solid var(--border, #e5e7eb); }
.rTable tbody tr:last-child td { border-bottom: none; }
.rTable tbody tr:hover td { background: var(--surface-2, #f9fafb); }
.rTable--mini { font-size: 0.82rem; }
.rTable--mini td { padding: 5px 8px; }
.rTh--num { text-align: right !important; }
.rTd--num { text-align: right; }

/* ── Percent Bar ────────────────────────────────────────────────────────── */
.rPctBar { display: inline-flex; align-items: center; gap: 8px; width: 140px; }
.rPctBar__fill { height: 6px; border-radius: 3px; background: color-mix(in srgb, var(--color-primary, #3b82f6) 60%, transparent); flex-shrink: 0; }
.rPctBar__label { font-size: 0.75rem; opacity: 0.7; white-space: nowrap; }

/* ── Section headers ────────────────────────────────────────────────────── */
.rSections { display: flex; flex-direction: column; gap: 20px; }
.rSection   { display: flex; flex-direction: column; gap: 12px; padding-top: 16px; border-top: 1px solid var(--border, #e5e7eb); }
.rSection:first-child { border-top: none; padding-top: 0; }
.rSection__title { font-size: 0.9rem; font-weight: 700; margin: 0; display: flex; align-items: center; gap: 8px; }
.rSection__icon  { width: 22px; height: 22px; border-radius: 6px; display: grid; place-items: center; flex-shrink: 0; }
.rSection__icon--opp { background: color-mix(in srgb, #10b981 12%, transparent); color: #059669; }
.rSection__icon--rec { background: color-mix(in srgb, var(--color-primary, #3b82f6) 12%, transparent); color: var(--color-primary, #3b82f6); }
.rSection__icon svg  { width: 12px; height: 12px; }
.rSubsection       { display: flex; flex-direction: column; gap: 8px; margin-top: 4px; }
.rSubsection__title { font-size: 0.82rem; font-weight: 700; opacity: 0.75; margin: 0; }

/* ── Sub-category cards ─────────────────────────────────────────────────── */
.rSubCatGrid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 12px; }
.rSubCatCard { border: 1px solid var(--border, #e5e7eb); border-radius: 10px; overflow: hidden; }
.rSubCatCard__header { display: flex; justify-content: space-between; align-items: center; padding: 8px 12px; background: var(--surface-2, #f9fafb); }
.rSubCatCard__name  { font-weight: 600; font-size: 0.875rem; }
.rSubCatCard__count { font-size: 0.78rem; opacity: 0.6; }

/* ── Hourly grid ────────────────────────────────────────────────────────── */
.rHourlyGrid { display: flex; flex-wrap: wrap; gap: 6px; }
.rHourlyCell {
    display: flex; flex-direction: column; align-items: center; gap: 2px;
    min-width: 44px; padding: 6px 8px; border-radius: 8px;
    background: var(--surface-2, #f3f4f6); border: 1px solid var(--border, #e5e7eb);
    font-size: 0.72rem;
}
.rHourlyCell--peak { background: color-mix(in srgb, var(--color-primary, #3b82f6) 12%, transparent); border-color: color-mix(in srgb, var(--color-primary, #3b82f6) 30%, transparent); color: var(--color-primary, #3b82f6); }
.rHourlyCell__hour  { opacity: 0.65; }
.rHourlyCell__count { font-weight: 700; font-size: 0.82rem; }

/* ── Sample calls ───────────────────────────────────────────────────────── */
.rSampleSection       { display: flex; flex-direction: column; gap: 8px; }
.rSampleSection__title { font-size: 0.82rem; font-weight: 600; opacity: 0.7; margin: 0; }
.rSampleList { display: flex; flex-direction: column; gap: 8px; }
.rSampleCall { border: 1px solid var(--border, #e5e7eb); border-radius: 8px; padding: 10px 12px; }
.rSampleCall__meta { display: flex; align-items: center; gap: 10px; margin-bottom: 6px; flex-wrap: wrap; }
.rSampleCall__tag { font-size: 0.72rem; padding: 1px 7px; border-radius: 999px; background: var(--surface-2, #f3f4f6); border: 1px solid var(--border, #e5e7eb); }
.rSampleCall__transcript { font-size: 0.82rem; line-height: 1.5; opacity: 0.8; }

/* ── Insight cards ──────────────────────────────────────────────────────── */
.rInsightList { display: flex; flex-direction: column; gap: 12px; }
.rInsightCard { border: 1px solid var(--border, #e5e7eb); border-radius: 10px; overflow: hidden; }
.rInsightCard--opp { border-color: color-mix(in srgb, #10b981 30%, var(--border, #e5e7eb)); }
.rInsightCard__header { display: flex; align-items: center; gap: 10px; padding: 10px 14px; background: var(--surface-2, #f9fafb); border-bottom: 1px solid var(--border, #e5e7eb); flex-wrap: wrap; }
.rInsightCard__category { font-size: 0.82rem; font-weight: 600; opacity: 0.8; }
.rInsightCard__body   { padding: 12px 14px; display: flex; flex-direction: column; gap: 10px; }
.rInsightCard__reason { margin: 0; font-size: 0.875rem; line-height: 1.5; }
.rInsightMetrics { display: flex; gap: 16px; flex-wrap: wrap; }
.rInsightMetric { display: flex; flex-direction: column; gap: 2px; }
.rInsightMetric__label { font-size: 0.7rem; font-weight: 600; opacity: 0.55; text-transform: uppercase; letter-spacing: 0.04em; }
.rInsightMetric__value { font-size: 0.875rem; font-weight: 600; }
.rInsightMetric__sub   { font-size: 0.78rem; opacity: 0.6; }

/* ── Recommendation cards ───────────────────────────────────────────────── */
.rRecList { display: flex; flex-direction: column; gap: 10px; }
.rRecCard { border: 1px solid var(--border, #e5e7eb); border-radius: 10px; padding: 12px 14px; display: flex; flex-direction: row; gap: 12px; align-items: flex-start; }
.rRecCard--warning { background: color-mix(in srgb, #f59e0b 6%, transparent); border-color: color-mix(in srgb, #f59e0b 30%, var(--border, #e5e7eb)); }
.rRecCard--info    { background: color-mix(in srgb, var(--color-primary) 6%, transparent); border-color: color-mix(in srgb, var(--color-primary) 30%, var(--border)); }
.rRecCard__icon    { width: 32px; height: 32px; border-radius: 8px; flex-shrink: 0; display: grid; place-items: center; background: color-mix(in srgb, var(--color-primary, #3b82f6) 10%, transparent); color: var(--color-primary, #3b82f6); }
.rRecCard--warning .rRecCard__icon { background: color-mix(in srgb, #f59e0b 12%, transparent); color: #b45309; }
.rRecCard__icon svg { width: 16px; height: 16px; }
.rRecCard__content { display: flex; flex-direction: column; gap: 4px; flex: 1; min-width: 0; }
.rRecCard__type    { font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; opacity: 0.65; }
.rRecCard__message { margin: 0; font-size: 0.875rem; line-height: 1.5; }
.rRecCard__tags    { display: flex; gap: 6px; flex-wrap: wrap; }
.rRecTag { font-size: 0.72rem; padding: 2px 8px; border-radius: 999px; background: var(--surface-2, #f3f4f6); border: 1px solid var(--border, #e5e7eb); }

/* ── Empty ──────────────────────────────────────────────────────────────── */
.rEmpty { padding: 2rem; text-align: center; }
.rEmpty__title { font-size: 0.9rem; font-weight: 600; margin-bottom: 4px; }
.rEmpty__sub   { font-size: 0.82rem; opacity: 0.55; }

/* ── Skeleton ───────────────────────────────────────────────────────────── */
.rSkelLines { display: flex; flex-direction: column; gap: 10px; }
.rSkelLines::before, .rSkelLines::after {
    content: ''; height: 16px; border-radius: 6px;
    background: color-mix(in srgb, var(--color-text, #111) 8%, transparent);
    animation: rPulse 1.2s ease-in-out infinite;
}
@keyframes rPulse { 0%,100% { opacity: 1; } 50% { opacity: 0.4; } }

/* ── Responsive ─────────────────────────────────────────────────────────── */

/* Table headers: prevent wrap on mobile so horizontal scroll stays clean */
.rTable th { white-space: nowrap; }
/* iOS momentum scroll on table containers */
.rTableWrap { -webkit-overflow-scrolling: touch; }
/* sub-category name + count */
.rSubCatCard__header { gap: 8px; }
.rSubCatCard__name   { min-width: 0; word-break: break-word; }
.rSubCatCard__count  { white-space: nowrap; flex-shrink: 0; }
/* insight/rec text wrapping */
.rInsightCard__reason, .rRecCard__message { word-break: break-word; }
.rRecCard__content { min-width: 0; }
.rInsightCard__category { word-break: break-word; }
/* sample transcript wrapping */
.rSampleCall__transcript { word-break: break-word; }

@media (max-width: 768px) {
    .rHeader {
        flex-direction: column;
        padding: 1rem;
    }
    .rHeader__left    { width: 100%; }
    .rHeader__stats   { width: 100%; }
    .rHeader__stat    { flex: 1; }
    .rHeader__actions { width: 100%; }

    .rKvGrid--3col { grid-template-columns: 1fr 1fr; }
    .rMetricsGrid  { grid-template-columns: 1fr 1fr; }
    .rPctBar { width: 80px; }
    .rCard   { padding: 1rem; }
}

@media (max-width: 520px) {
    .rHeader { padding: 0.875rem; gap: 0.75rem; }
    .rHeader__title    { font-size: 1.05rem; }
    .rHeader__icon     { width: 36px; height: 36px; }
    .rHeader__icon svg { width: 16px; height: 16px; }

    .rHeader__stats  { flex-direction: row; gap: 0.5rem; }
    .rHeader__stat   { min-width: 0; padding: 0.375rem 0.75rem; }
    .rHeader__statValue { font-size: 0.9rem; }

    .rKvGrid--3col { grid-template-columns: 1fr; }
    .rMetricsGrid  { grid-template-columns: 1fr 1fr; }
    .rCard  { padding: 0.875rem; gap: 12px; }
    .rMetricCard__value { font-size: 1.35rem; }
    .rHourlyCell { min-width: 36px; padding: 5px 6px; }
    .rSubCatGrid { grid-template-columns: 1fr; }
    .rPctBar { width: 60px; }
}

@media (max-width: 360px) {
    .rMetricsGrid { grid-template-columns: 1fr; }
    .rHeader__stats { flex-direction: column; gap: 0.5rem; }
    .rHeader__stat  { width: 100%; }
}
</style>
