<script setup lang="ts">
import { computed, onMounted, onBeforeUnmount, ref, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import { userApi } from "../api/user";

type CallRow = {
    id: number;
    callId: string;
    callTime: string | null;
    fromNumber: string | null;
    toNumber: string | null;
    direction: string | null;
    company: string | null;
    provider: string | null;
    durationSeconds: number;
    status: string;
    createdAt: string | null;
    category: string | null;
    categoryId: number | null;
    subCategory: string | null;
    aiSummaryStatus: string | null;
    aiCategoryStatus: string | null;
    hasAiSummary: boolean;
    hasTranscription: boolean;
    aiRecovery: {
        hasTranscript: boolean;
        canRegenerate: boolean;
        action: string | null;
        actionLabel: string | null;
    } | null;
};

type Meta = { currentPage: number; lastPage: number; perPage: number; total: number };
type Category = { id: number; name: string };

const router = useRouter();
const route = useRoute();

const loading = ref(true);
const error = ref("");
const pipelineAlert = ref("");
const regeneratingCallId = ref<number | null>(null);
const checkingTranscriptCallId = ref<number | null>(null);

const search = ref("");
const page = ref(1);
const pageSize = ref(25);
const sortBy = ref("created_at");
const sortDirection = ref<"asc" | "desc">("desc");

const filterCategory = ref("");
const filterDirection = ref("");
const filterStatus = ref("");
const filterStartDate = ref("");
const filterEndDate = ref("");
const draftFilterCategory = ref("");
const draftFilterDirection = ref("");
const draftFilterStatus = ref("");
const draftFilterStartDate = ref("");
const draftFilterEndDate = ref("");

const filtersOpen = ref(false);
const filterWrap = ref<HTMLElement | null>(null);
const isDesktop = ref(true);

const categories = ref<Category[]>([]);

const rows = ref<CallRow[]>([]);
const pendingRegenerationRowIds = new Set<number>();
const meta = ref<Meta>({ currentPage: 1, lastPage: 1, perPage: 25, total: 0 });

const PAGE_SIZE_OPTIONS = [10, 25, 50, 100, 200];

const DIRECTION_LABELS: Record<string, string> = {
    inbound: "Inbound", outbound: "Outbound", internal: "Internal",
};
const STATUS_LABELS: Record<string, string> = {
    answered: "Answered", missed: "Missed", unknown: "Unknown",
};

// ── Formatters ──────────────────────────────────────────────────────────────

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

function badgeClass(status: string): string {
    const s = String(status || "").toLowerCase();
    if (s === "completed") return "badge--active";
    if (s === "failed") return "badge--failed";
    return "badge--processing";
}

function directionLabel(direction: string | null): string {
    if (!direction) return "";
    return DIRECTION_LABELS[direction] ?? direction;
}

function sortGlyph(key: string): string {
    if (sortBy.value !== key) return "";
    return sortDirection.value === "asc" ? "▲" : "▼";
}

function toggleSort(key: string) {
    if (sortBy.value === key) {
        sortDirection.value = sortDirection.value === "asc" ? "desc" : "asc";
    } else {
        sortBy.value = key;
        sortDirection.value = "asc";
    }
    page.value = 1;
    fetchCalls();
}

// ── Fetch ────────────────────────────────────────────────────────────────────

async function fetchCalls() {
    loading.value = true;
    error.value = "";

    try {
        const params: Record<string, unknown> = {
            page: page.value,
            per_page: pageSize.value,
            search: search.value || undefined,
            sort: sortBy.value,
            direction: sortDirection.value,
        };
        if (filterCategory.value) params.category_id = filterCategory.value;
        if (filterDirection.value) params.call_direction = filterDirection.value;
        if (filterStatus.value) params.call_status = filterStatus.value;
        if (filterStartDate.value) params.start_date = filterStartDate.value;
        if (filterEndDate.value) params.end_date = filterEndDate.value;

        const res = await userApi.get<{ data: CallRow[]; meta: Meta; message?: string }>("/calls", params);
        const payload = res.data;
        rows.value = Array.isArray(payload?.data) ? payload.data : [];
        syncRegenerationAlerts();
        meta.value = payload?.meta ?? meta.value;
    } catch {
        rows.value = [];
        error.value = "Failed to load calls.";
    } finally {
        loading.value = false;
        syncProcessingPoller();
    }
}

async function loadCategories() {
    try {
        const res = await userApi.get<{ data: Category[] }>("/categories");
        categories.value = res.data?.data ?? [];
    } catch {
        // non-critical
    }
}

// ── Filters ──────────────────────────────────────────────────────────────────

function applyFilters() {
    filterCategory.value  = draftFilterCategory.value;
    filterDirection.value = draftFilterDirection.value;
    filterStatus.value    = draftFilterStatus.value;
    filterStartDate.value = draftFilterStartDate.value;
    filterEndDate.value   = draftFilterEndDate.value;
    filtersOpen.value      = false;
    page.value             = 1;
    fetchCalls();
}

function resetDraftFilters() {
    draftFilterCategory.value  = "";
    draftFilterDirection.value = "";
    draftFilterStatus.value    = "";
    draftFilterStartDate.value = "";
    draftFilterEndDate.value   = "";
}

function syncDraftFilters() {
    draftFilterCategory.value  = filterCategory.value;
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
        chips.push({ key: "direction", label: directionLabel(filterDirection.value), clear: () => { filterDirection.value = ""; page.value = 1; fetchCalls(); } });
    }
    if (filterStatus.value) {
        chips.push({ key: "status", label: STATUS_LABELS[filterStatus.value] ?? filterStatus.value, clear: () => { filterStatus.value = ""; page.value = 1; fetchCalls(); } });
    }
    if (filterCategory.value) {
        const cat = categories.value.find((c) => String(c.id) === String(filterCategory.value));
        chips.push({ key: "category", label: cat?.name ?? "Category", clear: () => { filterCategory.value = ""; page.value = 1; fetchCalls(); } });
    }
    if (filterStartDate.value || filterEndDate.value) {
        const label = filterStartDate.value && filterEndDate.value
            ? `${filterStartDate.value} → ${filterEndDate.value}`
            : filterStartDate.value ? `From ${filterStartDate.value}` : `Until ${filterEndDate.value}`;
        chips.push({ key: "dates", label, clear: () => { filterStartDate.value = ""; filterEndDate.value = ""; page.value = 1; fetchCalls(); } });
    }
    return chips;
});

