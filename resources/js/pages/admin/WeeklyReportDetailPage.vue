<template>
    <div class="admin-container admin-page">
        <header class="admin-page__header">
            <div>
                <p class="admin-page__kicker">Reports</p>
                <h1 class="admin-page__title">Weekly Call Report</h1>
                <p class="admin-page__subtitle">
                    Comprehensive weekly analysis and insights.
                </p>
            </div>

            <div class="admin-reportHeader__actions">
                <BaseButton
                    variant="secondary"
                    size="sm"
                    :to="{ name: 'admin.dashboard' }"
                >
                    Back to Dashboard
                </BaseButton>
                <BaseButton
                    variant="secondary"
                    size="sm"
                    :loading="loading"
                    @click="refresh"
                >
                    Refresh
                </BaseButton>
                <BaseButton
                    v-if="report?.exports?.pdf_available"
                    variant="primary"
                    size="sm"
                >
                    Export PDF
                </BaseButton>
            </div>
        </header>

        <div v-if="error" class="admin-alert admin-alert--error">
            {{ error }}
        </div>

        <div class="admin-reportSections">
            <!-- Section 1: Header -->
            <ReportHeader
                :loading="loading"
                :header="report?.header"
            />

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
import CategoryBreakdowns from "../../components/admin/reports/CategoryBreakdowns.vue";
import InsightsRecommendations from "../../components/admin/reports/InsightsRecommendations.vue";

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
