<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from "vue";
import { useRouter } from "vue-router";
import { userApi } from "../api/user";

type Row = {
    id: number;
    pbx_unique_id: string;
    from: string | null;
    to: string | null;
    direction: string | null;
    status: string;
    duration_seconds: number;
    started_at: string | null;
    ai_summary: string | null;
    transcript_text: string | null;
};

type Meta = { currentPage: number; lastPage: number; perPage: number; total: number };

const router = useRouter();

const loading = ref(true);
const rows = ref<Row[]>([]);
const meta = ref<Meta>({ currentPage: 1, lastPage: 1, perPage: 25, total: 0 });
const error = ref<string | null>(null);
const emptyMessage = ref<string | null>(null);
const expandedId = ref<number | null>(null);

const search = ref("");
const page = ref(1);

const filterDirection = ref("");
const filterStatus = ref("");
const filterStartDate = ref("");
const filterEndDate = ref("");
const draftFilterDirection = ref("");
const draftFilterStatus = ref("");
const draftFilterStartDate = ref("");
const draftFilterEndDate = ref("");

const filtersOpen = ref(false);
const filterWrap = ref<HTMLElement | null>(null);
const isDesktop = ref(true);

const DIRECTION_LABELS: Record<string, string> = {
    inbound: "Inbound", outbound: "Outbound", internal: "Internal",
};
const STATUS_LABELS: Record<string, string> = {
    answered: "Answered", missed: "Missed", unknown: "Unknown",
};

function directionLabel(direction: string | null): string {
    if (!direction) return "";
    return DIRECTION_LABELS[direction] ?? direction;
}

function badgeClass(status: string): string {
    const s = String(status || "").toLowerCase();
    if (s === "answered" || s === "completed") return "badge--active";
    if (s === "missed" || s === "failed") return "badge--failed";
    return "badge--processing";
}

function formatDuration(seconds: number | null): string {
    const s = Number(seconds);
    if (!Number.isFinite(s) || s < 0) return "—";
    const m = Math.floor(s / 60);
    const sec = Math.floor(s % 60);
    return `${m}:${String(sec).padStart(2, "0")}`;
}

function formatDateParts(iso: string | null): { date: string; time: string } {
    if (!iso) return { date: "—", time: "" };
    const t = new Date(iso);
    if (!Number.isFinite(t.getTime())) return { date: "—", time: "" };
    return {
        date: t.toLocaleDateString(undefined, { day: "2-digit", month: "short" }),
        time: t.toLocaleTimeString(undefined, { hour: "2-digit", minute: "2-digit" }),
    };
}

function formatNumber(n: number): string {
    return Number.isFinite(n) ? new Intl.NumberFormat("en-US").format(n) : "—";
}

async function load(p = page.value) {
    loading.value = true;
    error.value = null;
    try {
        const params: Record<string, unknown> = {
            page: p,
            per_page: 25,
            search: search.value || undefined,
        };
        if (filterDirection.value) params.call_direction = filterDirection.value;
        if (filterStatus.value) params.call_status = filterStatus.value;
        if (filterStartDate.value) params.start_date = filterStartDate.value;
        if (filterEndDate.value) params.end_date = filterEndDate.value;

        const res = await userApi.get<{ data: Row[]; meta: Meta; message?: string }>("/transcriptions", params);
        rows.value = res.data.data ?? [];
        meta.value = res.data.meta ?? meta.value;
        emptyMessage.value = res.data.message ?? null;
        page.value = p;
    } catch (e) {
        error.value = e instanceof Error ? e.message : "Failed to load transcriptions.";
    } finally {
        loading.value = false;
    }
}

function toggle(id: number) {
    expandedId.value = expandedId.value === id ? null : id;
}

function viewCall(row: Row) {
    router.push({ name: "calls-detail", params: { callId: row.pbx_unique_id } });
}

let searchTimer: ReturnType<typeof setTimeout> | null = null;
watch(search, () => {
    if (searchTimer) clearTimeout(searchTimer);
    searchTimer = setTimeout(() => load(1), 300);
});