// ── Pipeline actions ──────────────────────────────────────────────────────────

function syncRegenerationAlerts() {
    if (!pendingRegenerationRowIds.size) return;
    for (const rowId of Array.from(pendingRegenerationRowIds)) {
        const row = rows.value.find((r) => Number(r?.id) === Number(rowId));
        if (!row) continue;
        const catStatus = String(row?.aiCategoryStatus || "").toLowerCase();
        if (catStatus === "queued" || catStatus === "running") continue;
        if (catStatus === "not_generated" && !row?.categoryId) {
            pipelineAlert.value = `AI output was not generated for call ${row.callId}. Category remains empty. Please try Generate again.`;
        }
        pendingRegenerationRowIds.delete(rowId);
    }
}

let processingPoller = 0;

function hasActivePipelineWork(): boolean {
    return rows.value.some((row) => {
        const ss = String(row?.aiSummaryStatus || "").toLowerCase();
        const cs = String(row?.aiCategoryStatus || "").toLowerCase();
        return ss === "queued" || ss === "running" || cs === "queued" || cs === "running";
    });
}

function stopProcessingPoller() {
    if (!processingPoller) return;
    window.clearInterval(processingPoller);
    processingPoller = 0;
}

function syncProcessingPoller() {
    if (hasActivePipelineWork() && !processingPoller) {
        processingPoller = window.setInterval(() => {
            if (!loading.value) fetchCalls();
        }, 7000);
    } else if (!hasActivePipelineWork()) {
        stopProcessingPoller();
    }
}

async function regenerateRow(row: CallRow) {
    if (!row?.id || regeneratingCallId.value !== null) return;
    regeneratingCallId.value = row.id;
    error.value = "";
    pipelineAlert.value = "";
    pendingRegenerationRowIds.add(row.id);
    try {
        await userApi.post(`/calls/${row.callId}/regenerate-ai`);
        await fetchCalls();
    } catch (e: unknown) {
        pendingRegenerationRowIds.delete(row.id);
        error.value = (e as { response?: { data?: { message?: string } } })?.response?.data?.message
            ?? "Failed to queue AI regeneration for this call.";
    } finally {
        regeneratingCallId.value = null;
    }
}

async function checkTranscriptRow(row: CallRow) {
    if (!row?.id || checkingTranscriptCallId.value !== null) return;
    checkingTranscriptCallId.value = row.id;
    error.value = "";
    try {
        const res = await userApi.post<{ found: boolean; message?: string }>(`/calls/${row.callId}/check-transcript`);
        if (!res.data?.found) {
            error.value = res.data?.message ?? "Transcript is still not available for this call.";
        }
        await fetchCalls();
    } catch (e: unknown) {
        error.value = (e as { response?: { data?: { message?: string } } })?.response?.data?.message
            ?? "Failed to check transcript for this call.";
    } finally {
        checkingTranscriptCallId.value = null;
    }
}

function viewRow(row: CallRow) {
    if (!row?.callId) return;
    router.push({ name: "calls-detail", params: { callId: row.callId } });
}

function refresh() {
    fetchCalls();
}

// ── Search debounce ──────────────────────────────────────────────────────────

let searchTimer = 0;
watch(() => search.value, () => {
    if (searchTimer) window.clearTimeout(searchTimer);
    searchTimer = window.setTimeout(() => { page.value = 1; fetchCalls(); }, 250);
});

watch(() => pageSize.value, () => { page.value = 1; fetchCalls(); });

onMounted(() => {
    const q = route.query.q;
    if (typeof q === "string" && q.trim() !== "") {
        search.value = q;
    }
    syncDraftFilters();
    updateViewport();
    window.addEventListener("resize", updateViewport);
    document.addEventListener("click", onDocumentClick);
    loadCategories();
    fetchCalls();
});

onBeforeUnmount(() => {
    stopProcessingPoller();
    window.removeEventListener("resize", updateViewport);
    document.removeEventListener("click", onDocumentClick);
    if (searchTimer) window.clearTimeout(searchTimer);
});
</script>

