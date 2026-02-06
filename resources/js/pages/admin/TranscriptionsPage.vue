<template>
    <div class="admin-container admin-page">
        <header class="admin-page__header admin-transcriptionsHeader">
            <div class="admin-transcriptionsHeader__left">
                <div class="admin-transcriptionsHeader__icon">
                    <svg
                        viewBox="0 0 24 24"
                        fill="none"
                        xmlns="http://www.w3.org/2000/svg"
                    >
                        <path
                            d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8z"
                            fill="currentColor"
                        />
                        <circle cx="12" cy="12" r="3" fill="currentColor" />
                    </svg>
                </div>
                <div>
                    <p class="admin-page__kicker">Insights</p>
                    <h1 class="admin-page__title">Transcriptions</h1>
                    <p class="admin-page__subtitle">
                        View and search call transcriptions with full-text
                        search capabilities.
                    </p>
                </div>
            </div>

            <div class="admin-transcriptionsHeader__stats">
                <div class="admin-transcriptionsHeader__stat">
                    <div class="admin-transcriptionsHeader__statValue">
                        {{ formatNumber(meta.total) }}
                    </div>
                    <div class="admin-transcriptionsHeader__statLabel">
                        Total Records
                    </div>
                </div>
            </div>
        </header>

        <section class="admin-card admin-card--glass">
            <div class="admin-transcriptionsToolbar">
                <div class="admin-transcriptionsToolbar__left">
                    <div
                        class="admin-field admin-transcriptionsToolbar__search"
                    >
                        <label
                            class="admin-field__label"
                            for="transcriptions-search"
                        >
                            Search
                        </label>
                        <input
                            id="transcriptions-search"
                            v-model="search"
                            class="admin-input"
                            type="search"
                            autocomplete="off"
                            placeholder="Call ID, company, transcription text…"
                        />
                    </div>
                </div>

                <div class="admin-transcriptionsToolbar__right">
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
                empty-title="No transcriptions"
                empty-description="No transcriptions match the current filters."
            >
                <template #header-callId>
                    <button
                        type="button"
                        class="admin-transcriptionsSortBtn"
                        @click="toggleSort('pbx_unique_id')"
                    >
                        Call ID
                        <span class="admin-transcriptionsSortBtn__chev">{{
                            sortGlyph("pbx_unique_id")
                        }}</span>
                    </button>
                </template>

                <template #header-company>
                    <button
                        type="button"
                        class="admin-transcriptionsSortBtn"
                        @click="toggleSort('company')"
                    >
                        Company
                        <span class="admin-transcriptionsSortBtn__chev">{{
                            sortGlyph("company")
                        }}</span>
                    </button>
                </template>

                <template #header-durationSeconds>
                    <button
                        type="button"
                        class="admin-transcriptionsSortBtn"
                        @click="toggleSort('duration_seconds')"
                    >
                        Duration
                        <span class="admin-transcriptionsSortBtn__chev">{{
                            sortGlyph("duration_seconds")
                        }}</span>
                    </button>
                </template>

                <template #header-createdAt>
                    <button
                        type="button"
                        class="admin-transcriptionsSortBtn"
                        @click="toggleSort('created_at')"
                    >
                        Created
                        <span class="admin-transcriptionsSortBtn__chev">{{
                            sortGlyph("created_at")
                        }}</span>
                    </button>
                </template>

                <template #cell-durationSeconds="{ value }">
                    <span class="admin-transcriptionMono">{{
                        formatDuration(value)
                    }}</span>
                </template>

                <template #cell-createdAt="{ value }">
                    <span class="admin-transcriptionMono">{{
                        formatDate(value)
                    }}</span>
                </template>

                <template #cell-actions="{ row }">
                    <BaseButton
                        variant="ghost"
                        size="sm"
                        :to="{
                            name: 'admin.transcriptions.detail',
                            params: { id: row?.id },
                        }"
                    >
                        View
                    </BaseButton>
                </template>
            </BaseTable>

            <div class="admin-transcriptionsFooter">
                <BasePagination
                    v-model:page="page"
                    v-model:pageSize="pageSize"
                    :total="meta.total"
                    :disabled="loading"
                    :page-size-options="[10, 25, 50, 100, 200]"
                    hint="Server-side pagination"
                    @change="fetchTranscriptions"
                />
            </div>
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
import { onMounted, onBeforeUnmount, ref, watch } from "vue";
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

const search = ref("");
const page = ref(1);
const pageSize = ref(25);

const sortBy = ref("created_at");
const sortDirection = ref("desc");

const filterCompany = ref("");
const filterStartDate = ref("");
const filterEndDate = ref("");

const draftFilterCompany = ref("");
const draftFilterStartDate = ref("");
const draftFilterEndDate = ref("");

const filtersOpen = ref(false);
const filterWrap = ref(null);
const companies = ref([]);

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
    { key: "provider", label: "Provider" },
    {
        key: "durationSeconds",
        label: "Duration",
        cellClass: "admin-transcriptionsCol--right",
    },
    { key: "createdAt", label: "Created" },
    {
        key: "actions",
        label: "Actions",
        cellClass: "admin-transcriptionsCol--right",
    },
]);

function normalizeRow(item) {
    return {
        id: item.id,
        callId: item.callId,
        company: item.company,
        provider: item.provider,
        durationSeconds: item.durationSeconds,
        createdAt: item.createdAt,
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
    fetchTranscriptions();
}

async function fetchTranscriptions() {
    loading.value = true;
    error.value = "";

    try {
        const params = {
            page: page.value,
            per_page: pageSize.value,
            sort: sortBy.value,
            direction: sortDirection.value,
            search: search.value || undefined,
        };

        if (filterCompany.value) {
            params.company_id = filterCompany.value;
        }

        if (filterStartDate.value) {
            params.start_date = filterStartDate.value;
        }

        if (filterEndDate.value) {
            params.end_date = filterEndDate.value;
        }

        const res = await adminApi.get("/transcriptions", { params });

        const payload = res?.data;
        rows.value = Array.isArray(payload?.data)
            ? payload.data.map(normalizeRow)
            : [];
        meta.value = payload?.meta ?? meta.value;
    } catch (e) {
        rows.value = [];
        error.value = "Failed to load transcriptions.";
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
    draftFilterStartDate.value = "";
    draftFilterEndDate.value = "";
}

function syncDraftFilters() {
    draftFilterCompany.value = filterCompany.value;
    draftFilterStartDate.value = filterStartDate.value;
    draftFilterEndDate.value = filterEndDate.value;
}

function applyFilters() {
    filterCompany.value = draftFilterCompany.value;

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
    fetchTranscriptions();
}

function toggleFilters() {
    filtersOpen.value = !filtersOpen.value;
    if (filtersOpen.value) {
        syncDraftFilters();
    }
}

function refresh() {
    fetchTranscriptions();
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
            page.value = 1;
            fetchTranscriptions();
        }, 250);
    },
);

watch(
    () => pageSize.value,
    () => {
        page.value = 1;
        fetchTranscriptions();
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
    fetchTranscriptions();
});

onBeforeUnmount(() => {
    window.removeEventListener("resize", updateViewport);
    document.removeEventListener("click", onDocumentClick);
});
</script>