// ── Filters ──────────────────────────────────────────────────────────────
function applyFilters() {
    filterDirection.value = draftFilterDirection.value;
    filterStatus.value    = draftFilterStatus.value;
    filterStartDate.value = draftFilterStartDate.value;
    filterEndDate.value   = draftFilterEndDate.value;
    filtersOpen.value      = false;
    load(1);
}

function resetDraftFilters() {
    draftFilterDirection.value = "";
    draftFilterStatus.value    = "";
    draftFilterStartDate.value = "";
    draftFilterEndDate.value   = "";
}

function syncDraftFilters() {
    draftFilterDirection.value = filterDirection.value;
    draftFilterStatus.value    = filterStatus.value;
    draftFilterStartDate.value = filterStartDate.value;
    draftFilterEndDate.value   = filterEndDate.value;
}

function toggleFilters() {
    filtersOpen.value = !filtersOpen.value;
    if (filtersOpen.value) syncDraftFilters();
}

function updateViewport() {
    isDesktop.value = window.innerWidth >= 1024;
}

function onDocumentClick(event: Event) {
    if (!filtersOpen.value || !isDesktop.value) return;
    const target = event.target;
    if (!filterWrap.value || !(target instanceof Node)) return;
    if (filterWrap.value.contains(target)) return;
    filtersOpen.value = false;
}

type Chip = { key: string; label: string; clear: () => void };

const activeChips = computed<Chip[]>(() => {
    const chips: Chip[] = [];
    if (filterDirection.value) {
        chips.push({ key: "direction", label: directionLabel(filterDirection.value), clear: () => { filterDirection.value = ""; load(1); } });
    }
    if (filterStatus.value) {
        chips.push({ key: "status", label: STATUS_LABELS[filterStatus.value] ?? filterStatus.value, clear: () => { filterStatus.value = ""; load(1); } });
    }
    if (filterStartDate.value || filterEndDate.value) {
        const label = filterStartDate.value && filterEndDate.value
            ? `${filterStartDate.value} → ${filterEndDate.value}`
            : filterStartDate.value ? `From ${filterStartDate.value}` : `Until ${filterEndDate.value}`;
        chips.push({ key: "dates", label, clear: () => { filterStartDate.value = ""; filterEndDate.value = ""; load(1); } });
    }
    return chips;
});

onMounted(() => {
    updateViewport();
    window.addEventListener("resize", updateViewport);
    document.addEventListener("click", onDocumentClick);
    load();
});

onBeforeUnmount(() => {
    window.removeEventListener("resize", updateViewport);
    document.removeEventListener("click", onDocumentClick);
    if (searchTimer) clearTimeout(searchTimer);
});
</script>