<template>
    <div class="cPage">
        <!-- Header -->
        <div class="cPageHead">
            <div>
                <h1 class="cPageHead__title">Calls</h1>
                <p class="cPageHead__sub">Every call pulled from your phone system, with its transcript and AI category.</p>
            </div>
        </div>

        <!-- Toolbar: search + filter popover trigger + refresh -->
        <div class="cToolbar">
            <div class="cField cField--search">
                <div class="cSearchWrap">
                    <svg class="cSearchIcon" viewBox="0 0 24 24" fill="none"><circle cx="11" cy="11" r="7.2" stroke="currentColor" stroke-width="1.8"/><path d="m16.5 16.5 4 4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                    <input
                        id="calls-search"
                        v-model="search"
                        class="cInput cInput--search"
                        type="search"
                        autocomplete="off"
                        placeholder="Call ID, number, status…"
                    />
                </div>
            </div>

            <div class="cToolbar__actions">
                <div ref="filterWrap" class="cFilterPopover">
                    <button type="button" class="cBtn cBtn--secondary" @click="toggleFilters">
                        <svg viewBox="0 0 24 24" fill="none" class="cBtn__icon">
                            <path d="M4 5H20L14 12V19L10 21V12L4 5Z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                        Filter
                        <span v-if="activeChips.length" class="cFilterDot">{{ activeChips.length }}</span>
                    </button>

                    <!-- Desktop popover -->
                    <div v-if="filtersOpen && isDesktop" class="cFilterPanel" role="dialog" aria-label="Filter options">
                        <div class="cFilterPanel__header">Filter Options</div>
                        <div class="cFilterGrid">
                            <div class="cField">
                                <label class="cField__label" for="filter-direction">Direction</label>
                                <select id="filter-direction" v-model="draftFilterDirection" class="cInput cInput--select">
                                    <option value="">All</option>
                                    <option value="inbound">Inbound</option>
                                    <option value="outbound">Outbound</option>
                                    <option value="internal">Internal</option>
                                </select>
                            </div>
                            <div class="cField">
                                <label class="cField__label" for="filter-status">Status</label>
                                <select id="filter-status" v-model="draftFilterStatus" class="cInput cInput--select">
                                    <option value="">All</option>
                                    <option value="answered">Answered</option>
                                    <option value="missed">Missed</option>
                                    <option value="unknown">Unknown</option>
                                </select>
                            </div>
                            <div class="cField" style="grid-column: 1 / -1">
                                <label class="cField__label" for="filter-category">Category</label>
                                <select id="filter-category" v-model="draftFilterCategory" class="cInput cInput--select">
                                    <option value="">All</option>
                                    <option v-for="cat in categories" :key="cat.id" :value="cat.id">{{ cat.name }}</option>
                                </select>
                            </div>
                            <div class="cField">
                                <label class="cField__label" for="filter-start-date">Start date</label>
                                <input id="filter-start-date" v-model="draftFilterStartDate" type="date" class="cInput" />
                            </div>
                            <div class="cField">
                                <label class="cField__label" for="filter-end-date">End date</label>
                                <input id="filter-end-date" v-model="draftFilterEndDate" type="date" class="cInput" :min="draftFilterStartDate" />
                            </div>
                        </div>
                        <div class="cFilterActions">
                            <button type="button" class="cBtn cBtn--ghost" @click="resetDraftFilters">Reset</button>
                            <button type="button" class="cBtn cBtn--primary" @click="applyFilters">Apply</button>
                        </div>
                    </div>
                </div>

                <button type="button" class="cBtn cBtn--secondary" :disabled="loading" @click="refresh">
                    <svg viewBox="0 0 24 24" fill="none" class="cBtn__icon">
                        <path d="M20 12a8 8 0 1 1-2.34-5.66" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                        <path d="M20 4v6h-6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    Refresh
                </button>
            </div>
        </div>

        <!-- Active filter chips -->
        <div v-if="activeChips.length || meta.total" class="cChipsRow">
            <span class="cChipsRow__count">{{ formatNumber(meta.total) }} calls</span>
            <span v-for="chip in activeChips" :key="chip.key" class="cChip">
                {{ chip.label }}
                <button type="button" class="cChip__x" :aria-label="`Remove ${chip.label} filter`" @click="chip.clear">
                    <svg viewBox="0 0 24 24" fill="none"><path d="M6 6l12 12M18 6 6 18" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"/></svg>
                </button>
            </span>
        </div>

        <!-- Mobile filter modal -->
        <Teleport to="body">
            <Transition name="cModal">
                <div v-if="filtersOpen && !isDesktop" class="cOverlay" @click="filtersOpen = false">
                    <div class="cModal" @click.stop>
                        <div class="cModal__header">
                            <h2 class="cModal__title">Filter Options</h2>
                            <button type="button" class="cModal__close" aria-label="Close" @click="filtersOpen = false">
                                <svg viewBox="0 0 24 24" fill="none"><path d="M6 6l12 12M18 6 6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                            </button>
                        </div>
                        <div class="cModal__body">
                            <div class="cFilterGrid">
                                <div class="cField">
                                    <label class="cField__label">Direction</label>
                                    <select v-model="draftFilterDirection" class="cInput cInput--select">
                                        <option value="">All</option>
                                        <option value="inbound">Inbound</option>
                                        <option value="outbound">Outbound</option>
                                        <option value="internal">Internal</option>
                                    </select>
                                </div>
                                <div class="cField">
                                    <label class="cField__label">Status</label>
                                    <select v-model="draftFilterStatus" class="cInput cInput--select">
                                        <option value="">All</option>
                                        <option value="answered">Answered</option>
                                        <option value="missed">Missed</option>
                                        <option value="unknown">Unknown</option>
                                    </select>
                                </div>
                                <div class="cField" style="grid-column: 1 / -1">
                                    <label class="cField__label">Category</label>
                                    <select v-model="draftFilterCategory" class="cInput cInput--select">
                                        <option value="">All</option>
                                        <option v-for="cat in categories" :key="cat.id" :value="cat.id">{{ cat.name }}</option>
                                    </select>
                                </div>
                                <div class="cField">
                                    <label class="cField__label">Start date</label>
                                    <input v-model="draftFilterStartDate" type="date" class="cInput" />
                                </div>
                                <div class="cField">
                                    <label class="cField__label">End date</label>
                                    <input v-model="draftFilterEndDate" type="date" class="cInput" :min="draftFilterStartDate" />
                                </div>
                            </div>
                        </div>
                        <div class="cModal__footer">
                            <button type="button" class="cBtn cBtn--secondary" @click="resetDraftFilters">Reset</button>
                            <button type="button" class="cBtn cBtn--primary" @click="applyFilters">Apply</button>
                        </div>
                    </div>
                </div>
            </Transition>
        </Teleport>

        <!-- Alerts -->
        <div v-if="error" class="cAlert cAlert--error">{{ error }}</div>
        <div v-if="pipelineAlert" class="cAlert cAlert--error">{{ pipelineAlert }}</div>

        <!-- Table -->
        <div class="cCard">
            <div class="cTableWrap">
                <table class="cTable">
                    <thead>
                        <tr>
                            <th>
                                <button type="button" class="cSortBtn" @click="toggleSort('call_uid')">
                                    Call ID <span class="cSortBtn__chev">{{ sortGlyph('call_uid') }}</span>
                                </button>
                            </th>
                            <th>
                                <button type="button" class="cSortBtn" @click="toggleSort('started_at')">
                                    Time <span class="cSortBtn__chev">{{ sortGlyph('started_at') }}</span>
                                </button>
                            </th>
                            <th>
                                <button type="button" class="cSortBtn" @click="toggleSort('from')">
                                    Parties <span class="cSortBtn__chev">{{ sortGlyph('from') }}</span>
                                </button>
                            </th>
                            <th class="cCol--right">
                                <button type="button" class="cSortBtn" @click="toggleSort('duration_seconds')">
                                    Length <span class="cSortBtn__chev">{{ sortGlyph('duration_seconds') }}</span>
                                </button>
                            </th>
                            <th>
                                <button type="button" class="cSortBtn" @click="toggleSort('status')">
                                    Status <span class="cSortBtn__chev">{{ sortGlyph('status') }}</span>
                                </button>
                            </th>
                            <th>Category</th>
                            <th>
                                <button type="button" class="cSortBtn" @click="toggleSort('created_at')">
                                    Created <span class="cSortBtn__chev">{{ sortGlyph('created_at') }}</span>
                                </button>
                            </th>
                            <th class="cCol--pipeline">Transcript</th>
                            <th class="cCol--actions"></th>
                        </tr>
                    </thead>

                    <tbody v-if="loading">
                        <tr v-for="i in 10" :key="i">
                            <td colspan="9"><div class="cSkeleton"></div></td>
                        </tr>
                    </tbody>

                    <tbody v-else-if="rows.length === 0">
                        <tr>
                            <td colspan="9" class="cEmpty">
                                <div class="cEmpty__title">No calls</div>
                                <div class="cEmpty__desc">No calls match the current filters.</div>
                            </td>
                        </tr>
                    </tbody>

                    <tbody v-else>
                        <tr v-for="row in rows" :key="row.id" class="cRow" @click="viewRow(row)">
                            <td class="cMono">{{ row.callId || '—' }}</td>

                            <td>
                                <div class="cTimeCell">
                                    <span class="cMono">{{ formatDateParts(row.callTime).date }}</span>
                                    <span class="cMono cTimeCell__time">{{ formatDateParts(row.callTime).time }}</span>
                                </div>
                            </td>

                            <td>
                                <div class="cParties">
                                    <span class="cDirIcon" :class="`cDirIcon--${row.direction}`" :title="directionLabel(row.direction)">
                                        <svg v-if="row.direction === 'outbound'" viewBox="0 0 24 24" fill="none"><path d="M19 13V5m0 0h-8m8 0-9 9" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                        <svg v-else-if="row.direction === 'internal'" viewBox="0 0 24 24" fill="none"><path d="M8 7h8M8 12h8M8 17h5" stroke="currentColor" stroke-width="2.1" stroke-linecap="round"/></svg>
                                        <svg v-else viewBox="0 0 24 24" fill="none"><path d="M5 11v8m0 0h8m-8 0 9-9" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                    </span>
                                    <div class="cParties__body">
                                        <div class="cParties__from">{{ row.fromNumber || '—' }}</div>
                                        <div class="cParties__to cMono">→ {{ row.toNumber || '—' }}</div>
                                    </div>
                                </div>
                            </td>

                            <td class="cMono cCol--right">{{ formatDuration(row.durationSeconds) }}</td>

                            <td>
                                <span class="cBadge" :class="badgeClass(row.status)">
                                    {{ String(row.status || '').toUpperCase() }}
                                </span>
                            </td>

                            <td>
                                <div class="cCategory">
                                    <div>{{ row.category || '—' }}</div>
                                    <div v-if="row.subCategory" class="cCategory__sub">{{ row.subCategory }}</div>
                                </div>
                            </td>

                            <td>
                                <div class="cTimeCell">
                                    <span class="cMono">{{ formatDateParts(row.createdAt).date }}</span>
                                    <span class="cMono cTimeCell__time">{{ formatDateParts(row.createdAt).time }}</span>
                                </div>
                            </td>

                            <td class="cCol--pipeline" @click.stop>
                                <div class="cPipeline">
                                    <div class="cPipeline__step">
                                        <span v-if="row.aiRecovery?.hasTranscript" class="cReady"><svg viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" fill="currentColor"/><path d="m8 12.2 2.7 2.6L16 9.5" stroke="var(--color-surface)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>Ready</span>
                                        <template v-else>
                                            <span class="cNotReady">No transcript</span>
                                            <button
                                                class="cPipeline__btn"
                                                :disabled="checkingTranscriptCallId === row.id"
                                                @click.stop="checkTranscriptRow(row)"
                                            >
                                                {{ checkingTranscriptCallId === row.id ? '…' : 'Check again' }}
                                            </button>
                                        </template>
                                    </div>

                                    <div class="cPipeline__step">
                                        <span class="cPipeline__label">Summary</span>
                                        <span v-if="row.hasAiSummary" class="cPipeline__ok">✓</span>
                                        <span v-else-if="row.aiSummaryStatus === 'queued' || row.aiSummaryStatus === 'running'" class="cPipeline__badge">Generating…</span>
                                        <template v-else-if="row.aiRecovery?.canRegenerate && row.aiRecovery?.action === 'summary_and_category'">
                                            <span class="cPipeline__missing">Not generated</span>
                                            <button
                                                class="cPipeline__btn"
                                                :disabled="regeneratingCallId === row.id"
                                                @click.stop="regenerateRow(row)"
                                            >
                                                {{ regeneratingCallId === row.id ? '…' : 'Generate again' }}
                                            </button>
                                        </template>
                                        <span v-else class="cPipeline__dash">—</span>
                                    </div>

                                    <div class="cPipeline__step">
                                        <span class="cPipeline__label">Category</span>
                                        <span v-if="row.category" class="cPipeline__ok">✓</span>
                                        <span v-else-if="row.aiCategoryStatus === 'queued' || row.aiCategoryStatus === 'running'" class="cPipeline__badge">Generating…</span>
                                        <template v-else-if="row.aiRecovery?.canRegenerate && row.aiRecovery?.action === 'category_only'">
                                            <span class="cPipeline__missing">Not generated</span>
                                            <button
                                                class="cPipeline__btn"
                                                :disabled="regeneratingCallId === row.id"
                                                @click.stop="regenerateRow(row)"
                                            >
                                                {{ regeneratingCallId === row.id ? '…' : 'Generate again' }}
                                            </button>
                                        </template>
                                        <span v-else class="cPipeline__dash">—</span>
                                    </div>
                                </div>
                            </td>

                            <td class="cCol--actions" @click.stop>
                                <button class="cChevronBtn" type="button" aria-label="View call" @click.stop="viewRow(row)">
                                    <svg viewBox="0 0 24 24" fill="none"><path d="m9 6 6 6-6 6" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/></svg>
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Mobile card list — shown instead of the table below 720px -->
            <div class="cCardList">
                <div v-if="loading" class="cCardList__body">
                    <div v-for="i in 6" :key="i" class="cCardSkeleton"></div>
                </div>

                <div v-else-if="rows.length === 0" class="cEmpty">
                    <div class="cEmpty__title">No calls</div>
                    <div class="cEmpty__desc">No calls match the current filters.</div>
                </div>

                <div v-else class="cCardList__body">
                    <button
                        v-for="row in rows"
                        :key="row.id"
                        type="button"
                        class="cCallCard"
                        @click="viewRow(row)"
                    >
                        <div class="cCallCard__top">
                            <span class="cDirIcon" :class="`cDirIcon--${row.direction}`" :title="directionLabel(row.direction)">
                                <svg v-if="row.direction === 'outbound'" viewBox="0 0 24 24" fill="none"><path d="M19 13V5m0 0h-8m8 0-9 9" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                <svg v-else-if="row.direction === 'internal'" viewBox="0 0 24 24" fill="none"><path d="M8 7h8M8 12h8M8 17h5" stroke="currentColor" stroke-width="2.1" stroke-linecap="round"/></svg>
                                <svg v-else viewBox="0 0 24 24" fill="none"><path d="M5 11v8m0 0h8m-8 0 9-9" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </span>
                            <div class="cCallCard__parties">
                                <div class="cCallCard__from">{{ row.fromNumber || '—' }}</div>
                                <div class="cCallCard__to cMono">→ {{ row.toNumber || '—' }}</div>
                            </div>
                            <span class="cBadge" :class="badgeClass(row.status)">
                                {{ String(row.status || '').toUpperCase() }}
                            </span>
                        </div>

                        <div class="cCallCard__meta">
                            <span class="cMono">{{ formatDateParts(row.callTime).date }} {{ formatDateParts(row.callTime).time }}</span>
                            <span class="cCallCard__dot"></span>
                            <span class="cMono">{{ formatDuration(row.durationSeconds) }}</span>
                        </div>

                        <div v-if="row.category" class="cCallCard__category">
                            {{ row.category }}
                            <span v-if="row.subCategory" class="cCategory__sub">· {{ row.subCategory }}</span>
                        </div>

                        <div class="cCallCard__bottom">
                            <span v-if="row.aiRecovery?.hasTranscript" class="cReady">
                                <svg viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" fill="currentColor"/><path d="m8 12.2 2.7 2.6L16 9.5" stroke="var(--color-surface)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                Transcript ready
                            </span>
                            <span v-else class="cNotReady">No transcript</span>
                            <svg class="cCallCard__chevron" viewBox="0 0 24 24" fill="none"><path d="m9 6 6 6-6 6" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/></svg>
                        </div>
                    </button>
                </div>
            </div>

            <!-- Footer / Pagination -->
            <div class="cFooter">
                <span class="cPagerHint">Showing {{ rows.length ? ((meta.currentPage - 1) * meta.perPage) + 1 : 0 }}–{{ ((meta.currentPage - 1) * meta.perPage) + rows.length }} of {{ formatNumber(meta.total) }}</span>

                <div class="cPager">
                    <button class="cPageBtn" :disabled="meta.currentPage <= 1 || loading" @click="page = meta.currentPage - 1; fetchCalls()" aria-label="Previous page">
                        <svg viewBox="0 0 24 24" fill="none"><path d="m15 6-6 6 6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                    </button>
                    <button
                        v-for="p in meta.lastPage"
                        v-show="Math.abs(p - meta.currentPage) < 3 || p === 1 || p === meta.lastPage"
                        :key="p"
                        class="cPageBtn"
                        :class="{ 'cPageBtn--active': p === meta.currentPage }"
                        :disabled="loading"
                        @click="page = p; fetchCalls()"
                    >{{ p }}</button>
                    <button class="cPageBtn" :disabled="meta.currentPage >= meta.lastPage || loading" @click="page = meta.currentPage + 1; fetchCalls()" aria-label="Next page">
                        <svg viewBox="0 0 24 24" fill="none"><path d="m9 6 6 6-6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                    </button>
                </div>

                <div class="cPageSizeWrap">
                    <label class="cPageSizeLabel">Rows</label>
                    <select v-model="pageSize" class="cInput cInput--sm cInput--select">
                        <option v-for="s in PAGE_SIZE_OPTIONS" :key="s" :value="s">{{ s }}</option>
                    </select>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
