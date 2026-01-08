<template>
    <div class="admin-container admin-page">
        <header class="admin-page__header">
            <div>
                <p class="admin-page__kicker">Operations</p>
                <h1 class="admin-page__title">Calls</h1>
                <p class="admin-page__subtitle">
                    Search, sort, and review recent calls.
                </p>
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

                    <BaseBadge variant="info">
                        {{ meta.total }} total
                    </BaseBadge>
                </div>

                <div class="admin-callsToolbar__right">
                    <BaseButton
                        variant="secondary"
                        size="sm"
                        :loading="loading"
                        @click="refresh"
                    >
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
                :virtualized="true"
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

                <template #header-provider>
                    <button
                        type="button"
                        class="admin-callsSortBtn"
                        @click="toggleSort('provider')"
                    >
                        Provider
                        <span class="admin-callsSortBtn__chev">{{
                            sortGlyph("provider")
                        }}</span>
                    </button>
                </template>

                <template #header-duration>
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
import { onMounted, ref, watch } from "vue";
import { useRouter } from "vue-router";

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

const rows = ref([]);
const meta = ref({
    currentPage: 1,
    lastPage: 1,
    perPage: 25,
    total: 0,
});

const columns = ref([
    { key: "callId", label: "Call ID" },
    { key: "company", label: "Company" },
    { key: "provider", label: "Provider" },
    { key: "duration", label: "Duration", cellClass: "admin-callsCol--right" },
    { key: "status", label: "Status" },
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
    };
}

function formatDuration(seconds) {
    const s = Number(seconds);
    if (!Number.isFinite(s) || s < 0) return "—";

    const hh = Math.floor(s / 3600);
    const mm = Math.floor((s % 3600) / 60);
    const ss = Math.floor(s % 60);

    if (hh > 0) {
        return `${hh}:${String(mm).padStart(2, "0")}:${String(ss).padStart(
            2,
            "0"
        )}`;
    }

    return `${mm}:${String(ss).padStart(2, "0")}`;
}

function formatDate(iso) {
    const t = new Date(iso);
    const ms = t.getTime();
    if (!Number.isFinite(ms)) return "—";
    return t.toLocaleString();
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
        const res = await adminApi.get("/calls", {
            params: {
                page: page.value,
                per_page: pageSize.value,
                search: search.value || undefined,
                sort: sortBy.value,
                direction: sortDirection.value,
            },
        });

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

function refresh() {
    fetchCalls();
}

function viewRow(row) {
    const id = row?.id;
    if (!id) return;
    router.push({ name: "admin.calls.detail", params: { id } });
}

let searchTimer = 0;
watch(
    () => search.value,
    () => {
        if (searchTimer) window.clearTimeout(searchTimer);
        searchTimer = window.setTimeout(() => {
            page.value = 1;
            fetchCalls();
        }, 250);
    }
);

watch(
    () => pageSize.value,
    () => {
        page.value = 1;
        fetchCalls();
    }
);

onMounted(() => {
    fetchCalls();
});
</script>
