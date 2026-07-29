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
    if (r >= 80) return "dBadge--active";
    if (r >= 60) return "dBadge--processing";
    return "dBadge--failed";
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
        <header class="rHeader">
            <div class="rHeader__left">
                <div class="rHeader__icon">
                    <svg viewBox="0 0 24 24" fill="none">
                        <path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                        <rect x="9" y="3" width="6" height="4" rx="1" stroke="currentColor" stroke-width="1.8"/>
                        <path d="M9 12h6M9 16h6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                    </svg>
                </div>
                <div>
                    <h1 class="rHeader__title">Weekly Call Reports</h1>
                    <p class="rHeader__sub">Track and analyse weekly call performance metrics.</p>
                </div>
            </div>
            <div class="rHeader__stat">
                <div class="rHeader__statValue">{{ filtered.length }}</div>
                <div class="rHeader__statLabel">Total Reports</div>
            </div>
        </header>

        <!-- Toolbar -->
        <div class="rToolbar">
            <input
                v-model="search"
                class="rSearch"
                type="search"
                placeholder="Search week range…"
                autocomplete="off"
            />
            <button type="button" class="rBtn rBtn--ghost" :disabled="loading" @click="fetchReports">
                <svg viewBox="0 0 24 24" fill="none" class="rBtn__icon">
                    <path d="M20 12a8 8 0 1 1-2.34-5.66" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                    <path d="M20 4v6h-6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                Refresh
            </button>
        </div>

        <div v-if="error" class="rAlert">{{ error }}</div>

        <!-- Table card -->
        <div class="rCard">
            <!-- Table header -->
            <div class="rTable">
                <div class="rTr rTr--head">
                    <div class="rTd">
                        <button type="button" class="rSortBtn" @click="toggleSort('weekStart')">
                            Week{{ glyph('weekStart') }}
                        </button>
                    </div>
                    <div class="rTd rTd--num">
                        <button type="button" class="rSortBtn" @click="toggleSort('totalCalls')">
                            Total Calls{{ glyph('totalCalls') }}
                        </button>
                    </div>
                    <div class="rTd rTd--num">
                        <button type="button" class="rSortBtn" @click="toggleSort('answeredCalls')">
                            Answered{{ glyph('answeredCalls') }}
                        </button>
                    </div>
                    <div class="rTd rTd--num">Answer Rate</div>
                    <div class="rTd rTd--actions">Actions</div>
                </div>

                <!-- Skeleton -->
                <template v-if="loading">
                    <div v-for="n in 8" :key="n" class="rTr">
                        <div class="rTd"><span class="rSk rSk--lg"></span></div>
                        <div class="rTd rTd--num"><span class="rSk rSk--sm"></span></div>
                        <div class="rTd rTd--num"><span class="rSk rSk--sm"></span></div>
                        <div class="rTd rTd--num"><span class="rSk rSk--badge"></span></div>
                        <div class="rTd rTd--actions"><span class="rSk rSk--btn"></span></div>
                    </div>
                </template>

                <!-- Empty -->
                <div v-else-if="filtered.length === 0" class="rEmpty">
                    <div class="rEmpty__title">No reports found</div>
                    <div class="rEmpty__sub">No weekly call reports match the current search.</div>
                </div>

                <!-- Rows -->
                <template v-else>
                    <div v-for="row in filtered" :key="row.id" class="rTr rTr--body" @click="view(row)">
                        <div class="rTd">
                            <span class="rWeek">{{ fmtWeek(row) }}</span>
                        </div>
                        <div class="rTd rTd--num rMono">{{ fmtNum(row.totalCalls) }}</div>
                        <div class="rTd rTd--num rMono">{{ fmtNum(row.answeredCalls) }}</div>
                        <div class="rTd rTd--num">
                            <span class="dBadge" :class="rateBadge(row)">{{ answerRate(row) }}%</span>
                        </div>
                        <div class="rTd rTd--actions" @click.stop>
                            <button type="button" class="rBtn rBtn--ghost rBtn--sm" @click="view(row)">View</button>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </div>
</template>

<style scoped>
/* ── Layout ─────────────────────────────────────────────────────── */
.rPage { display: flex; flex-direction: column; gap: var(--space-4, 1rem); }