/* ── Layout ──────────────────────────────────────────────────────────────── */
.cPage { display: flex; flex-direction: column; gap: 16px; }

/* ── Header ──────────────────────────────────────────────────────────────── */
.cPageHead { display: flex; align-items: flex-end; justify-content: space-between; gap: 20px; flex-wrap: wrap; }
.cPageHead__title { margin: 0; font-size: 1.9rem; font-weight: 700; letter-spacing: -0.015em; }
.cPageHead__sub { margin: 6px 0 0 0; color: var(--color-muted); font-size: 0.88rem; }

/* ── Toolbar (search + filter trigger + refresh) ────────────────────────── */
.cToolbar {
    display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;
    background: var(--color-surface); border: 1px solid var(--color-border); border-radius: 14px; padding: 12px 14px;
}
.cField { display: flex; flex-direction: column; gap: 6px; min-width: 0; }
.cField--search { flex: 1 1 260px; min-width: 200px; }
.cField__label { font-size: 0.68rem; font-weight: 600; letter-spacing: 0.03em; color: var(--color-muted); }

.cToolbar__actions { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }

.cSearchWrap { position: relative; display: flex; align-items: center; min-width: 0; }
.cSearchIcon { position: absolute; left: 12px; width: 15px; height: 15px; color: var(--color-muted); pointer-events: none; }
.cInput--search { padding-left: 36px; }

