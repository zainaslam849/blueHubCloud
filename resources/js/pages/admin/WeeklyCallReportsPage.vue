<template>
    <div class="admin-container admin-page">
        <header class="admin-page__header admin-reportsHeader">
            <div class="admin-reportsHeader__left">
                <div class="admin-reportsHeader__icon">
                    <svg
                        viewBox="0 0 24 24"
                        fill="none"
                        xmlns="http://www.w3.org/2000/svg"
                    >
                        <path
                            d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"
                            stroke="currentColor"
                            stroke-width="2"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        />
                        <rect
                            x="9"
                            y="3"
                            width="6"
                            height="4"
                            rx="1"
                            stroke="currentColor"
                            stroke-width="2"
                        />
                        <path
                            d="M9 12h6M9 16h6"
                            stroke="currentColor"
                            stroke-width="2"
                            stroke-linecap="round"
                        />
                    </svg>
                </div>
                <div>
                    <p class="admin-page__kicker">Reports</p>
                    <h1 class="admin-page__title">Weekly Call Reports</h1>
                    <p class="admin-page__subtitle">
                        Track and analyze weekly call performance metrics and
                        trends.
                    </p>
                </div>
            </div>

            <div class="admin-reportsHeader__stats">
                <div class="admin-reportsHeader__stat">
                    <div class="admin-reportsHeader__statValue">
                        {{ formatNumber(rows.length) }}
                    </div>
                    <div class="admin-reportsHeader__statLabel">
                        Total Reports
                    </div>
                </div>
            </div>
        </header>

        <section class="admin-card admin-card--glass">
            <div class="admin-reportsToolbar">
                <div class="admin-reportsToolbar__left">
                    <div class="admin-field admin-reportsToolbar__search">
                        <label class="admin-field__label" for="reports-search">
                            Search
                        </label>
                        <input
                            id="reports-search"
                            v-model="search"
                            class="admin-input"
                            type="search"
                            autocomplete="off"
                            placeholder="Company name, week range…"
                        />
                    </div>
                </div>

                <div class="admin-reportsToolbar__right">
                    <div ref="filterWrap" class="admin-filterPopover">
                        <BaseButton
                            variant="secondary"
                            size="sm"
                            class="admin-filterTrigger"
                            @click="toggleFilters"
                        >
                            <span
                                class="admin-filterTrigger__icon"
                                aria-hidden="true"
                            >
                                <svg
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    xmlns="http://www.w3.org/2000/svg"
                                >
                                    <path
                                        d="M4 5H20L14 12V19L10 21V12L4 5Z"
                                        stroke="currentColor"
                                        stroke-width="1.8"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                    />
                                </svg>
                            </span>
                            Filter
                        </BaseButton>

                        <div
                            v-if="filtersOpen && isDesktop"
                            class="admin-filterPanel"
                            role="dialog"
                            aria-label="Filter options"
                        >
                            <div class="admin-filterPanel__header">
                                Filter Options
                            </div>

                            <div class="admin-filterGrid">
                                <div class="admin-field">
                                    <label
                                        class="admin-field__label"
                                        for="filter-company"
                                    >
                                        Company
                                    </label>
                                    <select
                                        id="filter-company"
                                        v-model="draftFilterCompany"
                                        class="admin-input admin-input--select"
                                    >
                                        <option value="">All Companies</option>
                                        <option
                                            v-for="company in companies"
                                            :key="company.id"
                                            :value="company.id"
                                        >
                                            {{ company.name }}
                                        </option>
                                    </select>
                                </div>
                            </div>

                            <div class="admin-filterActions">
                                <BaseButton
                                    variant="ghost"
                                    size="sm"
                                    @click="resetDraftFilters"
                                >
                                    Reset
                                </BaseButton>
                                <BaseButton
                                    variant="primary"
                                    size="sm"
                                    @click="applyFilters"
                                >
                                    Apply
                                </BaseButton>
                            </div>
                        </div>
                    </div>
                    <BaseButton
                        variant="secondary"
                        size="sm"
                        :loading="loading"
                        @click="refresh"
                    >
                        <span
                            class="admin-filterTrigger__icon"
                            aria-hidden="true"
                        >
                            <svg
                                viewBox="0 0 24 24"
                                fill="none"
                                xmlns="http://www.w3.org/2000/svg"
                            >
                                <path
                                    d="M20 12a8 8 0 1 1-2.34-5.66"
                                    stroke="currentColor"
                                    stroke-width="1.8"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                />
                                <path
                                    d="M20 4v6h-6"
                                    stroke="currentColor"
                                    stroke-width="1.8"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                />
                            </svg>
                        </span>
                        Refresh
                    </BaseButton>
                </div>
            </div>

            <div v-if="error" class="admin-alert admin-alert--error">
                {{ error }}
            </div>

            <BaseTable
                :columns="columns"
                :rows="filteredRows"
                row-key="id"
                :loading="loading"
                :skeleton-rows="10"
                :virtualized="isDesktop"
                height="lg"
                :row-height="48"
                :overscan="8"
                empty-title="No reports"
                empty-description="No weekly call reports match the current filters."
            >
                <template #header-company>
                    <button
                        type="button"
                        class="admin-reportsSortBtn"
                        @click="toggleSort('company')"
                    >
                        Company
                        <span class="admin-reportsSortBtn__chev">{{
                            sortGlyph("company")
                        }}</span>
                    </button>
                </template>

                <template #header-weekRange>
                    <button
                        type="button"
                        class="admin-reportsSortBtn"
                        @click="toggleSort('weekStart')"
                    >
                        Week
                        <span class="admin-reportsSortBtn__chev">{{
                            sortGlyph("weekStart")
                        }}</span>
                    </button>
                </template>

                <template #header-totalCalls>
                    <button
                        type="button"
                        class="admin-reportsSortBtn"
                        @click="toggleSort('totalCalls')"
                    >
                        Total Calls
                        <span class="admin-reportsSortBtn__chev">{{
                            sortGlyph("totalCalls")
                        }}</span>
                    </button>
                </template>

                <template #header-answeredCalls>
                    <button
                        type="button"
                        class="admin-reportsSortBtn"
                        @click="toggleSort('answeredCalls')"
                    >
                        Answered
                        <span class="admin-reportsSortBtn__chev">{{
                            sortGlyph("answeredCalls")
                        }}</span>
                    </button>
                </template>

                <template #cell-company="{ value }">
                    {{ value || "—" }}
                </template>

                <template #cell-weekRange="{ row }">
                    <span class="admin-mono">
                        {{ formatWeekRange(row) }}
                    </span>
                </template>

                <template #cell-totalCalls="{ value, row }">
                    <span class="admin-mono">{{ formatNumber(value) }}</span>
                </template>

                <template #cell-answeredCalls="{ value }">
                    <span class="admin-mono">{{ formatNumber(value) }}</span>
                </template>

                <template #cell-answerRate="{ row }">
                    <BaseBadge :variant="answerRateVariant(row)">
                        {{ calculateAnswerRate(row) }}%
                    </BaseBadge>
                </template>

                <template #cell-actions="{ row }">
                    <template v-if="row?.id">
                        <BaseButton
                            variant="ghost"
                            size="sm"
                            :to="{
                                name: 'admin.weeklyReports.detail',
                                params: { id: row.id },
                            }"
                        >
                            View
                        </BaseButton>
                    </template>
                    <template v-else>
                        <BaseButton variant="ghost" size="sm" disabled>
                            View
                        </BaseButton>
                    </template>
                </template>
            </BaseTable>
        </section>

        <Teleport to="body">
            <Transition name="admin-modal">
                <div
                    v-if="filtersOpen && !isDesktop"
                    class="admin-modalOverlay"
                    @click="filtersOpen = false"
                >
                    <div class="admin-modal" @click.stop>
                        <div class="admin-modal__header">
                            <h2 class="admin-modal__title">Filter Options</h2>
                            <button
                                type="button"
                                class="admin-modal__close"
                                @click="filtersOpen = false"
                            >
                                ✕
                            </button>
                        </div>

                        <div class="admin-modal__body">
                            <div class="admin-filterGrid">
                                <div class="admin-field">
                                    <label
                                        class="admin-field__label"
                                        for="filter-company"
                                    >
                                        Company
                                    </label>
                                    <select
                                        id="filter-company"
                                        v-model="draftFilterCompany"
                                        class="admin-input admin-input--select"
                                    >
                                        <option value="">All Companies</option>
                                        <option
                                            v-for="company in companies"
                                            :key="company.id"
                                            :value="company.id"
                                        >
                                            {{ company.name }}
                                        </option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="admin-modal__footer">
                            <BaseButton
                                variant="secondary"
                                @click="resetDraftFilters"
                            >
                                Reset
                            </BaseButton>
                            <BaseButton variant="primary" @click="applyFilters">
                                Apply
                            </BaseButton>
                        </div>
                    </div>
                </div>
            </Transition>
        </Teleport>
    </div>
