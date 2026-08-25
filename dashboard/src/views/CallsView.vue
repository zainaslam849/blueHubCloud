<script setup lang="ts">
import { onMounted, onBeforeUnmount, ref, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import { userApi } from "../api/user";

type CallRow = {
    id: number;
    callId: string;
    callTime: string | null;
    fromNumber: string | null;
    toNumber: string | null;
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
const filterStartDate = ref("");
const filterEndDate = ref("");
const draftFilterCategory = ref("");
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

// ── Formatters ──────────────────────────────────────────────────────────────

function formatDuration(seconds: number | null): string {
    const s = Number(seconds);
    if (!Number.isFinite(s) || s < 0) return "—";
    if (s === 0) return "0 seconds";
    const m = Math.floor(s / 60);
    const sec = Math.floor(s % 60);
    const parts: string[] = [];
    if (m > 0) parts.push(`${m} ${m === 1 ? "minute" : "minutes"}`);
    if (sec > 0) parts.push(`${sec} ${sec === 1 ? "second" : "seconds"}`);
    return parts.join(" ");
}

function formatDate(iso: string | null): string {
    if (!iso) return "—";
    const t = new Date(iso);
    if (!Number.isFinite(t.getTime())) return "—";
    return t.toLocaleString();
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
    filterStartDate.value = draftFilterStartDate.value;
    filterEndDate.value   = draftFilterEndDate.value;
    filtersOpen.value     = false;
    page.value            = 1;
    fetchCalls();
}

function resetDraftFilters() {
    draftFilterCategory.value  = "";
    draftFilterStartDate.value = "";
    draftFilterEndDate.value   = "";
}

function syncDraftFilters() {
    draftFilterCategory.value  = filterCategory.value;
    draftFilterStartDate.value = filterStartDate.value;
    draftFilterEndDate.value   = filterEndDate.value;
}

function toggleFilters() {
    filtersOpen.value = !filtersOpen.value;
    if (filtersOpen.value) syncDraftFilters();
}

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

// ── Viewport & click outside ─────────────────────────────────────────────────

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
        <!-- Page Header -->
        <header class="cHeader">
            <div class="cHeader__left">
                <div class="cHeader__icon">
                    <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z" fill="currentColor"/>
                    </svg>
                </div>
                <div>
                    <p class="cHeader__kicker">Operations</p>
                    <h1 class="cHeader__title">Calls</h1>
                    <p class="cHeader__subtitle">Manage and review all incoming and outgoing calls.</p>
                </div>
            </div>
            <div class="cHeader__stats">
                <div class="cHeader__stat">
                    <div class="cHeader__statValue">{{ formatNumber(meta.total) }}</div>
                    <div class="cHeader__statLabel">Total Calls</div>
                </div>
            </div>
        </header>

        <div class="cCard">
            <!-- Toolbar -->
            <div class="cToolbar">
                <div class="cToolbar__left">
                    <div class="cField cField--search">
                        <label class="cField__label" for="calls-search">Search</label>
                        <input
                            id="calls-search"
                            v-model="search"
                            class="cInput"
                            type="search"
                            autocomplete="off"
                            placeholder="Call ID, number, status…"
                        />
                    </div>
                </div>

                <div class="cToolbar__right">
                    <div ref="filterWrap" class="cFilterWrap">
                        <button type="button" class="cBtn cBtn--secondary" @click="toggleFilters">
                            <svg viewBox="0 0 24 24" fill="none" class="cBtn__icon">
                                <path d="M4 5H20L14 12V19L10 21V12L4 5Z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                            Filter
                            <span v-if="filterCategory || filterStartDate || filterEndDate" class="cFilterDot"></span>
                        </button>

                        <!-- Desktop filter panel -->
                        <div v-if="filtersOpen && isDesktop" class="cFilterPanel" role="dialog" aria-label="Filter options">
                            <div class="cFilterPanel__header">Filter Options</div>
                            <div class="cFilterGrid">
                                <div class="cField">
                                    <label class="cField__label" for="filter-category">Category</label>
                                    <select id="filter-category" v-model="draftFilterCategory" class="cInput cInput--select">
                                        <option value="">All Categories</option>
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
                                <button class="cBtn cBtn--ghost" type="button" @click="resetDraftFilters">Reset</button>
                                <button class="cBtn cBtn--primary" type="button" @click="applyFilters">Apply</button>
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

            <!-- Alerts -->
            <div v-if="error" class="cAlert cAlert--error">{{ error }}</div>
            <div v-if="pipelineAlert" class="cAlert cAlert--error">{{ pipelineAlert }}</div>

            <!-- Table -->
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
                                    Call Time <span class="cSortBtn__chev">{{ sortGlyph('started_at') }}</span>
                                </button>
                            </th>
                            <th>
                                <button type="button" class="cSortBtn" @click="toggleSort('from')">
                                    Parties <span class="cSortBtn__chev">{{ sortGlyph('from') }}</span>
                                </button>
                            </th>
                            <th class="cCol--right">
                                <button type="button" class="cSortBtn" @click="toggleSort('duration_seconds')">
                                    Duration <span class="cSortBtn__chev">{{ sortGlyph('duration_seconds') }}</span>
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
                            <th class="cCol--pipeline">AI Pipeline</th>
                            <th class="cCol--actions">Actions</th>
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

                            <td class="cMono">{{ formatDate(row.callTime) }}</td>

                            <td>
                                <div class="cParties">
                                    <div class="cParties__line">
                                        <span class="cParties__label">From</span>
                                        <span class="cMono">{{ row.fromNumber || '—' }}</span>
                                    </div>
                                    <div class="cParties__line">
                                        <span class="cParties__label">To</span>
                                        <span class="cMono">{{ row.toNumber || '—' }}</span>
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

                            <td class="cMono">{{ formatDate(row.createdAt) }}</td>

                            <td class="cCol--pipeline" @click.stop>
                                <div class="cPipeline">
                                    <div class="cPipeline__step">
                                        <span class="cPipeline__label">Transcript</span>
                                        <span v-if="row.aiRecovery?.hasTranscript" class="cPipeline__ok">✓</span>
                                        <template v-else>
                                            <span class="cPipeline__missing">Not available</span>
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
                                        <span v-if="row.category" class="cPipeline__ok">✓ <span class="cPipeline__catName">{{ row.category }}</span></span>
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
                                <div class="cActions">
                                    <button class="cBtn cBtn--secondary cBtn--sm" type="button" @click.stop="viewRow(row)">View</button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Footer / Pagination -->
            <div class="cFooter">
                <div class="cPageSizeWrap">
                    <label class="cPageSizeLabel">Rows</label>
                    <select v-model="pageSize" class="cInput cInput--sm cInput--select">
                        <option v-for="s in PAGE_SIZE_OPTIONS" :key="s" :value="s">{{ s }}</option>
                    </select>
                </div>
                <div class="cPager">
                    <button class="cBtn cBtn--ghost cBtn--sm" :disabled="meta.currentPage <= 1 || loading" @click="page = meta.currentPage - 1; fetchCalls()">Prev</button>
                    <span class="cPagerInfo">{{ meta.currentPage }} / {{ meta.lastPage }}</span>
                    <button class="cBtn cBtn--ghost cBtn--sm" :disabled="meta.currentPage >= meta.lastPage || loading" @click="page = meta.currentPage + 1; fetchCalls()">Next</button>
                </div>
                <span class="cPagerHint">Server-side pagination</span>
            </div>
        </div>

        <!-- Mobile filter modal (Teleport) -->
        <Teleport to="body">
            <Transition name="cModal">
                <div v-if="filtersOpen && !isDesktop" class="cOverlay" @click="filtersOpen = false">
                    <div class="cModal" @click.stop>
                        <div class="cModal__header">
                            <h2 class="cModal__title">Filter Options</h2>
                            <button type="button" class="cModal__close" @click="filtersOpen = false">✕</button>
                        </div>
                        <div class="cModal__body">
                            <div class="cFilterGrid">
                                <div class="cField">
                                    <label class="cField__label">Category</label>
                                    <select v-model="draftFilterCategory" class="cInput cInput--select">
                                        <option value="">All Categories</option>
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
                            <button class="cBtn cBtn--secondary" type="button" @click="resetDraftFilters">Reset</button>
                            <button class="cBtn cBtn--primary" type="button" @click="applyFilters">Apply</button>
                        </div>
                    </div>
                </div>
            </Transition>
        </Teleport>
    </div>
</template>

<style scoped>
/* ── Layout ──────────────────────────────────────────────────────────────── */
.cPage { display: flex; flex-direction: column; gap: var(--space-4, 1rem); }

/* ── Page Header ─────────────────────────────────────────────────────────── */
.cHeader {
    display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap;
    padding: var(--space-5, 1.25rem) var(--space-6, 1.5rem);
    background: var(--surface, #fff); border: 1px solid var(--border, #e5e7eb); border-radius: 14px;
}
.cHeader__left { display: flex; align-items: center; gap: 1rem; }
.cHeader__icon {
    width: 48px; height: 48px; border-radius: 12px; flex-shrink: 0;
    background: color-mix(in srgb, var(--color-primary, #3b82f6) 15%, transparent);
    color: var(--color-primary, #3b82f6);
    display: grid; place-items: center;
}
.cHeader__icon svg { width: 22px; height: 22px; }
.cHeader__kicker { font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.06em; opacity: 0.55; margin: 0 0 2px; }
.cHeader__title { font-size: 1.35rem; font-weight: 800; margin: 0 0 2px; }
.cHeader__subtitle { font-size: 0.85rem; opacity: 0.65; margin: 0; }

.cHeader__stats { display: flex; gap: 1rem; }
.cHeader__stat {
    padding: 0.75rem 1.25rem; border-radius: 10px; text-align: center;
    background: color-mix(in srgb, var(--color-primary, #3b82f6) 8%, transparent);
}
.cHeader__statValue { font-size: 1.5rem; font-weight: 800; color: var(--color-primary, #3b82f6); }
.cHeader__statLabel { font-size: 0.72rem; opacity: 0.65; text-transform: uppercase; letter-spacing: 0.05em; }

/* ── Card ────────────────────────────────────────────────────────────────── */
.cCard {
    background: var(--surface, #fff); border: 1px solid var(--border, #e5e7eb);
    border-radius: 14px; padding: var(--space-4, 1rem); display: flex; flex-direction: column; gap: 12px;
}

/* ── Toolbar ─────────────────────────────────────────────────────────────── */
.cToolbar { display: flex; align-items: flex-end; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
.cToolbar__left { flex: 1; min-width: 180px; }
.cToolbar__right { display: flex; align-items: flex-end; gap: 8px; }

.cField { display: flex; flex-direction: column; gap: 5px; }
.cField__label { font-size: 0.75rem; font-weight: 600; opacity: 0.7; }
.cField--search { flex: 1; }

.cInput {
    height: 36px; padding: 0 10px; border: 1px solid var(--border, #e5e7eb);
    border-radius: 8px; background: var(--surface, #fff); color: inherit;
    font-size: 0.875rem; width: 100%; box-sizing: border-box;
}
.cInput--select { appearance: auto; }
.cInput--sm { height: 30px; font-size: 0.82rem; }

/* ── Filter popover ──────────────────────────────────────────────────────── */
.cFilterWrap { position: relative; }
.cFilterPanel {
    position: absolute; top: calc(100% + 6px); right: 0; z-index: 50;
    background: var(--surface, #fff); border: 1px solid var(--border, #e5e7eb);
    border-radius: 12px; padding: 16px; min-width: 320px; box-shadow: 0 8px 24px rgba(0,0,0,.12);
}
.cFilterPanel__header { font-size: 0.85rem; font-weight: 700; margin-bottom: 12px; }
.cFilterGrid { display: grid; gap: 10px; }
.cFilterActions { display: flex; gap: 8px; justify-content: flex-end; margin-top: 12px; }
.cFilterDot {
    display: inline-block; width: 6px; height: 6px; border-radius: 50%;
    background: var(--color-primary, #3b82f6); margin-left: 4px; vertical-align: middle;
}

/* ── Buttons ─────────────────────────────────────────────────────────────── */
.cBtn {
    display: inline-flex; align-items: center; gap: 6px; height: 36px; padding: 0 14px;
    border-radius: 8px; font-size: 0.85rem; font-weight: 600; border: none; cursor: pointer;
    white-space: nowrap; transition: filter 0.15s, opacity 0.15s;
}
.cBtn--sm { height: 30px; padding: 0 10px; font-size: 0.8rem; }
.cBtn--primary { background: var(--color-primary, #3b82f6); color: #fff; }
.cBtn--primary:hover:not(:disabled) { filter: brightness(1.08); }
.cBtn--secondary {
    background: var(--surface-2, #f3f4f6); color: inherit;
    border: 1px solid var(--border, #e5e7eb);
}
.cBtn--secondary:hover:not(:disabled) { background: color-mix(in srgb, var(--border) 60%, var(--surface-2)); }
.cBtn--ghost { background: transparent; color: inherit; border: 1px solid var(--border, #e5e7eb); }
.cBtn--ghost:hover:not(:disabled) { background: var(--surface-2, #f3f4f6); }
.cBtn:disabled { opacity: 0.45; cursor: not-allowed; }
.cBtn__icon { width: 15px; height: 15px; flex-shrink: 0; }

/* ── Alert ───────────────────────────────────────────────────────────────── */
.cAlert { padding: 10px 14px; border-radius: 8px; font-size: 0.9rem; }
.cAlert--error { background: color-mix(in srgb, #e53e3e 10%, transparent); border: 1px solid #e53e3e; color: #e53e3e; }

/* ── Table ───────────────────────────────────────────────────────────────── */
.cTableWrap { overflow-x: auto; }
.cTable { width: 100%; border-collapse: collapse; font-size: 0.875rem; min-width: 960px; }
.cTable thead tr { border-bottom: 2px solid var(--border, #e5e7eb); }
.cTable th {
    text-align: left; padding: 10px 12px; font-size: 0.72rem; text-transform: uppercase;
    letter-spacing: 0.05em; opacity: 0.6; font-weight: 700; white-space: nowrap;
}
.cTable td { padding: 12px; border-bottom: 1px solid var(--border, #e5e7eb); vertical-align: middle; }
.cRow { cursor: pointer; transition: background 0.1s; }
.cRow:hover { background: var(--surface-2, #f9fafb); }

/* Sortable header button */
.cSortBtn {
    background: none; border: none; cursor: pointer; font-size: 0.72rem; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.05em; opacity: 0.6; padding: 0;
    display: inline-flex; align-items: center; gap: 4px; color: inherit;
}
.cSortBtn:hover { opacity: 1; }
.cSortBtn__chev { font-size: 0.6rem; }

/* Mono text */
.cMono { font-family: ui-monospace, monospace; font-size: 0.82rem; }

/* Parties */
.cParties { display: flex; flex-direction: column; gap: 2px; }
.cParties__line { display: flex; gap: 6px; align-items: center; }
.cParties__label { font-size: 0.7rem; font-weight: 600; opacity: 0.5; min-width: 28px; }

/* Badge */
.cBadge {
    padding: 2px 9px; border-radius: 999px; font-size: 0.72rem; font-weight: 700;
    display: inline-block; white-space: nowrap;
}
.badge--active  { background: color-mix(in srgb, #10b981 14%, transparent); color: #059669; }
.badge--failed  { background: color-mix(in srgb, #ef4444 14%, transparent); color: #dc2626; }
.badge--processing { background: color-mix(in srgb, var(--color-primary) 14%, transparent); color: var(--color-primary-hover, var(--color-primary)); }

/* Category */
.cCategory__sub { font-size: 0.78rem; opacity: 0.6; margin-top: 2px; }

/* Column helpers */
.cCol--right { text-align: right; }
.cCol--pipeline { min-width: 220px; }
.cCol--actions { text-align: right; }

/* AI Pipeline column */
.cPipeline { display: flex; flex-direction: column; gap: 5px; }
.cPipeline__step { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
.cPipeline__label { font-size: 0.7rem; font-weight: 600; opacity: 0.55; min-width: 64px; }
.cPipeline__ok { color: #10b981; font-weight: 700; font-size: 0.82rem; }
.cPipeline__catName { font-size: 0.72rem; opacity: 0.7; margin-left: 2px; }
.cPipeline__badge {
    font-size: 0.7rem; font-weight: 600; padding: 1px 7px; border-radius: 999px;
    background: color-mix(in srgb, #f59e0b 16%, transparent); color: #b45309;
}
.cPipeline__missing { font-size: 0.72rem; opacity: 0.5; }
.cPipeline__dash { opacity: 0.3; font-size: 0.82rem; }
.cPipeline__btn {
    height: 22px; padding: 0 8px; border-radius: 5px; cursor: pointer; font-size: 0.7rem; font-weight: 600;
    background: var(--color-primary, #3b82f6); color: #fff; border: none; white-space: nowrap;
}
.cPipeline__btn:hover:not(:disabled) { filter: brightness(1.08); }
.cPipeline__btn:disabled { opacity: 0.5; cursor: not-allowed; }

/* Actions */
.cActions { display: flex; gap: 6px; justify-content: flex-end; }

/* Skeleton */
.cSkeleton { height: 20px; border-radius: 6px; background: color-mix(in srgb, var(--color-text, #111) 8%, transparent); animation: pulse 1.2s ease-in-out infinite; }
@keyframes pulse { 0%,100% { opacity: 1; } 50% { opacity: 0.45; } }

/* Empty */
.cEmpty { padding: 3rem; text-align: center; }
.cEmpty__title { font-size: 1rem; font-weight: 600; margin-bottom: 4px; }
.cEmpty__desc { font-size: 0.875rem; opacity: 0.55; }

/* ── Footer / Pagination ─────────────────────────────────────────────────── */
.cFooter { display: flex; align-items: center; gap: 16px; padding-top: 4px; flex-wrap: wrap; }
.cPageSizeWrap { display: flex; align-items: center; gap: 6px; }
.cPageSizeLabel { font-size: 0.8rem; opacity: 0.65; }
.cPager { display: flex; align-items: center; gap: 8px; margin-left: auto; }
.cPagerInfo { font-size: 0.85rem; opacity: 0.65; }
.cPagerHint { font-size: 0.75rem; opacity: 0.4; }

/* ── Mobile modal ────────────────────────────────────────────────────────── */
.cOverlay {
    position: fixed; inset: 0; background: rgba(0,0,0,.5); display: grid; place-items: center; z-index: 100;
}
.cModal {
    background: var(--surface, #fff); border: 1px solid var(--border, #e5e7eb); border-radius: 14px;
    width: min(480px, 92vw); display: grid; gap: 0; overflow: hidden;
}
.cModal__header { display: flex; align-items: center; justify-content: space-between; padding: 16px 20px; border-bottom: 1px solid var(--border); }
.cModal__title { margin: 0; font-size: 1rem; font-weight: 700; }
.cModal__close { background: none; border: none; cursor: pointer; font-size: 1rem; opacity: 0.6; }
.cModal__body { padding: 20px; }
.cModal__footer { display: flex; gap: 10px; justify-content: flex-end; padding: 16px 20px; border-top: 1px solid var(--border); }

.cModal-enter-active, .cModal-leave-active { transition: opacity 0.2s; }
.cModal-enter-from, .cModal-leave-to { opacity: 0; }

/* ── Responsive ──────────────────────────────────────────────────────────── */
@media (max-width: 1024px) {
    .cToolbar { flex-direction: column; align-items: stretch; }
    .cToolbar__right { flex-wrap: wrap; }
    .cHeader { flex-direction: column; align-items: flex-start; }
}
</style>