<template>
    <div class="tPage">
        <!-- Header -->
        <div class="tPageHead">
            <div>
                <h1 class="tPageHead__title">Transcriptions</h1>
                <p class="tPageHead__sub">Call transcripts and AI summaries for your company.</p>
            </div>
        </div>

        <!-- Toolbar -->
        <div class="tToolbar">
            <div class="tField tField--search">
                <div class="tSearchWrap">
                    <svg class="tSearchIcon" viewBox="0 0 24 24" fill="none"><circle cx="11" cy="11" r="7.2" stroke="currentColor" stroke-width="1.8"/><path d="m16.5 16.5 4 4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                    <input
                        v-model="search"
                        class="tInput tInput--search"
                        type="search"
                        placeholder="Transcript, number, call ID…"
                    />
                </div>
            </div>

            <div class="tToolbar__actions">
                <div ref="filterWrap" class="tFilterPopover">
                    <button type="button" class="tBtn tBtn--secondary" @click="toggleFilters">
                        <svg viewBox="0 0 24 24" fill="none" class="tBtn__icon">
                            <path d="M4 5H20L14 12V19L10 21V12L4 5Z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                        Filter
                        <span v-if="activeChips.length" class="tFilterDot">{{ activeChips.length }}</span>
                    </button>

                    <div v-if="filtersOpen && isDesktop" class="tFilterPanel" role="dialog" aria-label="Filter options">
                        <div class="tFilterPanel__header">Filter Options</div>
                        <div class="tFilterGrid">
                            <div class="tField">
                                <label class="tField__label">Direction</label>
                                <select v-model="draftFilterDirection" class="tInput tInput--select">
                                    <option value="">All</option>
                                    <option value="inbound">Inbound</option>
                                    <option value="outbound">Outbound</option>
                                    <option value="internal">Internal</option>
                                </select>
                            </div>
                            <div class="tField">
                                <label class="tField__label">Status</label>
                                <select v-model="draftFilterStatus" class="tInput tInput--select">
                                    <option value="">All</option>
                                    <option value="answered">Answered</option>
                                    <option value="missed">Missed</option>
                                    <option value="unknown">Unknown</option>
                                </select>
                            </div>
                            <div class="tField">
                                <label class="tField__label">Start date</label>
                                <input v-model="draftFilterStartDate" type="date" class="tInput" />
                            </div>
                            <div class="tField">
                                <label class="tField__label">End date</label>
                                <input v-model="draftFilterEndDate" type="date" class="tInput" :min="draftFilterStartDate" />
                            </div>
                        </div>
                        <div class="tFilterActions">
                            <button type="button" class="tBtn tBtn--ghost" @click="resetDraftFilters">Reset</button>
                            <button type="button" class="tBtn tBtn--primary" @click="applyFilters">Apply</button>
                        </div>
                    </div>
                </div>

                <button type="button" class="tBtn tBtn--secondary" :disabled="loading" @click="load(page)">
                    <svg viewBox="0 0 24 24" fill="none" class="tBtn__icon">
                        <path d="M20 12a8 8 0 1 1-2.34-5.66" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                        <path d="M20 4v6h-6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    Refresh
                </button>
            </div>
        </div>

        <!-- Active filter chips -->
        <div v-if="activeChips.length || meta.total" class="tChipsRow">
            <span class="tChipsRow__count">{{ formatNumber(meta.total) }} transcripts</span>
            <span v-for="chip in activeChips" :key="chip.key" class="tChip">
                {{ chip.label }}
                <button type="button" class="tChip__x" :aria-label="`Remove ${chip.label} filter`" @click="chip.clear">
                    <svg viewBox="0 0 24 24" fill="none"><path d="M6 6l12 12M18 6 6 18" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"/></svg>
                </button>
            </span>
        </div>

        <!-- Mobile filter modal -->
        <Teleport to="body">
            <Transition name="tModal">
                <div v-if="filtersOpen && !isDesktop" class="tOverlay" @click="filtersOpen = false">
                    <div class="tModal" @click.stop>
                        <div class="tModal__header">
                            <h2 class="tModal__title">Filter Options</h2>
                            <button type="button" class="tModal__close" aria-label="Close" @click="filtersOpen = false">
                                <svg viewBox="0 0 24 24" fill="none"><path d="M6 6l12 12M18 6 6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                            </button>
                        </div>
                        <div class="tModal__body">
                            <div class="tFilterGrid">
                                <div class="tField">
                                    <label class="tField__label">Direction</label>
                                    <select v-model="draftFilterDirection" class="tInput tInput--select">
                                        <option value="">All</option>
                                        <option value="inbound">Inbound</option>
                                        <option value="outbound">Outbound</option>
                                        <option value="internal">Internal</option>
                                    </select>
                                </div>
                                <div class="tField">
                                    <label class="tField__label">Status</label>
                                    <select v-model="draftFilterStatus" class="tInput tInput--select">
                                        <option value="">All</option>
                                        <option value="answered">Answered</option>
                                        <option value="missed">Missed</option>
                                        <option value="unknown">Unknown</option>
                                    </select>
                                </div>
                                <div class="tField">
                                    <label class="tField__label">Start date</label>
                                    <input v-model="draftFilterStartDate" type="date" class="tInput" />
                                </div>
                                <div class="tField">
                                    <label class="tField__label">End date</label>
                                    <input v-model="draftFilterEndDate" type="date" class="tInput" :min="draftFilterStartDate" />
                                </div>
                            </div>
                        </div>
                        <div class="tModal__footer">
                            <button type="button" class="tBtn tBtn--secondary" @click="resetDraftFilters">Reset</button>
                            <button type="button" class="tBtn tBtn--primary" @click="applyFilters">Apply</button>
                        </div>
                    </div>
                </div>
            </Transition>
        </Teleport>

        <div v-if="error" class="tAlert tAlert--error">{{ error }}</div>

        <!-- List card -->
        <div class="tCard">
            <div v-if="loading" class="tListBody">
                <div v-for="i in 6" :key="i" class="tSkeleton"></div>
            </div>

            <div v-else-if="rows.length === 0" class="tEmpty">
                <div class="tEmpty__title">No transcriptions</div>
                <div class="tEmpty__desc">{{ emptyMessage || "No transcripts match the current filters." }}</div>
            </div>

            <div v-else class="tListBody">
                <div v-for="r in rows" :key="r.id" class="tItem">
                    <button type="button" class="tItemHead" @click="toggle(r.id)">
                        <span class="tDirIcon" :class="`tDirIcon--${r.direction}`" :title="directionLabel(r.direction)">
                            <svg v-if="r.direction === 'outbound'" viewBox="0 0 24 24" fill="none"><path d="M19 13V5m0 0h-8m8 0-9 9" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            <svg v-else-if="r.direction === 'internal'" viewBox="0 0 24 24" fill="none"><path d="M8 7h8M8 12h8M8 17h5" stroke="currentColor" stroke-width="2.1" stroke-linecap="round"/></svg>
                            <svg v-else viewBox="0 0 24 24" fill="none"><path d="M5 11v8m0 0h8m-8 0 9-9" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </span>

                        <div class="tItemHead__parties">
                            <div class="tItemHead__from">{{ r.from || "—" }}</div>
                            <div class="tItemHead__to tMono">→ {{ r.to || "—" }}</div>
                        </div>

                        <div class="tItemHead__time">
                            <span class="tMono">{{ formatDateParts(r.started_at).date }} {{ formatDateParts(r.started_at).time }}</span>
                        </div>

                        <span class="tMono tItemHead__duration">{{ formatDuration(r.duration_seconds) }}</span>

                        <span class="tBadge" :class="badgeClass(r.status)">{{ String(r.status || '').toUpperCase() }}</span>

                        <svg class="tChev" :class="{ open: expandedId === r.id }" viewBox="0 0 24 24" fill="none"><path d="m9 6 6 6-6 6" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/></svg>
                    </button>

                    <div v-if="expandedId === r.id" class="tItemBody">
                        <div v-if="r.ai_summary" class="tBlock">
                            <h4 class="tBlock__title">AI Summary</h4>
                            <p class="tBlock__summary">{{ r.ai_summary }}</p>
                        </div>
                        <div class="tBlock">
                            <h4 class="tBlock__title">Transcript</h4>
                            <pre class="tBlock__transcript">{{ r.transcript_text }}</pre>
                        </div>
                        <button type="button" class="tViewCallLink" @click="viewCall(r)">
                            View full call →
                        </button>
                    </div>
                </div>
            </div>

            <!-- Footer / Pagination -->
            <div v-if="!loading && rows.length" class="tFooter">
                <span class="tPagerHint">Showing {{ ((meta.currentPage - 1) * meta.perPage) + 1 }}–{{ ((meta.currentPage - 1) * meta.perPage) + rows.length }} of {{ formatNumber(meta.total) }}</span>

                <div class="tPager">
                    <button class="tPageBtn" :disabled="meta.currentPage <= 1" @click="load(meta.currentPage - 1)" aria-label="Previous page">
                        <svg viewBox="0 0 24 24" fill="none"><path d="m15 6-6 6 6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                    </button>
                    <button
                        v-for="p in meta.lastPage"
                        v-show="Math.abs(p - meta.currentPage) < 3 || p === 1 || p === meta.lastPage"
                        :key="p"
                        class="tPageBtn"
                        :class="{ 'tPageBtn--active': p === meta.currentPage }"
                        @click="load(p)"
                    >{{ p }}</button>
                    <button class="tPageBtn" :disabled="meta.currentPage >= meta.lastPage" @click="load(meta.currentPage + 1)" aria-label="Next page">
                        <svg viewBox="0 0 24 24" fill="none"><path d="m9 6 6 6-6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