</template>

<script setup>
import { computed, onMounted, onBeforeUnmount, ref, watch } from "vue";

import adminApi from "../../router/admin/api";
import { BaseBadge, BaseButton, BaseTable } from "../../components/admin/base";

const loading = ref(true);
const error = ref("");
const rows = ref([]);

const search = ref("");
const sortBy = ref("weekStart");
const sortDirection = ref("desc");

const filterCompany = ref("");
const draftFilterCompany = ref("");

const filtersOpen = ref(false);
const filterWrap = ref(null);
const companies = ref([]);
const isDesktop = ref(true);

const columns = ref([
    { key: "company", label: "Company" },
    { key: "weekRange", label: "Week" },
    { key: "totalCalls", label: "Total Calls", cellClass: "admin-table__num" },
    { key: "answeredCalls", label: "Answered", cellClass: "admin-table__num" },
    { key: "answerRate", label: "Answer Rate", cellClass: "admin-table__num" },
    { key: "actions", label: "Actions", cellClass: "admin-table__actions" },
]);

function normalizeRow(item) {
    return {
        id: item.id,
        company: item.company?.name || item.company_name || "—",
        weekStart: item.week_start_date,
        weekEnd: item.week_end_date,
        totalCalls: item.total_calls ?? 0,
        answeredCalls: item.answered_calls ?? 0,
        missedCalls: item.missed_calls ?? 0,
    };
}

