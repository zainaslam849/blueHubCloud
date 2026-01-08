<template>
    <div class="admin-container admin-page">
        <header class="admin-page__header">
            <div>
                <p class="admin-page__kicker">Insights</p>
                <h1 class="admin-page__title">Transcriptions</h1>
                <p class="admin-page__subtitle">
                    Read-only transcription viewer.
                </p>
            </div>
        </header>

        <section class="admin-card admin-card--glass">
            <div class="admin-transcriptionsToolbar">
                <div class="admin-transcriptionsToolbar__left">
                    <BaseBadge variant="info">{{ meta.total }} total</BaseBadge>
                </div>

                <div class="admin-transcriptionsToolbar__right">
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
                empty-title="No transcriptions"
                empty-description="There are no transcriptions to display."
            >
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
    { key: "id", label: "ID" },
    { key: "callId", label: "Call ID" },
    { key: "company", label: "Company" },
    { key: "provider", label: "Provider" },
    { key: "language", label: "Language" },
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
        language: item.language,
        durationSeconds: item.durationSeconds,
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

async function fetchTranscriptions() {
    loading.value = true;
    error.value = "";

    try {
        const res = await adminApi.get("/transcriptions", {
            params: {
                page: page.value,
                per_page: pageSize.value,
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
        error.value = "Failed to load transcriptions.";
    } finally {
        loading.value = false;
    }
}

function refresh() {
    fetchTranscriptions();
}

watch(
    () => pageSize.value,
    () => {
        page.value = 1;
        fetchTranscriptions();
    }
);

onMounted(() => {
    fetchTranscriptions();
});
</script>