.cInput {
    height: 38px; padding: 0 11px; border: 1px solid var(--color-border);
    border-radius: 9px; background: var(--color-surface-2); color: inherit;
    font-size: 0.85rem; width: 100%; min-width: 0; box-sizing: border-box;
}
.cInput--select { appearance: auto; }
.cInput--sm { height: 30px; font-size: 0.82rem; width: auto; }
.cInput:focus {
    outline: none; border-color: color-mix(in srgb, var(--color-primary) 60%, var(--color-border));
    background: var(--color-surface); box-shadow: 0 0 0 3px var(--ring);
}

/* ── Filter popover (desktop) ────────────────────────────────────────────── */
.cFilterPopover { position: relative; }
.cFilterDot {
    display: inline-flex; align-items: center; justify-content: center; min-width: 16px; height: 16px;
    padding: 0 4px; border-radius: 999px; background: var(--color-primary); color: #fff;
    font-size: 0.62rem; font-weight: 700; margin-left: 2px;
}
.cFilterPanel {
    position: absolute; top: calc(100% + 10px); right: 0; z-index: 30;
    width: min(420px, 90vw); padding: 16px; border-radius: 16px;
    border: 1px solid var(--color-border); background: var(--color-surface); box-shadow: var(--shadow-lg);
}
.cFilterPanel__header { font-size: 0.9rem; font-weight: 700; margin-bottom: 12px; }
.cFilterGrid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
.cFilterActions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 14px; }

