<template>
    <div class="admin-container admin-page">
        <header class="admin-page__header admin-callsHeader">
            <div class="admin-callsHeader__left">
                <div class="admin-callsHeader__icon">
                    <svg
                        viewBox="0 0 24 24"
                        fill="none"
                        xmlns="http://www.w3.org/2000/svg"
                    >
                        <path
                            d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"
                            fill="currentColor"
                        />
                    </svg>
                </div>
                <div>
                    <p class="admin-page__kicker">Operations</p>
                    <h1 class="admin-page__title">Calls</h1>
                    <p class="admin-page__subtitle">
                        Manage and review all incoming and outgoing calls in
                        real-time.
                    </p>
                </div>
            </div>

            <div class="admin-callsHeader__stats">
                <div class="admin-callsHeader__stat">
                    <div class="admin-callsHeader__statValue">
                        {{ formatNumber(meta.total) }}
                    </div>
                    <div class="admin-callsHeader__statLabel">Total Calls</div>
                </div>
            </div>
        </header>

        <section class="admin-card admin-card--glass">
            <div class="admin-callsToolbar">
                <div class="admin-callsToolbar__left">
                    <div class="admin-field admin-callsToolbar__search">
                        <label class="admin-field__label" for="calls-search">
                            Search
                        </label>
                        <input
                            id="calls-search"
                            v-model="search"
                            class="admin-input"
                            type="search"
                            autocomplete="off"
                            placeholder="Call ID, company, provider, status…"
                        />
                    </div>
                </div>

                <div class="admin-callsToolbar__right">
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
                            v-if="filtersOpen"
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

                                <div class="admin-field">
                                    <label
                                        class="admin-field__label"
                                        for="filter-category"
                                    >
                                        Category
                                    </label>
                                    <select
                                        id="filter-category"
                                        v-model="draftFilterCategory"
                                        class="admin-input admin-input--select"
                                    >
                                        <option value="">All Categories</option>
                                        <option
                                            v-for="cat in categories"
                                            :key="cat.id"
                                            :value="cat.id"
                                        >
                                            {{ cat.name }}
                                        </option>
                                    </select>
                                </div>

                                <div class="admin-field">
                                    <label
                                        class="admin-field__label"
                                        for="filter-start-date"
                                    >
                                        Start date
                                    </label>
                                    <VueDatePicker
                                        v-model="draftFilterStartDate"
                                        :enable-time-picker="false"
                                        placeholder="Select start date"
                                        format="yyyy-MM-dd"
                                        auto-apply
                                        :clearable="true"
                                    />
                                </div>

                                <div class="admin-field">
                                    <label
                                        class="admin-field__label"
                                        for="filter-end-date"
                                    >
                                        End date
                                    </label>
                                    <VueDatePicker
                                        v-model="draftFilterEndDate"
                                        :enable-time-picker="false"
                                        placeholder="Select end date"
                                        format="yyyy-MM-dd"
                                        auto-apply
                                        :clearable="true"
                                        :min-date="draftFilterStartDate"
                                    />
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
                :rows="rows"
                row-key="id"
                :loading="loading"
                :skeleton-rows="10"
                :virtualized="isDesktop"
                height="lg"
                :row-height="48"
                :overscan="8"
                empty-title="No calls"
                empty-description="No calls match the current filters."
            >
                <template #header-callId>
                    <button
                        type="button"
                        class="admin-callsSortBtn"
                        @click="toggleSort('call_uid')"
                    >
                        Call ID
                        <span class="admin-callsSortBtn__chev">{{
                            sortGlyph("call_uid")
                        }}</span>
                    </button>
                </template>

                <template #header-company>
                    <button
                        type="button"
                        class="admin-callsSortBtn"
                        @click="toggleSort('company')"
                    >
                        Company
                        <span class="admin-callsSortBtn__chev">{{
                            sortGlyph("company")
                        }}</span>
                    </button>
                </template>

                <template #header-durationSeconds>
                    <button
                        type="button"
                        class="admin-callsSortBtn"
                        @click="toggleSort('duration_seconds')"
                    >
                        Duration
                        <span class="admin-callsSortBtn__chev">{{
                            sortGlyph("duration_seconds")
                        }}</span>
                    </button>
                </template>

                <template #header-status>
                    <button
                        type="button"
                        class="admin-callsSortBtn"
                        @click="toggleSort('status')"
                    >
                        Status
                        <span class="admin-callsSortBtn__chev">{{
                            sortGlyph("status")
                        }}</span>
                    </button>
                </template>

                <template #header-category> Category </template>

                <template #header-createdAt>
                    <button
                        type="button"
                        class="admin-callsSortBtn"
                        @click="toggleSort('created_at')"
                    >
                        Created
                        <span class="admin-callsSortBtn__chev">{{
                            sortGlyph("created_at")
                        }}</span>
                    </button>
                </template>

                <template #cell-durationSeconds="{ value }">
                    <span class="admin-callsMono">{{
                        formatDuration(value)
                    }}</span>
                </template>

                <template #cell-status="{ value }">
                    <BaseBadge :variant="badgeVariant(value)">
                        {{ String(value || "").toUpperCase() }}
                    </BaseBadge>
                </template>

                <template #cell-category="{ row }">
                    <div class="admin-callsCategory">
                        <div class="admin-callsCategory__main">
                            {{ row.category || "—" }}
                        </div>
                        <div
                            v-if="row.subCategory"
                            class="admin-callsCategory__sub"
                        >
                            {{ row.subCategory }}
                        </div>
                    </div>
                </template>

                <template #cell-createdAt="{ value }">
                    <span class="admin-callsMono">{{ formatDate(value) }}</span>
                </template>

                <template #cell-actions="{ row }">
                    <BaseButton
                        variant="ghost"
                        size="sm"
                        @click.stop="viewRow(row)"
                    >
                        View
                    </BaseButton>
                </template>
            </BaseTable>

            <div class="admin-callsFooter">
                <BasePagination
                    v-model:page="page"
                    v-model:pageSize="pageSize"
                    :total="meta.total"
                    :disabled="loading"
                    :page-size-options="[10, 25, 50, 100, 200]"
                    hint="Server-side pagination"
                    @change="fetchCalls"
                />
            </div>
        </section>
    </div>