/* ── Layout ──────────────────────────────────────────────────────────────── */
.tPage { display: flex; flex-direction: column; gap: 16px; }

/* ── Header ──────────────────────────────────────────────────────────────── */
.tPageHead { display: flex; align-items: flex-end; justify-content: space-between; gap: 20px; flex-wrap: wrap; }
.tPageHead__title { margin: 0; font-size: 1.9rem; font-weight: 700; letter-spacing: -0.015em; }
.tPageHead__sub { margin: 6px 0 0 0; color: var(--color-muted); font-size: 0.88rem; }

/* ── Toolbar ─────────────────────────────────────────────────────────────── */
.tToolbar {
    display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;
    background: var(--color-surface); border: 1px solid var(--color-border); border-radius: 14px; padding: 12px 14px;
}
.tField { display: flex; flex-direction: column; gap: 6px; min-width: 0; }
.tField--search { flex: 1 1 260px; min-width: 200px; }
.tField__label { font-size: 0.68rem; font-weight: 600; letter-spacing: 0.03em; color: var(--color-muted); }
.tToolbar__actions { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }

.tSearchWrap { position: relative; display: flex; align-items: center; min-width: 0; }
.tSearchIcon { position: absolute; left: 12px; width: 15px; height: 15px; color: var(--color-muted); pointer-events: none; }
.tInput--search { padding-left: 36px; }