function formatWeekRange(row) {
    if (!row.weekStart) return "—";
    const start = new Date(row.weekStart);
    const end = row.weekEnd ? new Date(row.weekEnd) : null;

    const startStr = start.toLocaleDateString(undefined, {
        month: "short",
        day: "numeric",
    });

    if (!end) return startStr;

    const endStr = end.toLocaleDateString(undefined, {
        month: "short",
        day: "numeric",
        year: "numeric",
    });

    return `${startStr} – ${endStr}`;
}

function formatNumber(value) {
    const num = Number(value);
    if (!Number.isFinite(num)) return "—";
    return num.toLocaleString();
}

function calculateAnswerRate(row) {
    const total = row.totalCalls ?? 0;
    const answered = row.answeredCalls ?? 0;
    if (total === 0) return 0;
    return Math.round((answered / total) * 100);
}

function answerRateVariant(row) {
    const rate = calculateAnswerRate(row);
    if (rate >= 80) return "active";
    if (rate >= 60) return "processing";
    return "failed";
}

function sortGlyph(key) {
    if (sortBy.value !== key) return "";
    return sortDirection.value === "asc" ? "▲" : "▼";
}

function toggleSort(key) {
    if (sortBy.value === key) {
        sortDirection.value = sortDirection.value === "asc" ? "desc" : "asc";
    } else {
        sortBy.value = key;
        sortDirection.value = "asc";
    }
}

