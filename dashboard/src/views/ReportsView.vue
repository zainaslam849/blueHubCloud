<script setup lang="ts">
import Card from "../components/ui/Card.vue";
import PageHeader from "../components/ui/PageHeader.vue";
import ReportsTable, {
    type ReportRow,
} from "../components/reports/ReportsTable.vue";

const rows: ReportRow[] = [
    {
        id: "r_2025_12_28",
        week: "2025-12-22 → 2025-12-28",
        status: "ready",
        pdfUrl: "#",
        csvUrl: "#",
    },
    {
        id: "r_2025_12_21",
        week: "2025-12-15 → 2025-12-21",
        status: "processing",
    },
    {
        id: "r_2025_12_14",
        week: "2025-12-08 → 2025-12-14",
        status: "failed",
    },
    {
        id: "r_2025_12_07",
        week: "2025-12-01 → 2025-12-07",
        status: "ready",
        pdfUrl: "#",
        csvUrl: "#",
    },
] as const;
</script>

<template>
    <div>
        <PageHeader
            title="Reports"
            description="Weekly reporting snapshots and exports (layout-only)."
        />

        <Card>
            <ReportsTable :rows="rows" />
        </Card>
    </div>
</template>