</template>

<script setup>
import { onMounted, onBeforeUnmount, ref, watch } from "vue";
import { useRouter } from "vue-router";
import { VueDatePicker } from "@vuepic/vue-datepicker";
import "@vuepic/vue-datepicker/dist/main.css";

import adminApi from "../../router/admin/api";
import {
    BaseBadge,
    BaseButton,
    BasePagination,
    BaseTable,
} from "../../components/admin/base";

const loading = ref(true);
const error = ref("");

const router = useRouter();

const search = ref("");
const page = ref(1);
const pageSize = ref(25);

const sortBy = ref("created_at");
const sortDirection = ref("desc");

// Category filters
const filterCompany = ref("");
const filterCategory = ref("");
const filterStartDate = ref("");
const filterEndDate = ref("");

const draftFilterCompany = ref("");
const draftFilterCategory = ref("");
const draftFilterStartDate = ref("");
const draftFilterEndDate = ref("");

const filtersOpen = ref(false);
const filterWrap = ref(null);
const companies = ref([]);
const categories = ref([]);

const rows = ref([]);
const meta = ref({
    currentPage: 1,
    lastPage: 1,
    perPage: 25,
    total: 0,
});

const isDesktop = ref(true);

const columns = ref([
    { key: "callId", label: "Call ID" },
    { key: "company", label: "Company" },
    {
        key: "durationSeconds",
        label: "Duration",
        cellClass: "admin-callsCol--right",
    },
    { key: "status", label: "Status" },
    { key: "category", label: "Category" },
    { key: "createdAt", label: "Created" },
    { key: "actions", label: "Actions", cellClass: "admin-callsCol--right" },
]);

function normalizeRow(item) {
    return {
        id: item.id,
        callId: item.callId,
        company: item.company,
        provider: item.provider,
        durationSeconds: item.durationSeconds,
        status: item.status,
        createdAt: item.createdAt,
        category: item.category,
        categoryId: item.categoryId,
        subCategory: item.subCategory,
        categorySource: item.categorySource,
        categoryConfidence: item.categoryConfidence,
    };
}

function formatDuration(seconds) {
    const s = Number(seconds);
    if (!Number.isFinite(s) || s < 0) return "—";
    if (s === 0) return "0 seconds";

    const totalMinutes = Math.floor(s / 60);
    const secs = Math.floor(s % 60);

    const parts = [];
    if (totalMinutes > 0) {
        parts.push(
            `${totalMinutes} ${totalMinutes === 1 ? "minute" : "minutes"}`,
        );
    }
    if (secs > 0) {
        parts.push(`${secs} ${secs === 1 ? "second" : "seconds"}`);
    }

    return parts.join(" ");
}

