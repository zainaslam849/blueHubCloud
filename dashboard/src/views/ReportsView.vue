<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useRouter } from "vue-router";
import { userApi } from "../api/user";

type ReportRow = {
    id: number;
    weekStart: string | null;
    weekEnd: string | null;
    week: string;
    status: string;
    totalCalls: number;
    answeredCalls: number;
    missedCalls: number;
    generatedAt: string | null;
};

const router = useRouter();

const loading    = ref(true);
const error      = ref("");
const rows       = ref<ReportRow[]>([]);
const search     = ref("");
const sortBy     = ref("weekStart");
const sortDir    = ref<"asc" | "desc">("desc");

function normalize(item: any): ReportRow {
    return {
        id:            item.id,
        weekStart:     item.week_start_date ?? null,
        weekEnd:       item.week_end_date ?? null,
        week:          item.week ?? "",
        status:        item.status ?? "",
        totalCalls:    item.total_calls ?? 0,
        answeredCalls: item.answered_calls ?? 0,
        missedCalls:   item.missed_calls ?? 0,
        generatedAt:   item.generated_at ?? null,
    };
}

function fmtWeek(row: ReportRow): string {
    if (!row.weekStart) return row.week || "—";
    const s = new Date(row.weekStart);
    const e = row.weekEnd ? new Date(row.weekEnd) : null;
    const ss = s.toLocaleDateString(undefined, { month: "short", day: "numeric" });
    if (!e) return ss;
    const es = e.toLocaleDateString(undefined, { month: "short", day: "numeric", year: "numeric" });
    return `${ss} – ${es}`;
}

function fmtNum(v: number | null | undefined): string {
    const n = Number(v);
    return Number.isFinite(n) ? n.toLocaleString() : "—";
}

function answerRate(row: ReportRow): number {
    if (!row.totalCalls) return 0;
    return Math.round((row.answeredCalls / row.totalCalls) * 100);
}

function rateBadge(row: ReportRow): string {
    const r = answerRate(row);
    if (r >= 80) return "badge--active";
    if (r >= 60) return "badge--processing";
    return "badge--failed";
}

function glyph(key: string): string {
    if (sortBy.value !== key) return "";
    return sortDir.value === "asc" ? " ▲" : " ▼";
}

function toggleSort(key: string) {
    if (sortBy.value === key) {
        sortDir.value = sortDir.value === "asc" ? "desc" : "asc";
    } else {
        sortBy.value = key;
        sortDir.value = "asc";
    }
}

const filtered = computed(() => {
    let list = [...rows.value];

    if (search.value) {
        const q = search.value.toLowerCase();
        list = list.filter(r =>
            fmtWeek(r).toLowerCase().includes(q) ||
            String(r.status).toLowerCase().includes(q)
        );
    }

    list.sort((a, b) => {
        let av: number, bv: number;
        if (sortBy.value === "weekStart") {
            av = new Date(a.weekStart || 0).getTime();
            bv = new Date(b.weekStart || 0).getTime();
        } else if (sortBy.value === "totalCalls") {
            av = a.totalCalls;
            bv = b.totalCalls;
        } else if (sortBy.value === "answeredCalls") {
            av = a.answeredCalls;
            bv = b.answeredCalls;
        } else {
            return 0;
        }
        if (av < bv) return sortDir.value === "asc" ? -1 : 1;
        if (av > bv) return sortDir.value === "asc" ? 1 : -1;
        return 0;
    });

    return list;
});

async function fetchReports() {
    loading.value = true;
    error.value   = "";
    try {
        const res  = await userApi.get<{ data: any[] }>("/reports");
        rows.value = (res.data.data ?? []).map(normalize);
    } catch {
        rows.value = [];
        error.value = "Failed to load weekly reports.";
    } finally {
        loading.value = false;
    }
}

function view(row: ReportRow) {
    router.push({ name: "report-detail", params: { id: row.id } });
}

onMounted(fetchReports);
</script>

