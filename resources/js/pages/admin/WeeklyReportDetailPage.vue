<template>
    <div class="admin-container admin-page">
        <header class="admin-reportDetailHeader">
            <div class="admin-reportDetailHeader__left">
                <div class="admin-reportDetailHeader__icon">
                    <svg
                        viewBox="0 0 24 24"
                        fill="none"
                        xmlns="http://www.w3.org/2000/svg"
                    >
                        <rect
                            x="3"
                            y="3"
                            width="18"
                            height="18"
                            rx="2"
                            stroke="currentColor"
                            stroke-width="1.5"
                        />
                        <path
                            d="M7 8h10M7 12h10M7 16h5"
                            stroke="currentColor"
                            stroke-width="1.5"
                            stroke-linecap="round"
                        />
                    </svg>
                </div>
                <div class="admin-reportDetailHeader__content">
                    <div class="admin-reportDetailHeader__breadcrumb">
                        <router-link
                            :to="{ name: 'admin.weeklyReports' }"
                            class="admin-reportDetailHeader__breadLink"
                        >
                            Reports
                        </router-link>
                        <span class="admin-reportDetailHeader__breadSep"
                            >/</span
                        >
                        <span>Weekly Report</span>
                    </div>
                    <h1 class="admin-reportDetailHeader__title">
                        {{ report?.header?.company_name || "Weekly Report" }}
                    </h1>
                    <p class="admin-reportDetailHeader__subtitle">
                        Week of {{ report?.header?.week_start || "Loading..." }}
                    </p>
                </div>
            </div>

            <div class="admin-reportDetailHeader__stats">
                <div class="admin-reportDetailHeader__stat">
                    <div class="admin-reportDetailHeader__statLabel">
                        Total Calls
                    </div>
                    <div class="admin-reportDetailHeader__statValue">
                        {{ report?.metrics?.total_calls || "—" }}
                    </div>
                </div>
                <div class="admin-reportDetailHeader__stat">
                    <div class="admin-reportDetailHeader__statLabel">
                        Answer Rate
                    </div>
                    <div class="admin-reportDetailHeader__statValue">
                        {{ report?.metrics?.answer_rate || "—" }}%
                    </div>
                </div>
            </div>

            <div class="admin-reportDetailHeader__actions">
                <BaseButton
                    variant="ghost"
                    size="sm"
                    :to="{ name: 'admin.weeklyReports' }"
                    class="admin-detailActionBtn"
                >
                    <svg
                        viewBox="0 0 24 24"
                        fill="none"
                        xmlns="http://www.w3.org/2000/svg"
                        class="admin-detailActionBtn__icon"
                    >
                        <path
                            d="M19 12H5M5 12L12 19M5 12L12 5"
                            stroke="currentColor"
                            stroke-width="2"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        />
                    </svg>
                    Back
                </BaseButton>
                <BaseButton
                    variant="ghost"
                    size="sm"
                    :loading="loading"
                    @click="refresh"
                    class="admin-detailActionBtn"
                >
                    <svg
                        viewBox="0 0 24 24"
                        fill="none"
                        xmlns="http://www.w3.org/2000/svg"
                        class="admin-detailActionBtn__icon"
                    >
                        <path
                            d="M20 12a8 8 0 1 1-2.34-5.66"
                            stroke="currentColor"
                            stroke-width="1.8"
                            stroke-linecap="round"
                        />
                        <path
                            d="M20 4v6h-6"
                            stroke="currentColor"
                            stroke-width="1.8"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        />
                    </svg>
                    Refresh
                </BaseButton>
                <BaseButton
                    v-if="report?.exports?.pdf_available"
                    variant="primary"
                    size="sm"
                    class="admin-detailActionBtn"
                >
                    <svg
                        viewBox="0 0 24 24"
                        fill="none"
                        xmlns="http://www.w3.org/2000/svg"
                        class="admin-detailActionBtn__icon"
                    >
                        <path
                            d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"
                            stroke="currentColor"
                            stroke-width="2"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        />
                    </svg>
                    Export PDF
                </BaseButton>
            </div>
        </header>

        <div v-if="error" class="admin-alert admin-alert--error">
            {{ error }}
        </div>

        <div class="admin-reportSections">
            <!-- Section 1: Header -->
            <ReportHeader :loading="loading" :header="report?.header" />

            <!-- Section 2: Executive Summary -->
            <ExecutiveSummary
                :loading="loading"
                :summary="report?.executive_summary"
            />

            <!-- Section 3: Quantitative Analysis -->
            <QuantitativeAnalysis
                :loading="loading"
                :metrics="report?.metrics"
            />

            <CallEndpoints
                :loading="loading"
                :endpoints="report?.call_endpoints"
            />

            <!-- Section 4: Category Breakdowns -->
            <CategoryBreakdowns
                :loading="loading"
                :breakdowns="report?.category_breakdowns"
            />

            <!-- Section 5: Insights & Recommendations -->
            <InsightsRecommendations
                :loading="loading"
                :insights="report?.insights"
            />

            <AutomationPriorityViews
                :loading="loading"
                :advanced="report?.advanced_views"
            />
        </div>
    </div>
</template>

<script setup>
import { onMounted, ref, watch } from "vue";
import { useRoute } from "vue-router";

import adminApi from "../../router/admin/api";
import { BaseButton } from "../../components/admin/base";
import ReportHeader from "../../components/admin/reports/ReportHeader.vue";
import ExecutiveSummary from "../../components/admin/reports/ExecutiveSummary.vue";
import QuantitativeAnalysis from "../../components/admin/reports/QuantitativeAnalysis.vue";
import CallEndpoints from "../../components/admin/reports/CallEndpoints.vue";
import CategoryBreakdowns from "../../components/admin/reports/CategoryBreakdowns.vue";
import InsightsRecommendations from "../../components/admin/reports/InsightsRecommendations.vue";
import AutomationPriorityViews from "../../components/admin/reports/AutomationPriorityViews.vue";

const route = useRoute();

const loading = ref(true);
const error = ref("");
const report = ref(null);

async function fetchReport() {
    const id = route.params.id;

    loading.value = true;
    error.value = "";

    try {
        const res = await adminApi.get(`/weekly-call-reports/${id}`);
        report.value = res?.data?.data ?? null;
    } catch (e) {
        report.value = null;

        const status = e?.response?.status;
        if (status === 404) {
            error.value = "Report not found.";
        } else if (status === 403) {
            error.value = "You do not have permission to view this report.";
        } else {
            error.value = "Failed to load report.";
        }
    } finally {
        loading.value = false;
    }
}

function refresh() {
    fetchReport();
}

watch(
    () => route.params.id,
    () => {
        fetchReport();
    },
);

onMounted(() => {
    fetchReport();
});
</script>
