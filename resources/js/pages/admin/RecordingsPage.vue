<template>
    <div class="admin-container admin-page">
        <header class="admin-page__header">
            <div>
                <p class="admin-page__kicker">Library</p>
                <h1 class="admin-page__title">Recordings</h1>
                <p class="admin-page__subtitle">
                    Filter and review recordings at scale.
                </p>
            </div>
        </header>

        <section class="admin-card admin-card--glass">
            <div class="admin-recordingsToolbar">
                <div class="admin-recordingsToolbar__left">
                    <div class="admin-recordingsFilters">
                        <div
                            class="admin-field admin-recordingsFilters__company"
                        >
                            <label
                                class="admin-field__label"
                                for="recordings-company"
                            >
                                Company
                            </label>
                            <input
                                id="recordings-company"
                                v-model="company"
                                class="admin-input"
                                type="search"
                                autocomplete="off"
                                placeholder="Company name"
                            />
                        </div>

                        <div class="admin-field">
                            <label
                                class="admin-field__label"
                                for="recordings-status"
                            >
                                Status
                            </label>
                            <select
                                id="recordings-status"
                                v-model="status"
                                class="admin-input"
                            >
                                <option value="">Any</option>
                                <option value="uploaded">uploaded</option>
                                <option value="stored">stored</option>
                                <option value="queued">queued</option>
                                <option value="processing">processing</option>
                                <option value="completed">completed</option>
                                <option value="transcribing">
                                    transcribing
                                </option>
                                <option value="transcribed">transcribed</option>
                                <option value="failed">failed</option>
                            </select>
                        </div>

                        <div
                            class="admin-field admin-recordingsFilters__duration"
                        >
                            <label
                                class="admin-field__label"
                                for="recordings-duration-min"
                            >
                                Duration min (s)
                            </label>
                            <input
                                id="recordings-duration-min"
                                v-model="durationMin"
                                class="admin-input"
                                type="number"
                                inputmode="numeric"
                                min="0"
                                placeholder="0"
                            />
                        </div>

                        <div
                            class="admin-field admin-recordingsFilters__duration"
                        >
                            <label
                                class="admin-field__label"
                                for="recordings-duration-max"
                            >
                                Duration max (s)
                            </label>
                            <input
                                id="recordings-duration-max"
                                v-model="durationMax"
                                class="admin-input"
                                type="number"
                                inputmode="numeric"
                                min="0"
                                placeholder="3600"
                            />
                        </div>

                        <div class="admin-field">
                            <label
                                class="admin-field__label"
                                for="recordings-date-from"
                            >
                                Date from
                            </label>
                            <input
                                id="recordings-date-from"
                                v-model="dateFrom"
                                class="admin-input"
                                type="date"
                            />
                        </div>

                        <div class="admin-field">
                            <label
                                class="admin-field__label"
                                for="recordings-date-to"
                            >
                                Date to
                            </label>
                            <input
                                id="recordings-date-to"
                                v-model="dateTo"
                                class="admin-input"
                                type="date"
                            />
                        </div>
                    </div>

                    <BaseBadge variant="info">{{ meta.total }} total</BaseBadge>
                </div>

                <div class="admin-recordingsToolbar__right">
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
                empty-title="No recordings"
                empty-description="No recordings match the current filters."
            >
                <template #header-recordingId>
                    <button
                        type="button"
                        class="admin-recordingsSortBtn"
                        @click="toggleSort('id')"
                    >
                        Recording ID
                        <span class="admin-recordingsSortBtn__chev">{{
                            sortGlyph("id")
                        }}</span>
                    </button>
                </template>

                <template #header-callId>
                    <button
                        type="button"
                        class="admin-recordingsSortBtn"
                        @click="toggleSort('call_uid')"
                    >
                        Call ID
                        <span class="admin-recordingsSortBtn__chev">{{
                            sortGlyph("call_uid")
                        }}</span>
                    </button>
                </template>

                <template #header-company>
                    <button
                        type="button"
                        class="admin-recordingsSortBtn"
                        @click="toggleSort('company')"
                    >
                        Company
                        <span class="admin-recordingsSortBtn__chev">{{
                            sortGlyph("company")
                        }}</span>
                    </button>
                </template>

                <template #header-storageProvider>
                    <button
                        type="button"
                        class="admin-recordingsSortBtn"
                        @click="toggleSort('storage_provider')"
                    >
                        Provider
                        <span class="admin-recordingsSortBtn__chev">{{
                            sortGlyph("storage_provider")
                        }}</span>
                    </button>
                </template>

                <template #header-codec>
                    <button
                        type="button"
                        class="admin-recordingsSortBtn"
                        @click="toggleSort('codec')"
                    >
                        Codec
                        <span class="admin-recordingsSortBtn__chev">{{
                            sortGlyph("codec")
                        }}</span>
                    </button>
                </template>

                <template #header-durationSeconds>
                    <button
                        type="button"
                        class="admin-recordingsSortBtn"
                        @click="toggleSort('recording_duration')"
                    >
                        Duration
                        <span class="admin-recordingsSortBtn__chev">{{
                            sortGlyph("recording_duration")
                        }}</span>
                    </button>
                </template>

                <template #header-status>
                    <button
                        type="button"
                        class="admin-recordingsSortBtn"
                        @click="toggleSort('status')"
                    >
                        Status
                        <span class="admin-recordingsSortBtn__chev">{{
                            sortGlyph("status")
                        }}</span>
                    </button>
                </template>

                <template #header-createdAt>
                    <button
                        type="button"
                        class="admin-recordingsSortBtn"
                        @click="toggleSort('created_at')"
                    >
                        Created
                        <span class="admin-recordingsSortBtn__chev">{{
                            sortGlyph("created_at")
                        }}</span>
                    </button>
                </template>

                <template #cell-audio="{ row }">
                    <span
                        class="admin-recordingsAudio"
                        :class="{
                            'admin-recordingsAudio--available': Boolean(
                                row?.recordingUrl
                            ),
                        }"
                        :title="
                            row?.recordingUrl
                                ? 'Recording URL available'
                                : 'No recording URL'
                        "
                        aria-hidden="true"
                    />
                </template>

                <template #cell-durationSeconds="{ value }">
                    <span class="admin-recordingsMono">{{
                        formatDuration(value)
                    }}</span>
                </template>

                <template #cell-status="{ row }">
                    <BaseBadge :variant="badgeVariant(row?.status)">
                        {{ String(row?.rawStatus || row?.status || "—") }}
                    </BaseBadge>
                </template>

                <template #cell-createdAt="{ value }">
                    <span class="admin-recordingsMono">{{
                        formatDate(value)
                    }}</span>
                </template>

                <template #cell-actions="{ row }">
                    <div class="admin-recordingsActions">
                        <BaseButton
                            variant="ghost"
                            size="sm"
                            :to="{
                                name: 'admin.recordings.detail',
                                params: { id: row?.id },
                            }"
                        >
                            View
                        </BaseButton>

                        <BaseButton
                            v-if="row?.callId"
                            variant="ghost"
                            size="sm"
                            :to="{
                                name: 'admin.calls.detail',
                                params: { id: row.callId },
                            }"
                        >
                            Call
                        </BaseButton>

                        <BaseButton
                            variant="ghost"
                            size="sm"
                            :href="row?.recordingUrl || ''"
                            target="_blank"
                            rel="noreferrer"
                            :disabled="!row?.recordingUrl"
                        >
                            Open
                        </BaseButton>
                    </div>
                </template>
            </BaseTable>

            <div class="admin-recordingsFooter">
                <BasePagination
                    v-model:page="page"
                    v-model:pageSize="pageSize"
                    :total="meta.total"
                    :disabled="loading"
                    :page-size-options="[10, 25, 50, 100, 200]"
                    hint="Server-side pagination"
                    @change="fetchRecordings"
                />
            </div>
        </section>
    </div>