function formatDate(iso) {
    const t = new Date(iso);
    const ms = t.getTime();
    if (!Number.isFinite(ms)) return "—";
    return t.toLocaleString();
}

function formatNumber(num) {
    if (!Number.isFinite(num)) return "—";
    return new Intl.NumberFormat("en-US").format(num);
}

function badgeVariant(status) {
    const s = String(status || "").toLowerCase();
    if (s === "completed") return "active";
    if (s === "failed") return "failed";
    return "processing";
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
    page.value = 1;
    fetchCalls();
}

async function fetchCalls() {
    loading.value = true;
    error.value = "";

    try {
        const params = {
            page: page.value,
            per_page: pageSize.value,
            search: search.value || undefined,
            sort: sortBy.value,
            direction: sortDirection.value,
        };

        // Add filters
        if (filterCompany.value) {
            params.company_id = filterCompany.value;
        }

        if (filterCategory.value) {
            params.category_id = filterCategory.value;
        }

        if (filterStartDate.value) {
            params.start_date = filterStartDate.value;
        }

        if (filterEndDate.value) {
            params.end_date = filterEndDate.value;
        }

        const res = await adminApi.get("/calls", { params });

        const payload = res?.data;
        rows.value = Array.isArray(payload?.data)
            ? payload.data.map(normalizeRow)
            : [];
        meta.value = payload?.meta ?? meta.value;
    } catch (e) {
        rows.value = [];
        error.value = "Failed to load calls.";
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

async function loadCategories() {
    try {
        const res = await adminApi.get("/categories/enabled");
        categories.value = res?.data?.data || [];
    } catch (e) {
        console.error("Failed to load categories", e);
    }
}

function clearFilters() {
    filterCompany.value = "";
    filterCategory.value = "";
    filterStartDate.value = "";
    filterEndDate.value = "";
    page.value = 1;
    fetchCalls();
}

function resetDraftFilters() {
    draftFilterCompany.value = "";
    draftFilterCategory.value = "";
    draftFilterStartDate.value = "";
    draftFilterEndDate.value = "";
}

function syncDraftFilters() {
    draftFilterCompany.value = filterCompany.value;
    draftFilterCategory.value = filterCategory.value;
    draftFilterStartDate.value = filterStartDate.value;
    draftFilterEndDate.value = filterEndDate.value;
}

function applyFilters() {
    filterCompany.value = draftFilterCompany.value;
    filterCategory.value = draftFilterCategory.value;

    // Format dates from Date objects to YYYY-MM-DD strings
    filterStartDate.value = draftFilterStartDate.value
        ? draftFilterStartDate.value instanceof Date
            ? draftFilterStartDate.value.toISOString().split("T")[0]
            : draftFilterStartDate.value
        : "";
    filterEndDate.value = draftFilterEndDate.value
        ? draftFilterEndDate.value instanceof Date
            ? draftFilterEndDate.value.toISOString().split("T")[0]
            : draftFilterEndDate.value
        : "";
    filtersOpen.value = false;
    page.value = 1;
    fetchCalls();
}

function toggleFilters() {
    filtersOpen.value = !filtersOpen.value;
    if (filtersOpen.value) {
        syncDraftFilters();
    }
}

function refresh() {
    fetchCalls();
}

function viewRow(row) {
    const callId = row?.callId;
    if (!callId) return;
    router.push({ name: "admin.calls.detail", params: { callId } });
}

let searchTimer = 0;

function updateViewport() {
    isDesktop.value = window.innerWidth >= 1024;
}
watch(
    () => search.value,
    () => {
        if (searchTimer) window.clearTimeout(searchTimer);
        searchTimer = window.setTimeout(() => {
            page.value = 1;
            fetchCalls();
        }, 250);
    },
);

watch(
    () => pageSize.value,
    () => {
        page.value = 1;
        fetchCalls();
    },
);

// Watch filter changes
function onDocumentClick(event) {
    if (!filtersOpen.value) return;
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
    loadCategories();
    fetchCalls();
});

onBeforeUnmount(() => {
    window.removeEventListener("resize", updateViewport);
    document.removeEventListener("click", onDocumentClick);
});
</script>
