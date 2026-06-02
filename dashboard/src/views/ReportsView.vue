<script setup lang="ts">
import { onMounted, ref } from "vue";
import Card from "../components/ui/Card.vue";
import PageHeader from "../components/ui/PageHeader.vue";
import ReportsTable, { type ReportRow } from "../components/reports/ReportsTable.vue";
import { userApi } from "../api/user";

type ApiReport = {
    id: number;
    week: string;
    status: string;
    pdf_url?: string;
    csv_url?: string;
};

const loading = ref(true);
const rows = ref<ReportRow[]>([]);
const error = ref<string | null>(null);
const pendingMinutesCount = ref(0);

function mapStatus(status: string): ReportRow["status"] {
    if (status === "completed") return "ready";
    if (status === "failed" || status === "pending_minutes") return "failed";
    return "processing";
}

async function load() {
    loading.value = true;
    error.value = null;
    try {
        const res = await userApi.get<{ data: ApiReport[] }>("/reports");
        const reports = res.data.data ?? [];
        pendingMinutesCount.value = reports.filter((r) => r.status === "pending_minutes").length;
        rows.value = reports.map((r) => ({
            id: String(r.id),
            week: r.week,
            status: mapStatus(r.status),
            pdfUrl: r.pdf_url,
            csvUrl: r.csv_url,
        }));
    } catch (e) {
        error.value = e instanceof Error ? e.message : "Failed to load reports.";
    } finally {
        loading.value = false;
    }
}

onMounted(load);
</script>

<template>
    <div>
        <PageHeader
            title="Reports"
            description="Weekly reporting snapshots and exports."
        />

        <div v-if="pendingMinutesCount > 0" class="minutesBanner">
            {{ pendingMinutesCount }} report{{ pendingMinutesCount > 1 ? 's' : '' }} are waiting for minutes.
            Contact your administrator to top up your plan.
        </div>

        <div v-if="error" class="errorBanner">{{ error }}</div>

        <Card>
            <ReportsTable :rows="rows" :loading="loading" />
        </Card>
    </div>
</template>

<style scoped>
.minutesBanner {
    margin-bottom: var(--space-4);
    padding: var(--space-3) var(--space-4);
    background: color-mix(in srgb, #b7791f 12%, transparent);
    border: 1px solid #b7791f;
    border-radius: 10px;
    color: #b7791f;
    font-size: 0.9rem;
}

.errorBanner {
    margin-bottom: var(--space-4);
    padding: var(--space-3) var(--space-4);
    background: color-mix(in srgb, #e53e3e 12%, transparent);
    border: 1px solid #e53e3e;
    border-radius: 10px;
    color: #e53e3e;
    font-size: 0.9rem;
}
</style>
