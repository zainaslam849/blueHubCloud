<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useRouter } from "vue-router";
import Card from "../components/ui/Card.vue";
import DashboardSummaryCards from "../components/dashboard/DashboardSummaryCards.vue";
import PageHeader from "../components/ui/PageHeader.vue";
import TriggerIngestButton from "../components/admin/TriggerIngestButton.vue";
import { getAiPendingStats } from "../api/aiProcessing";

// UI-only: demonstrate loading + empty states without backend calls.
const demoState = ref<"loaded" | "loading" | "empty">("loaded");
const loadingAiPending = ref(false);
const aiPending = ref(0);
const aiError = ref<string | null>(null);

const router = useRouter();

const summaryData = {
    totalCallsWeek: 1248,
    totalMinutesProcessed: 6910,
    reportsGenerated: 12,
    currentProcessingJobs: 3,
} as const;

const aiHealthText = computed(() => {
    if (loadingAiPending.value) {
        return "Loading";
    }

    return aiPending.value > 0 ? "Action required" : "Healthy";
});

const aiHealthClass = computed(() =>
    aiPending.value > 0 ? "health--warning" : "health--ok",
);

async function loadAiPending(): Promise<void> {
    loadingAiPending.value = true;
    aiError.value = null;

    try {
        const stats = await getAiPendingStats({ steps: ["summary", "categories"] });
        aiPending.value = stats.total_pending;
    } catch (e) {
        aiError.value = e instanceof Error ? e.message : "Failed to load AI pending stats";
    } finally {
        loadingAiPending.value = false;
    }
}

onMounted(async () => {
    await loadAiPending();
});
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
                    <div class="k">AI pending calls</div>
                    <div class="v">{{ loadingAiPending ? "..." : aiPending }}</div>
                    <div class="k">AI processing health</div>
                    <div class="v" :class="aiHealthClass">{{ aiHealthText }}</div>
                </div>
                <p v-if="aiError" class="error-text">{{ aiError }}</p>
                <div class="actions-row">
                    <button
                        class="btn btn--ghost"
                        type="button"
                        @click="loadAiPending"
                    >
                        Refresh pending
                    </button>
                    <button
                        class="btn"
                        type="button"
                        @click="router.push({ name: 'ai-processing' })"
                    >
                        Manage AI processing
                    </button>
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

.health--ok {
    color: #1f9d55;
}

.health--warning {
    color: #b7791f;
}

.actions-row {
    margin-top: var(--space-3);
    display: flex;
    gap: var(--space-2);
    flex-wrap: wrap;
}

.error-text {
    margin-top: 10px;
    color: #a60020;
}

@media (max-width: 960px) {
    .grid2 {
        grid-template-columns: 1fr;
    }
}
</style>