</template>

<script setup>
import { onMounted, ref, watch } from "vue";

import adminApi from "../../router/admin/api";
import {
    BaseBadge,
    BaseButton,
    BasePagination,
    BaseTable,
} from "../../components/admin/base";

const loading = ref(true);
const error = ref("");

const company = ref("");
const status = ref("");
const durationMin = ref("");
const durationMax = ref("");
const dateFrom = ref("");
const dateTo = ref("");

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
    { key: "audio", label: "", headerClass: "admin-recordingsCol--icon" },
    { key: "recordingId", label: "Recording ID" },
    { key: "callId", label: "Call ID" },
    { key: "company", label: "Company" },
    { key: "storageProvider", label: "Provider" },
    { key: "codec", label: "Codec" },
    {
        key: "durationSeconds",
        label: "Duration",
        cellClass: "admin-recordingsCol--right",
    },
    { key: "status", label: "Status" },
    { key: "createdAt", label: "Created" },
    {
        key: "actions",
        label: "Actions",
        cellClass: "admin-recordingsCol--right",
    },
]);

function normalizeRow(item) {
    return {
        id: item.id,
        recordingId: item.recordingId ?? item.id,
        callId: item.callId,
        company: item.company,
        storageProvider: item.storageProvider,
        codec: item.codec,
        durationSeconds: item.durationSeconds,
        status: item.status,
        rawStatus: item.rawStatus,
        createdAt: item.createdAt,
        recordingUrl: item.recordingUrl,
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

function badgeVariant(statusCategory) {
    const s = String(statusCategory || "").toLowerCase();
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
    fetchRecordings();
}

function toIntOrUndefined(v) {
    const n = Number.parseInt(String(v || ""), 10);
    return Number.isFinite(n) ? n : undefined;
}

async function fetchRecordings() {
    loading.value = true;
    error.value = "";

    try {
        const res = await adminApi.get("/recordings", {
            params: {
                page: page.value,
                per_page: pageSize.value,
                company: company.value || undefined,
                status: status.value || undefined,
                duration_min: toIntOrUndefined(durationMin.value),
                duration_max: toIntOrUndefined(durationMax.value),
                date_from: dateFrom.value || undefined,
                date_to: dateTo.value || undefined,
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
        error.value = "Failed to load recordings.";
    } finally {
        loading.value = false;
    }
}

function refresh() {
    fetchRecordings();
}

let filterTimer = 0;
watch(
    () => [
        company.value,
        status.value,
        durationMin.value,
        durationMax.value,
        dateFrom.value,
        dateTo.value,
    ],
    () => {
        if (filterTimer) window.clearTimeout(filterTimer);
        filterTimer = window.setTimeout(() => {
            page.value = 1;
            fetchRecordings();
        }, 250);
    }
);

watch(
    () => pageSize.value,
    () => {
        page.value = 1;
        fetchRecordings();
    }
);

onMounted(() => {
    fetchRecordings();
});
</script>