.tInput {
    height: 38px; padding: 0 11px; border: 1px solid var(--color-border);
    border-radius: 9px; background: var(--color-surface-2); color: inherit;
    font-size: 0.85rem; width: 100%; min-width: 0; box-sizing: border-box;
}
.tInput--select { appearance: auto; }
.tInput:focus {
    outline: none; border-color: color-mix(in srgb, var(--color-primary) 60%, var(--color-border));
    background: var(--color-surface); box-shadow: 0 0 0 3px var(--ring);
}

/* ── Filter popover (desktop) ────────────────────────────────────────────── */
.tFilterPopover { position: relative; }
.tFilterDot {
    display: inline-flex; align-items: center; justify-content: center; min-width: 16px; height: 16px;
    padding: 0 4px; border-radius: 999px; background: var(--color-primary); color: #fff;
    font-size: 0.62rem; font-weight: 700; margin-left: 2px;
}
.tFilterPanel {
    position: absolute; top: calc(100% + 10px); right: 0; z-index: 30;
    width: min(420px, 90vw); padding: 16px; border-radius: 16px;
    border: 1px solid var(--color-border); background: var(--color-surface); box-shadow: var(--shadow-lg);
}
.tFilterPanel__header { font-size: 0.9rem; font-weight: 700; margin-bottom: 12px; }
.tFilterGrid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
.tFilterActions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 14px; }

@media (max-width: 640px) {
    .tFilterGrid { grid-template-columns: 1fr; }
}

/* ── Mobile filter modal ─────────────────────────────────────────────────── */
.tOverlay { position: fixed; inset: 0; background: rgba(0,0,0,.5); display: grid; place-items: center; z-index: 100; padding: 16px; }
.tModal {
    background: var(--color-surface); border: 1px solid var(--color-border); border-radius: 16px;
    width: min(420px, 100%); max-height: 85vh; overflow-y: auto; display: flex; flex-direction: column;
}
.tModal__header { display: flex; align-items: center; justify-content: space-between; padding: 16px 18px; border-bottom: 1px solid var(--color-border); }
.tModal__title { margin: 0; font-size: 1rem; font-weight: 700; }
.tModal__close { display: flex; background: none; border: none; cursor: pointer; color: var(--color-muted); padding: 2px; }
.tModal__close svg { width: 18px; height: 18px; }
.tModal__body { padding: 18px; }
.tModal__footer { display: flex; gap: 10px; justify-content: flex-end; padding: 14px 18px; border-top: 1px solid var(--color-border); }
.tModal-enter-active, .tModal-leave-active { transition: opacity 0.2s; }
.tModal-enter-from, .tModal-leave-to { opacity: 0; }