const filteredRows = computed(() => {
    let filtered = [...rows.value];

    // Apply search filter
    if (search.value) {
        const searchLower = search.value.toLowerCase();
        filtered = filtered.filter((row) => {
            const company = String(row.company || "").toLowerCase();
            const weekRange = formatWeekRange(row).toLowerCase();
            return (
                company.includes(searchLower) || weekRange.includes(searchLower)
            );
        });
    }

    // Apply company filter
    if (filterCompany.value) {
        filtered = filtered.filter((row) => {
            return String(row.company || "")
                .toLowerCase()
                .includes(String(filterCompany.value).toLowerCase());
        });
    }

    // Apply sorting
    filtered.sort((a, b) => {
        let aVal, bVal;

        if (sortBy.value === "company") {
            aVal = String(a.company || "").toLowerCase();
            bVal = String(b.company || "").toLowerCase();
        } else if (sortBy.value === "weekStart") {
            aVal = new Date(a.weekStart || 0).getTime();
            bVal = new Date(b.weekStart || 0).getTime();
        } else if (sortBy.value === "totalCalls") {
            aVal = a.totalCalls ?? 0;
            bVal = b.totalCalls ?? 0;
        } else if (sortBy.value === "answeredCalls") {
            aVal = a.answeredCalls ?? 0;
            bVal = b.answeredCalls ?? 0;
        } else {
            return 0;
        }

        if (aVal < bVal) return sortDirection.value === "asc" ? -1 : 1;
        if (aVal > bVal) return sortDirection.value === "asc" ? 1 : -1;
        return 0;
    });

    return filtered;
});

async function fetchReports() {
    loading.value = true;
    error.value = "";

    try {
        // First get user info to get company_id
        const meRes = await adminApi.get("/me");
        const companyId =
            meRes?.data?.company_id ||
            meRes?.data?.company?.id ||
            meRes?.data?.companyId ||
            null;

        const params = companyId ? { company_id: companyId } : undefined;
        const res = await adminApi.get("/weekly-call-reports", { params });

        const data = res?.data?.data;
        rows.value = Array.isArray(data) ? data.map(normalizeRow) : [];
    } catch (e) {
        rows.value = [];
        error.value = "Failed to load weekly reports.";
    } finally {
        loading.value = false;
    }
}

async function loadCompanies() {
    try {
        const res = await adminApi.get("/companies");
        companies.value = res?.data?.data || [];
    } catch (e) {
        console.error("Failed to load companies", e);
    }
}

function resetDraftFilters() {
    draftFilterCompany.value = "";
}

function syncDraftFilters() {
    draftFilterCompany.value = filterCompany.value;
}

function applyFilters() {
    filterCompany.value = draftFilterCompany.value;
    filtersOpen.value = false;
}

function toggleFilters() {
    filtersOpen.value = !filtersOpen.value;
    if (filtersOpen.value) {
        syncDraftFilters();
    }
}

function refresh() {
    fetchReports();
}

function updateViewport() {
    isDesktop.value = window.innerWidth >= 1024;
}

let searchTimer = 0;

watch(
    () => search.value,
    () => {
        if (searchTimer) window.clearTimeout(searchTimer);
        searchTimer = window.setTimeout(() => {
            // Search is client-side, no need to fetch
        }, 250);
    },
);

function onDocumentClick(event) {
    if (!filtersOpen.value || !isDesktop.value) return;
    const target = event.target;
    if (!filterWrap.value || !(target instanceof Node)) return;
    if (filterWrap.value.contains(target)) return;
    filtersOpen.value = false;
}

onMounted(() => {
    updateViewport();
    window.addEventListener("resize", updateViewport);
    document.addEventListener("click", onDocumentClick);
    loadCompanies();
    fetchReports();
});

onBeforeUnmount(() => {
    window.removeEventListener("resize", updateViewport);
    document.removeEventListener("click", onDocumentClick);
});
</script>