<template>
    <div class="rPage">
        <!-- Header -->
        <div class="rPageHead">
            <div>
                <h1 class="rPageHead__title">Weekly Call Reports</h1>
                <p class="rPageHead__sub">Track and analyse weekly call performance metrics.</p>
            </div>
        </div>

        <!-- Toolbar -->
        <div class="rToolbar">
            <div class="rSearchWrap">
                <svg class="rSearchIcon" viewBox="0 0 24 24" fill="none"><circle cx="11" cy="11" r="7.2" stroke="currentColor" stroke-width="1.8"/><path d="m16.5 16.5 4 4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                <input
                    v-model="search"
                    class="rInput rInput--search"
                    type="search"
                    placeholder="Search week range…"
                    autocomplete="off"
                />
            </div>
            <button type="button" class="rBtn rBtn--secondary" :disabled="loading" @click="fetchReports">
                <svg viewBox="0 0 24 24" fill="none" class="rBtn__icon">
                    <path d="M20 12a8 8 0 1 1-2.34-5.66" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                    <path d="M20 4v6h-6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                Refresh
            </button>
        </div>

        <!-- Count row -->
        <div v-if="!loading" class="rCountRow">{{ fmtNum(filtered.length) }} reports</div>

        <div v-if="error" class="rAlert">{{ error }}</div>

        <!-- Table card -->
        <div class="rCard">
            <div class="rTableWrap">
                <table class="rTable">
                    <thead>
                        <tr>
                            <th>
                                <button type="button" class="rSortBtn" @click="toggleSort('weekStart')">
                                    Week{{ glyph('weekStart') }}
                                </button>
                            </th>
                            <th class="rCol--right">
                                <button type="button" class="rSortBtn" @click="toggleSort('totalCalls')">
                                    Total Calls{{ glyph('totalCalls') }}
                                </button>
                            </th>
                            <th class="rCol--right">
                                <button type="button" class="rSortBtn" @click="toggleSort('answeredCalls')">
                                    Answered{{ glyph('answeredCalls') }}
                                </button>
                            </th>
                            <th class="rCol--right">Answer Rate</th>
                            <th class="rCol--actions"></th>
                        </tr>
                    </thead>

                    <tbody v-if="loading">
                        <tr v-for="n in 8" :key="n">
                            <td colspan="5"><div class="rSkeleton"></div></td>
                        </tr>
                    </tbody>

                    <tbody v-else-if="filtered.length === 0">
                        <tr>
                            <td colspan="5" class="rEmpty">
                                <div class="rEmpty__title">No reports found</div>
                                <div class="rEmpty__desc">No weekly call reports match the current search.</div>
                            </td>
                        </tr>
                    </tbody>

                    <tbody v-else>
                        <tr v-for="row in filtered" :key="row.id" class="rRow" @click="view(row)">
                            <td class="rWeek">{{ fmtWeek(row) }}</td>
                            <td class="rMono rCol--right">{{ fmtNum(row.totalCalls) }}</td>
                            <td class="rMono rCol--right">{{ fmtNum(row.answeredCalls) }}</td>
                            <td class="rCol--right">
                                <span class="rBadge" :class="rateBadge(row)">{{ answerRate(row) }}%</span>
                            </td>
                            <td class="rCol--actions" @click.stop>
                                <button type="button" class="rChevronBtn" aria-label="View report" @click.stop="view(row)">
                                    <svg viewBox="0 0 24 24" fill="none"><path d="m9 6 6 6-6 6" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/></svg>
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Mobile card list -->
            <div class="rCardList">
                <div v-if="loading" class="rCardList__body">
                    <div v-for="n in 6" :key="n" class="rCardSkeleton"></div>
                </div>
                <div v-else-if="filtered.length === 0" class="rEmpty">
                    <div class="rEmpty__title">No reports found</div>
                    <div class="rEmpty__desc">No weekly call reports match the current search.</div>
                </div>
                <div v-else class="rCardList__body">
                    <button v-for="row in filtered" :key="row.id" type="button" class="rReportCard" @click="view(row)">
                        <div class="rReportCard__top">
                            <span class="rWeek">{{ fmtWeek(row) }}</span>
                            <span class="rBadge" :class="rateBadge(row)">{{ answerRate(row) }}%</span>
                        </div>
                        <div class="rReportCard__meta">
                            <span class="rMono">{{ fmtNum(row.totalCalls) }} calls</span>
                            <span class="rReportCard__dot"></span>
                            <span class="rMono">{{ fmtNum(row.answeredCalls) }} answered</span>
                        </div>
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
/* ── Layout ──────────────────────────────────────────────────────────────── */
.rPage { display: flex; flex-direction: column; gap: 16px; }

/* ── Header ──────────────────────────────────────────────────────────────── */
.rPageHead { display: flex; align-items: flex-end; justify-content: space-between; gap: 20px; flex-wrap: wrap; }
.rPageHead__title { margin: 0; font-size: 1.9rem; font-weight: 700; letter-spacing: -0.015em; }
.rPageHead__sub { margin: 6px 0 0 0; color: var(--color-muted); font-size: 0.88rem; }

/* ── Toolbar ─────────────────────────────────────────────────────────────── */
.rToolbar {
    display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
    background: var(--color-surface); border: 1px solid var(--color-border); border-radius: 14px; padding: 12px 14px;
}
.rSearchWrap { position: relative; display: flex; align-items: center; flex: 1; min-width: 200px; }
.rSearchIcon { position: absolute; left: 12px; width: 15px; height: 15px; color: var(--color-muted); pointer-events: none; }
.rInput--search { padding-left: 36px; }
.rInput {
    height: 38px; padding: 0 11px; border: 1px solid var(--color-border);
    border-radius: 9px; background: var(--color-surface-2); color: inherit;
    font-size: 0.85rem; width: 100%; box-sizing: border-box;
}
.rInput:focus {
    outline: none; border-color: color-mix(in srgb, var(--color-primary) 60%, var(--color-border));
    background: var(--color-surface); box-shadow: 0 0 0 3px var(--ring);
}

.rCountRow { font-size: 0.82rem; color: var(--color-muted); }