/* ── Mobile filter modal ─────────────────────────────────────────────────── */
.cOverlay {
    position: fixed; inset: 0; background: rgba(0,0,0,.5); display: grid; place-items: center; z-index: 100; padding: 16px;
}
.cModal {
    background: var(--color-surface); border: 1px solid var(--color-border); border-radius: 16px;
    width: min(420px, 100%); max-height: 85vh; overflow-y: auto; display: flex; flex-direction: column;
}
.cModal__header { display: flex; align-items: center; justify-content: space-between; padding: 16px 18px; border-bottom: 1px solid var(--color-border); }
.cModal__title { margin: 0; font-size: 1rem; font-weight: 700; }
.cModal__close { display: flex; background: none; border: none; cursor: pointer; color: var(--color-muted); padding: 2px; }
.cModal__close svg { width: 18px; height: 18px; }
.cModal__body { padding: 18px; }
.cModal__footer { display: flex; gap: 10px; justify-content: flex-end; padding: 14px 18px; border-top: 1px solid var(--color-border); }

.cModal-enter-active, .cModal-leave-active { transition: opacity 0.2s; }
.cModal-enter-from, .cModal-leave-to { opacity: 0; }

/* ── Active filter chips ─────────────────────────────────────────────────── */
.cChipsRow { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.cChipsRow__count { font-size: 0.82rem; color: var(--color-muted); }
.cChip {
    display: inline-flex; align-items: center; gap: 6px; padding: 5px 10px; border-radius: 999px;
    background: var(--color-primary-soft); border: 1px solid var(--color-primary-soft-border);
    font-size: 0.78rem; color: var(--color-primary); font-weight: 500;
}
.cChip__x { display: flex; background: none; border: none; padding: 0; cursor: pointer; color: inherit; }
.cChip__x svg { width: 12px; height: 12px; }

/* ── Buttons ─────────────────────────────────────────────────────────────── */
.cBtn {
    display: inline-flex; align-items: center; gap: 6px; height: 38px; padding: 0 15px;
    border-radius: 9px; font-size: 0.85rem; font-weight: 600; border: none; cursor: pointer;
    white-space: nowrap; transition: filter 0.15s, opacity 0.15s;
}
.cBtn--sm { height: 30px; padding: 0 10px; font-size: 0.8rem; }
.cBtn--primary { background: var(--color-primary); color: #fff; }
.cBtn--primary:hover:not(:disabled) { filter: brightness(1.08); }
.cBtn--secondary {
    background: var(--color-surface); color: inherit;
    border: 1px solid var(--color-border-strong);
}
.cBtn--secondary:hover:not(:disabled) { background: var(--color-surface-2); }
.cBtn:disabled { opacity: 0.45; cursor: not-allowed; }
.cBtn__icon { width: 15px; height: 15px; flex-shrink: 0; }

/* ── Alert ───────────────────────────────────────────────────────────────── */
.cAlert { padding: 10px 14px; border-radius: 10px; font-size: 0.9rem; }
.cAlert--error { background: var(--color-error-soft); border: 1px solid var(--color-error-soft-border); color: var(--color-error); }

/* ── Table card ──────────────────────────────────────────────────────────── */
.cCard {
    background: var(--color-surface); border: 1px solid var(--color-border);
    border-radius: 14px; overflow: hidden;
}

/* ── Mobile card list (hidden on desktop, shown <=720px) ────────────────── */
.cCardList { display: none; }
.cCardList__body { display: flex; flex-direction: column; }

.cCallCard {
    display: flex; flex-direction: column; gap: 8px; width: 100%;
    padding: 14px 16px; border: none; border-bottom: 1px solid var(--color-border);
    background: none; text-align: left; cursor: pointer; font-family: inherit; color: inherit;
}
.cCallCard:last-child { border-bottom: none; }
.cCallCard:active { background: var(--color-surface-2); }

.cCallCard__top { display: flex; align-items: center; gap: 10px; }
.cCallCard__parties { flex: 1; min-width: 0; }
.cCallCard__from { font-size: 0.88rem; font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.cCallCard__to { font-size: 0.74rem; color: var(--color-muted); }

.cCallCard__meta { display: flex; align-items: center; gap: 7px; font-size: 0.76rem; color: var(--color-muted); }
.cCallCard__dot { width: 3px; height: 3px; border-radius: 999px; background: var(--color-muted); flex-shrink: 0; }

.cCallCard__category { font-size: 0.82rem; }

.cCallCard__bottom { display: flex; align-items: center; justify-content: space-between; margin-top: 2px; }
.cCallCard__chevron { width: 16px; height: 16px; color: var(--color-muted); flex-shrink: 0; }

.cCardSkeleton {
    height: 76px; margin: 0 16px 12px; border-radius: 12px;
    background: color-mix(in srgb, var(--color-text) 8%, transparent);
    animation: pulse 1.2s ease-in-out infinite;
}
.cCardSkeleton:first-child { margin-top: 12px; }

/* ── Table ───────────────────────────────────────────────────────────────── */
.cTableWrap { overflow-x: auto; }
.cTable { width: 100%; border-collapse: collapse; font-size: 0.85rem; min-width: 1020px; }
.cTable thead tr { background: var(--color-surface-2); }
.cTable th {
    text-align: left; padding: 11px 14px; font-size: 0.68rem; text-transform: uppercase;
    letter-spacing: 0.05em; color: var(--color-muted); font-weight: 600; white-space: nowrap;
    border-bottom: 1px solid var(--color-border);
}
.cTable td { padding: 13px 14px; border-bottom: 1px solid var(--color-border); vertical-align: middle; }
.cTable tbody tr:last-child td { border-bottom: none; }
.cRow { cursor: pointer; transition: background 0.1s; }
.cRow:hover { background: var(--color-surface-2); }

/* Sortable header button */
.cSortBtn {
    background: none; border: none; cursor: pointer; font-size: 0.68rem; font-weight: 600;
    text-transform: uppercase; letter-spacing: 0.05em; color: var(--color-muted); padding: 0;
    display: inline-flex; align-items: center; gap: 4px;
}
.cSortBtn:hover { color: var(--color-text); }
.cSortBtn__chev { font-size: 0.6rem; }

/* Mono text */
.cMono { font-family: var(--font-mono); font-size: 0.8rem; }

/* Time cell */
.cTimeCell { display: flex; flex-direction: column; gap: 1px; }
.cTimeCell__time { color: var(--color-muted); font-size: 0.72rem; }

/* Direction icon */
.cDirIcon {
    width: 26px; height: 26px; border-radius: 7px; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center;
}
.cDirIcon svg { width: 13px; height: 13px; }
.cDirIcon--inbound  { background: var(--color-success-soft); color: var(--color-success); }
.cDirIcon--outbound { background: var(--color-primary-soft); color: var(--color-primary); }
.cDirIcon--internal { background: var(--color-warning-soft); color: var(--color-warning); }

/* Parties */
.cParties { display: flex; align-items: center; gap: 9px; }
.cParties__body { min-width: 0; }
.cParties__from { font-size: 0.85rem; font-weight: 500; }
.cParties__to { font-size: 0.74rem; color: var(--color-muted); }

/* Badge */
.cBadge {
    padding: 3px 9px; border-radius: 999px; font-size: 0.68rem; font-weight: 700;
    display: inline-block; white-space: nowrap;
}
.badge--active  { background: var(--color-success-soft); color: var(--color-success); }
.badge--failed  { background: var(--color-error-soft); color: var(--color-error); }
.badge--processing { background: var(--color-primary-soft); color: var(--color-primary); }

/* Category */
.cCategory__sub { font-size: 0.75rem; color: var(--color-muted); margin-top: 2px; }

/* Column helpers */
.cCol--right { text-align: right; }
.cCol--pipeline { min-width: 200px; }
.cCol--actions { width: 40px; }

/* Transcript ready/not-ready */
.cReady { display: inline-flex; align-items: center; gap: 6px; color: var(--color-success); font-size: 0.82rem; font-weight: 500; }
.cReady svg { width: 15px; height: 15px; flex-shrink: 0; }
.cNotReady { color: var(--color-muted); font-size: 0.78rem; }

/* AI Pipeline column (Summary / Category sub-rows) */
.cPipeline { display: flex; flex-direction: column; gap: 5px; }
.cPipeline__step { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
.cPipeline__label { font-size: 0.68rem; font-weight: 600; color: var(--color-muted); min-width: 58px; }
.cPipeline__ok { color: var(--color-success); font-weight: 700; font-size: 0.8rem; }
.cPipeline__badge {
    font-size: 0.68rem; font-weight: 600; padding: 1px 7px; border-radius: 999px;
    background: var(--color-warning-soft); color: var(--color-warning);
}
.cPipeline__missing { font-size: 0.7rem; color: var(--color-muted); }
.cPipeline__dash { color: var(--color-muted); font-size: 0.8rem; }
.cPipeline__btn {
    height: 22px; padding: 0 8px; border-radius: 6px; cursor: pointer; font-size: 0.68rem; font-weight: 600;
    background: var(--color-primary); color: #fff; border: none; white-space: nowrap;
}
.cPipeline__btn:hover:not(:disabled) { filter: brightness(1.08); }
.cPipeline__btn:disabled { opacity: 0.5; cursor: not-allowed; }

/* Row chevron */
.cChevronBtn {
    display: flex; align-items: center; justify-content: center; width: 26px; height: 26px;
    border-radius: 7px; background: none; border: none; cursor: pointer; color: var(--color-muted);
}
.cChevronBtn:hover { background: var(--color-surface-2); color: var(--color-text); }
.cChevronBtn svg { width: 16px; height: 16px; }

/* Skeleton */
.cSkeleton { height: 20px; border-radius: 6px; background: color-mix(in srgb, var(--color-text) 8%, transparent); animation: pulse 1.2s ease-in-out infinite; }
@keyframes pulse { 0%,100% { opacity: 1; } 50% { opacity: 0.45; } }

/* Empty */
.cEmpty { padding: 3rem; text-align: center; }
.cEmpty__title { font-size: 1rem; font-weight: 600; margin-bottom: 4px; }
.cEmpty__desc { font-size: 0.875rem; color: var(--color-muted); }

/* ── Footer / Pagination ─────────────────────────────────────────────────── */
.cFooter {
    display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap;
    padding: 13px 16px; border-top: 1px solid var(--color-border); background: var(--color-surface-2);
}
.cPageSizeWrap { display: flex; align-items: center; gap: 6px; }
.cPageSizeLabel { font-size: 0.8rem; color: var(--color-muted); }
.cPager { display: flex; align-items: center; justify-content: center; gap: 5px; flex-wrap: wrap; }
.cPagerHint { font-size: 0.8rem; color: var(--color-muted); }

.cPageBtn {
    min-width: 34px; height: 34px; padding: 0 8px; border-radius: 8px;
    border: 1px solid var(--color-border-strong); background: var(--color-surface); color: inherit;
    font-size: 0.82rem; cursor: pointer; display: flex; align-items: center; justify-content: center;
}
.cPageBtn:hover:not(:disabled):not(.cPageBtn--active) { background: var(--color-surface-2); }
.cPageBtn:disabled { opacity: 0.4; cursor: not-allowed; }
.cPageBtn--active { background: var(--color-primary); border-color: var(--color-primary); color: #fff; font-weight: 600; }
.cPageBtn svg { width: 15px; height: 15px; }

@media (max-width: 640px) {
    .cFilterGrid { grid-template-columns: 1fr; }
}

/* ── Responsive ──────────────────────────────────────────────────────────── */
@media (max-width: 720px) {
    .cToolbar { padding: 10px 12px; }
    .cField--search { flex-basis: 100%; }
    .cToolbar__actions { width: 100%; }
    .cToolbar__actions .cFilterPopover,
    .cToolbar__actions .cBtn { flex: 1; }
    .cToolbar__actions .cBtn { justify-content: center; width: 100%; }
    .cPageHead { flex-direction: column; align-items: flex-start; }
    .cFooter { flex-direction: column; justify-content: center; text-align: center; }

    /* App-like card list instead of a squeezed desktop table. */
    .cTableWrap { display: none; }
    .cCardList { display: block; }
}
</style>
