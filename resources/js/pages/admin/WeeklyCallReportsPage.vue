<template>
    <div class="admin-container admin-page">
        <header class="admin-page__header">
            <div>
                <p class="admin-page__kicker">Reports</p>
                <h1 class="admin-page__title">Weekly Call Reports</h1>
                <p class="admin-page__subtitle">
                    View generated weekly call analytics reports.
                </p>
            </div>
        </header>

        <section class="admin-card admin-card--glass">
            <div class="admin-reportsToolbar">
                <div class="admin-reportsToolbar__left">
                    <BaseBadge variant="info">{{ rows.length }} reports</BaseBadge>
                </div>

                <div class="admin-reportsToolbar__right">
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
                empty-title="No reports"
                empty-description="No weekly call reports have been generated yet."
            >
                <template #cell-weekRange="{ row }">
                    <span class="admin-mono">
                        {{ formatWeekRange(row) }}
                    </span>
                </template>

                <template #cell-totalCalls="{ value }">
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
                    <BaseButton
                        variant="ghost"
                        size="sm"
                        :to="{
                            name: 'admin.weeklyReports.detail',
                            params: { id: row?.id },
                        }"
                    >
                        View
                    </BaseButton>
                </template>
            </BaseTable>
        </section>
    </div>
</template>

<script setup>
import { onMounted, ref } from "vue";

import adminApi from "../../router/admin/api";
import {
    BaseBadge,
    BaseButton,
    BaseTable,
} from "../../components/admin/base";

const loading = ref(true);
const error = ref("");
const rows = ref([]);

const columns = ref([
    { key: "id", label: "ID" },
    { key: "company", label: "Company" },
    { key: "weekRange", label: "Week" },
    { key: "totalCalls", label: "Total Calls", cellClass: "admin-table__num" },
    { key: "answeredCalls", label: "Answered", cellClass: "admin-table__num" },
    { key: "answerRate", label: "Answer Rate", cellClass: "admin-table__num" },
    { key: "actions", label: "", cellClass: "admin-table__actions" },
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

async function fetchReports() {
    loading.value = true;
    error.value = "";

    try {
        // First get user info to get company_id
        const meRes = await adminApi.get("/me");
        const companyId = meRes?.data?.company_id;

        if (!companyId) {
            error.value = "Unable to determine company.";
            rows.value = [];
            return;
        }

        const res = await adminApi.get("/weekly-call-reports", {
            params: { company_id: companyId },
        });

        const data = res?.data?.data;
        rows.value = Array.isArray(data) ? data.map(normalizeRow) : [];
    } catch (e) {
        rows.value = [];
        error.value = "Failed to load weekly reports.";
    } finally {
        loading.value = false;
    }
}

function refresh() {
    fetchReports();
}

onMounted(() => {
    fetchReports();
});
</script>
