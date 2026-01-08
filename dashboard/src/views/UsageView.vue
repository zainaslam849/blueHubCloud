<script setup lang="ts">
import Card from "../components/ui/Card.vue";
import PageHeader from "../components/ui/PageHeader.vue";

const usageRows = [
    { label: "Transcription minutes (this month)", value: "1,920" },
    { label: "Estimated cost", value: "$96.00" },
    { label: "Currency", value: "USD" },
] as const;
</script>

<template>
    <div>
        <PageHeader
            title="Usage"
            description="Usage summary layout (UI-only)."
        />

        <section class="grid">
            <Card title="This month">
                <div class="kv">
                    <template v-for="r in usageRows" :key="r.label">
                        <div class="k">{{ r.label }}</div>
                        <div class="v">{{ r.value }}</div>
                    </template>
                </div>
            </Card>

            <Card title="Trends" subtitle="Placeholder chart area">
                <div class="chart" aria-hidden="true"></div>
                <p class="muted">Replace with a real chart later.</p>
            </Card>
        </section>
    </div>
</template>

<style scoped>
.grid {
    display: grid;
    grid-template-columns: 1.2fr 1fr;
    gap: var(--space-4);
}

.kv {
    display: grid;
    grid-template-columns: 220px 1fr;
    gap: 10px;
}

.k {
    opacity: 0.75;
}

.v {
    font-weight: 750;
}

.chart {
    height: 140px;
    border-radius: var(--radius-lg);
    border: 1px dashed var(--border);
    background: color-mix(in srgb, var(--color-secondary) 8%, var(--surface));
}

.muted {
    margin: 10px 0 0;
    opacity: 0.75;
}

@media (max-width: 960px) {
    .grid {
        grid-template-columns: 1fr;
    }

    .kv {
        grid-template-columns: 1fr;
    }
}
</style>