/* ── Buttons ─────────────────────────────────────────────────────────────── */
.rBtn {
    display: inline-flex; align-items: center; gap: 6px; height: 38px; padding: 0 15px;
    border-radius: 9px; font-size: 0.85rem; font-weight: 600; border: none; cursor: pointer;
    white-space: nowrap; transition: filter 0.15s, opacity 0.15s;
}
.rBtn--secondary { background: var(--color-surface); color: inherit; border: 1px solid var(--color-border-strong); }
.rBtn--secondary:hover:not(:disabled) { background: var(--color-surface-2); }
.rBtn:disabled { opacity: 0.45; cursor: not-allowed; }
.rBtn__icon { width: 15px; height: 15px; flex-shrink: 0; }

/* ── Alert ───────────────────────────────────────────────────────────────── */
.rAlert { padding: 10px 14px; border-radius: 10px; font-size: 0.9rem; background: var(--color-error-soft); border: 1px solid var(--color-error-soft-border); color: var(--color-error); }

/* ── Card / table ────────────────────────────────────────────────────────── */
.rCard { background: var(--color-surface); border: 1px solid var(--color-border); border-radius: 14px; overflow: hidden; }
.rTableWrap { overflow-x: auto; }
.rTable { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
.rTable thead tr { background: var(--color-surface-2); }
.rTable th {
    text-align: left; padding: 11px 16px; font-size: 0.68rem; text-transform: uppercase;
    letter-spacing: 0.05em; color: var(--color-muted); font-weight: 600; white-space: nowrap;
    border-bottom: 1px solid var(--color-border);
}
.rTable td { padding: 13px 16px; border-bottom: 1px solid var(--color-border); vertical-align: middle; }
.rTable tbody tr:last-child td { border-bottom: none; }
.rRow { cursor: pointer; transition: background 0.1s; }
.rRow:hover { background: var(--color-surface-2); }

.rSortBtn {
    background: none; border: none; cursor: pointer; padding: 0; font: inherit;
    font-size: 0.68rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em;
    color: var(--color-muted);
}
.rSortBtn:hover { color: var(--color-text); }

.rWeek { font-weight: 500; font-size: 0.87rem; white-space: nowrap; }
.rMono { font-family: var(--font-mono); font-size: 0.82rem; }
.rCol--right { text-align: right; }
.rCol--actions { width: 40px; }

/* ── Badge ───────────────────────────────────────────────────────────────── */
.rBadge {
    padding: 3px 9px; border-radius: 999px; font-size: 0.7rem; font-weight: 700;
    display: inline-block; white-space: nowrap;
}
.badge--active     { background: var(--color-success-soft); color: var(--color-success); }
.badge--failed     { background: var(--color-error-soft); color: var(--color-error); }
.badge--processing { background: var(--color-primary-soft); color: var(--color-primary); }

.rChevronBtn {
    display: flex; align-items: center; justify-content: center; width: 26px; height: 26px;
    border-radius: 7px; background: none; border: none; cursor: pointer; color: var(--color-muted);
}
.rChevronBtn:hover { background: var(--color-surface-2); color: var(--color-text); }
.rChevronBtn svg { width: 16px; height: 16px; }

/* ── Empty ───────────────────────────────────────────────────────────────── */
.rEmpty { padding: 3rem; text-align: center; }
.rEmpty__title { font-size: 1rem; font-weight: 600; margin-bottom: 4px; }
.rEmpty__desc { font-size: 0.875rem; color: var(--color-muted); }

/* ── Skeleton ────────────────────────────────────────────────────────────── */
.rSkeleton {
    height: 20px; border-radius: 6px; background: color-mix(in srgb, var(--color-text) 8%, transparent);
    animation: rPulse 1.2s ease-in-out infinite;
}
@keyframes rPulse { 0%,100% { opacity: 1; } 50% { opacity: 0.45; } }

/* ── Mobile card list ────────────────────────────────────────────────────── */
.rCardList { display: none; }
.rCardList__body { display: flex; flex-direction: column; }
.rReportCard {
    display: flex; flex-direction: column; gap: 8px; width: 100%;
    padding: 14px 16px; border: none; border-bottom: 1px solid var(--color-border);
    background: none; text-align: left; cursor: pointer; font-family: inherit; color: inherit;
}
.rReportCard:last-child { border-bottom: none; }
.rReportCard:active { background: var(--color-surface-2); }
.rReportCard__top { display: flex; align-items: center; justify-content: space-between; gap: 10px; }
.rReportCard__meta { display: flex; align-items: center; gap: 7px; font-size: 0.78rem; color: var(--color-muted); }
.rReportCard__dot { width: 3px; height: 3px; border-radius: 999px; background: var(--color-muted); flex-shrink: 0; }
.rCardSkeleton {
    height: 58px; margin: 10px 16px; border-radius: 10px;
    background: color-mix(in srgb, var(--color-text) 8%, transparent);
    animation: rPulse 1.2s ease-in-out infinite;
}
.rCardSkeleton:first-child { margin-top: 16px; }

/* ── Responsive ──────────────────────────────────────────────────────────── */
@media (max-width: 720px) {
    .rPageHead { flex-direction: column; align-items: flex-start; }
    .rTableWrap { display: none; }
    .rCardList { display: block; }
}
</style>