/* ── Header ─────────────────────────────────────────────────────── */
.rHeader {
    display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap;
    padding: 1.25rem 1.5rem;
    background: var(--surface, #fff); border: 1px solid var(--border, #e5e7eb); border-radius: 14px;
}
.rHeader__left { display: flex; align-items: center; gap: 1rem; }
.rHeader__icon {
    width: 44px; height: 44px; border-radius: 10px; flex-shrink: 0;
    background: color-mix(in srgb, var(--color-primary, #3b82f6) 12%, transparent);
    color: var(--color-primary, #3b82f6); display: grid; place-items: center;
}
.rHeader__icon svg { width: 20px; height: 20px; }
.rHeader__title { font-size: 1.2rem; font-weight: 800; margin: 0 0 2px; }
.rHeader__sub   { font-size: 0.82rem; opacity: 0.6; margin: 0; }
.rHeader__stat  {
    padding: 0.5rem 1rem; border-radius: 8px; text-align: center;
    background: color-mix(in srgb, var(--color-primary, #3b82f6) 8%, transparent);
}
.rHeader__statValue { font-size: 1.15rem; font-weight: 700; }
.rHeader__statLabel { font-size: 0.7rem; opacity: 0.6; text-transform: uppercase; letter-spacing: 0.05em; }

/* ── Toolbar ─────────────────────────────────────────────────────── */
.rToolbar { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.rSearch {
    flex: 1; min-width: 200px; height: 36px; padding: 0 12px;
    border: 1px solid var(--border, #e5e7eb); border-radius: 8px;
    background: var(--surface, #fff); color: inherit; font-size: 0.875rem;
}
.rSearch:focus { outline: none; border-color: var(--color-primary, #3b82f6); }

/* ── Buttons ─────────────────────────────────────────────────────── */
.rBtn {
    display: inline-flex; align-items: center; gap: 6px; height: 36px; padding: 0 12px;
    border-radius: 8px; font-size: 0.82rem; font-weight: 600; border: none; cursor: pointer;
    white-space: nowrap; transition: filter 0.15s, opacity 0.15s;
}
.rBtn--sm { height: 30px; padding: 0 10px; font-size: 0.78rem; }
.rBtn--ghost { background: transparent; color: inherit; border: 1px solid var(--border, #e5e7eb); }
.rBtn--ghost:hover:not(:disabled) { background: var(--surface-2, #f3f4f6); }
.rBtn:disabled { opacity: 0.45; cursor: not-allowed; }
.rBtn__icon { width: 14px; height: 14px; flex-shrink: 0; }

/* ── Alert ───────────────────────────────────────────────────────── */
.rAlert {
    padding: 10px 14px; border-radius: 8px; font-size: 0.875rem;
    background: color-mix(in srgb, #ef4444 10%, transparent);
    border: 1px solid #ef4444; color: #ef4444;
}

/* ── Card ────────────────────────────────────────────────────────── */
.rCard {
    background: var(--surface, #fff); border: 1px solid var(--border, #e5e7eb);
    border-radius: 14px; overflow: hidden;
}

/* ── Table ───────────────────────────────────────────────────────── */
.rTable { display: flex; flex-direction: column; }

.rTr {
    display: grid;
    grid-template-columns: 2fr 1fr 1fr 1fr 120px;
    align-items: center; gap: 12px;
    padding: 0 1.25rem;
    border-bottom: 1px solid var(--border, #e5e7eb);
}
.rTr:last-child { border-bottom: none; }

.rTr--head {
    height: 40px;
    background: var(--surface-2, #f9fafb);
    font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em;
    opacity: 0.7;
}

.rTr--body {
    height: 52px;
    cursor: pointer;
    transition: background 0.1s;
}
.rTr--body:hover { background: var(--surface-2, #f9fafb); }

.rTd { display: flex; align-items: center; min-width: 0; }
.rTd--num { justify-content: flex-end; }
.rTd--actions { justify-content: flex-end; }

.rSortBtn {
    background: none; border: none; cursor: pointer; padding: 0; font: inherit;
    font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em;
    color: inherit; opacity: 0.7;
}
.rSortBtn:hover { opacity: 1; }

.rWeek { font-weight: 600; font-size: 0.875rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.rMono { font-family: ui-monospace, monospace; font-size: 0.82rem; }

/* ── Badge ───────────────────────────────────────────────────────── */
.dBadge {
    padding: 2px 9px; border-radius: 999px; font-size: 0.72rem; font-weight: 700;
    display: inline-block; white-space: nowrap;
}
.dBadge--active     { background: color-mix(in srgb, #10b981 14%, transparent); color: #059669; }
.dBadge--failed     { background: color-mix(in srgb, #ef4444 14%, transparent); color: #dc2626; }
.dBadge--processing { background: color-mix(in srgb, #3b82f6 14%, transparent); color: #2563eb; }

/* ── Empty ───────────────────────────────────────────────────────── */
.rEmpty { padding: 3rem 1.5rem; text-align: center; }
.rEmpty__title { font-size: 0.95rem; font-weight: 600; margin-bottom: 6px; }
.rEmpty__sub   { font-size: 0.82rem; opacity: 0.55; }

/* ── Skeleton ────────────────────────────────────────────────────── */
.rSk {
    display: inline-block; border-radius: 6px;
    background: color-mix(in srgb, var(--color-text, #111) 8%, transparent);
    animation: rPulse 1.2s ease-in-out infinite;
}
.rSk--lg   { width: 180px; height: 14px; }
.rSk--sm   { width: 60px; height: 14px; }
.rSk--badge { width: 52px; height: 20px; border-radius: 999px; }
.rSk--btn  { width: 60px; height: 28px; border-radius: 8px; }
@keyframes rPulse { 0%,100% { opacity: 1; } 50% { opacity: 0.4; } }

/* ── Responsive ──────────────────────────────────────────────────── */
@media (max-width: 768px) {
    .rTr {
        grid-template-columns: 1fr 1fr;
        height: auto;
        padding: 10px 1rem;
        gap: 8px;
    }
    .rTr--head { display: none; }
    .rTd--num, .rTd--actions { justify-content: flex-start; }
}
</style>
