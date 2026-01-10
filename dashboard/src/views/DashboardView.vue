<script setup lang="ts">
import { ref } from "vue";
import Card from "../components/ui/Card.vue";
import DashboardSummaryCards from "../components/dashboard/DashboardSummaryCards.vue";
import PageHeader from "../components/ui/PageHeader.vue";
import TriggerIngestButton from "../components/admin/TriggerIngestButton.vue";

// UI-only: demonstrate loading + empty states without backend calls.
const demoState = ref<"loaded" | "loading" | "empty">("loaded");

const summaryData = {
    totalCallsWeek: 1248,
    totalMinutesProcessed: 6910,
    reportsGenerated: 12,
    currentProcessingJobs: 3,
} as const;
</script>

<template>
    <div class="page">
        <PageHeader
            title="Dashboard"
            description="High-level reporting overview (UI-only)."
        />

        <DashboardSummaryCards
            :loading="demoState === 'loading'"
            :data="demoState === 'loaded' ? summaryData : null"
        />

        <div class="demoRow" aria-label="Demo controls">
            <button
                class="btn btn--ghost"
                type="button"
                @click="demoState = 'loaded'"
            >
                Loaded
            </button>
            <button
                class="btn btn--ghost"
                type="button"
                @click="demoState = 'loading'"
            >
                Loading
            </button>
            <button
                class="btn btn--ghost"
                type="button"
                @click="demoState = 'empty'"
            >
                Empty
            </button>
            <div style="margin-left: 8px">
                <TriggerIngestButton />
            </div>
        </div>

        <section class="grid2">
            <Card title="Recent activity" subtitle="Placeholder feed">
                <ul class="list">
                    <li>Weekly report generated</li>
                    <li>New calls ingested</li>
                    <li>Transcription usage updated</li>
                </ul>
            </Card>

            <Card title="Health" subtitle="Pipeline snapshot">
                <div class="kv">
                    <div class="k">Ingestion</div>
                    <div class="v">OK</div>
                    <div class="k">Processing</div>
                    <div class="v">OK</div>
                    <div class="k">Transcription</div>
                    <div class="v">OK</div>
                    <div class="k">Reporting</div>
                    <div class="v">OK</div>
                </div>
            </Card>
        </section>
    </div>
</template>

<style scoped>
.demoRow {
    margin: var(--space-4) 0 var(--space-6);
    display: flex;
    gap: var(--space-2);
    flex-wrap: wrap;
}

.grid2 {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: var(--space-4);
}

.list {
    margin: 0;
    padding-left: 18px;
    display: grid;
    gap: 10px;
}

.kv {
    display: grid;
    grid-template-columns: 1fr auto;
    gap: 10px;
}

.k {
    opacity: 0.75;
}

.v {
    font-weight: 700;
}

@media (max-width: 960px) {
    .grid2 {
        grid-template-columns: 1fr;
    }
}
</style>