/* ── Active filter chips ─────────────────────────────────────────────────── */
.tChipsRow { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.tChipsRow__count { font-size: 0.82rem; color: var(--color-muted); }
.tChip {
    display: inline-flex; align-items: center; gap: 6px; padding: 5px 10px; border-radius: 999px;
    background: var(--color-primary-soft); border: 1px solid var(--color-primary-soft-border);
    font-size: 0.78rem; color: var(--color-primary); font-weight: 500;
}
.tChip__x { display: flex; background: none; border: none; padding: 0; cursor: pointer; color: inherit; }
.tChip__x svg { width: 12px; height: 12px; }

/* ── Buttons ─────────────────────────────────────────────────────────────── */
.tBtn {
    display: inline-flex; align-items: center; gap: 6px; height: 38px; padding: 0 15px;
    border-radius: 9px; font-size: 0.85rem; font-weight: 600; border: none; cursor: pointer;
    white-space: nowrap; transition: filter 0.15s, opacity 0.15s;
}
.tBtn--primary { background: var(--color-primary); color: #fff; }
.tBtn--primary:hover:not(:disabled) { filter: brightness(1.08); }
.tBtn--secondary { background: var(--color-surface); color: inherit; border: 1px solid var(--color-border-strong); }
.tBtn--secondary:hover:not(:disabled) { background: var(--color-surface-2); }
.tBtn--ghost { background: transparent; color: inherit; border: 1px solid var(--color-border); }
.tBtn--ghost:hover:not(:disabled) { background: var(--color-surface-2); }
.tBtn:disabled { opacity: 0.45; cursor: not-allowed; }
.tBtn__icon { width: 15px; height: 15px; flex-shrink: 0; }

/* ── Alert ───────────────────────────────────────────────────────────────── */
.tAlert { padding: 10px 14px; border-radius: 10px; font-size: 0.9rem; }
.tAlert--error { background: var(--color-error-soft); border: 1px solid var(--color-error-soft-border); color: var(--color-error); }

/* ── Card / list ─────────────────────────────────────────────────────────── */
.tCard { background: var(--color-surface); border: 1px solid var(--color-border); border-radius: 14px; overflow: hidden; }
.tListBody { display: flex; flex-direction: column; }
.tMono { font-family: var(--font-mono); font-size: 0.82rem; }

.tItem { border-bottom: 1px solid var(--color-border); }
.tItem:last-child { border-bottom: none; }

.tItemHead {
    width: 100%; display: grid; grid-template-columns: auto minmax(0,1.7fr) minmax(0,1.3fr) auto auto auto;
    align-items: center; gap: 14px; padding: 13px 16px;
    background: none; border: none; cursor: pointer; color: inherit; text-align: left; font-family: inherit;
}
.tItemHead:hover { background: var(--color-surface-2); }

.tDirIcon {
    width: 30px; height: 30px; border-radius: 8px; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center;
}
.tDirIcon svg { width: 14px; height: 14px; }
.tDirIcon--inbound  { background: var(--color-success-soft); color: var(--color-success); }
.tDirIcon--outbound { background: var(--color-primary-soft); color: var(--color-primary); }
.tDirIcon--internal { background: var(--color-warning-soft); color: var(--color-warning); }

.tItemHead__parties { min-width: 0; }
.tItemHead__from { font-size: 0.87rem; font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.tItemHead__to { font-size: 0.74rem; color: var(--color-muted); }
.tItemHead__time { font-size: 0.8rem; color: var(--color-muted); }
.tItemHead__duration { font-size: 0.82rem; }

.tChev { width: 16px; height: 16px; color: var(--color-muted); flex-shrink: 0; transition: transform 0.15s; }
.tChev.open { transform: rotate(90deg); }

.tBadge {
    padding: 3px 9px; border-radius: 999px; font-size: 0.68rem; font-weight: 700;
    display: inline-block; white-space: nowrap; justify-self: start;
}
.badge--active { background: var(--color-success-soft); color: var(--color-success); }
.badge--failed { background: var(--color-error-soft); color: var(--color-error); }
.badge--processing { background: var(--color-primary-soft); color: var(--color-primary); }

.tItemBody { padding: 0 16px 18px 60px; display: flex; flex-direction: column; gap: 14px; background: var(--color-surface-2); }
.tBlock__title { margin: 0 0 6px; font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--color-muted); font-weight: 600; }
.tBlock__summary { margin: 0; font-size: 0.88rem; line-height: 1.55; }
.tBlock__transcript {
    margin: 0; white-space: pre-wrap; word-break: break-word;
    font-family: inherit; font-size: 0.85rem; line-height: 1.6; color: var(--color-text);
    max-height: 360px; overflow-y: auto;
}
.tViewCallLink {
    align-self: flex-start; background: none; border: none; cursor: pointer; padding: 0;
    color: var(--color-primary); font-size: 0.82rem; font-weight: 600;
}
.tViewCallLink:hover { text-decoration: underline; }

/* Skeleton */
.tSkeleton {
    height: 58px; margin: 10px 16px; border-radius: 10px;
    background: color-mix(in srgb, var(--color-text) 8%, transparent);
    animation: pulse 1.2s ease-in-out infinite;
}
.tSkeleton:first-child { margin-top: 16px; }
@keyframes pulse { 0%,100% { opacity: 1; } 50% { opacity: 0.45; } }

/* Empty */
.tEmpty { padding: 3rem; text-align: center; }
.tEmpty__title { font-size: 1rem; font-weight: 600; margin-bottom: 4px; }
.tEmpty__desc { font-size: 0.875rem; color: var(--color-muted); }

/* ── Footer / Pagination ─────────────────────────────────────────────────── */
.tFooter {
    display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap;
    padding: 13px 16px; border-top: 1px solid var(--color-border); background: var(--color-surface-2);
}
.tPagerHint { font-size: 0.8rem; color: var(--color-muted); }
.tPager { display: flex; align-items: center; justify-content: center; gap: 5px; flex-wrap: wrap; }
.tPageBtn {
    min-width: 34px; height: 34px; padding: 0 8px; border-radius: 8px;
    border: 1px solid var(--color-border-strong); background: var(--color-surface); color: inherit;
    font-size: 0.82rem; cursor: pointer; display: flex; align-items: center; justify-content: center;
}
.tPageBtn:hover:not(:disabled):not(.tPageBtn--active) { background: var(--color-surface-2); }
.tPageBtn:disabled { opacity: 0.4; cursor: not-allowed; }
.tPageBtn--active { background: var(--color-primary); border-color: var(--color-primary); color: #fff; font-weight: 600; }
.tPageBtn svg { width: 15px; height: 15px; }

/* ── Responsive ──────────────────────────────────────────────────────────── */
@media (max-width: 860px) {
    .tItemHead { grid-template-columns: auto minmax(0,1fr) auto auto; }
    .tItemHead__time, .tItemHead__duration { display: none; }
}

@media (max-width: 720px) {
    .tToolbar { padding: 10px 12px; }
    .tField--search { flex-basis: 100%; }
    .tToolbar__actions { width: 100%; }
    .tToolbar__actions .tFilterPopover,
    .tToolbar__actions .tBtn { flex: 1; }
    .tToolbar__actions .tBtn { justify-content: center; width: 100%; }
    .tPageHead { flex-direction: column; align-items: flex-start; }
    .tItemHead { grid-template-columns: auto minmax(0,1fr) auto; gap: 10px; padding: 12px; }
    .tItemBody { padding: 0 14px 16px 50px; }
    .tFooter { flex-direction: column; text-align: center; }
}
</style>
