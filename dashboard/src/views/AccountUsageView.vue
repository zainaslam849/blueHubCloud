<script setup lang="ts">
import { computed } from "vue";
import { useRoute } from "vue-router";
import Card from "../components/ui/Card.vue";
import PageHeader from "../components/ui/PageHeader.vue";

const route = useRoute();

const title = computed(() => {
    const t = route.meta.title;
    return typeof t === "string" ? t : "Account & Usage";
});

const description = computed(() => {
    if (route.name === "usage") return "Usage summary layout (UI-only).";
    if (route.name === "account") return "Account overview layout (UI-only).";
    return "Billing-ready usage summary layout (UI-only).";
});

const usageRows = [
    { label: "Transcription minutes (this month)", value: "1,920" },
    { label: "Estimated cost", value: "$96.00" },
    { label: "Currency", value: "USD" },
] as const;
</script>

<template>
    <div>
        <PageHeader :title="title" :description="description" />

        <section class="grid">
            <Card title="Plan">
                <div class="kv">
                    <div class="k">Tier</div>
                    <div class="v">Standard</div>
                    <div class="k">Status</div>
                    <div class="v">Active</div>
                </div>
            </Card>

            <Card title="Usage">
                <div class="kv">
                    <template v-for="r in usageRows" :key="r.label">
                        <div class="k">{{ r.label }}</div>
                        <div class="v">{{ r.value }}</div>
                    </template>
                </div>
            </Card>
        </section>

        <section class="grid2">
            <Card title="Invoices">
                <p class="muted">Invoices list placeholder.</p>
            </Card>

            <Card title="API">
                <p class="muted">
                    API credentials UI placeholder. For PBX ingestion, you’ll
                    show the account API key and allowed provider here.
                </p>
            </Card>
        </section>
    </div>
</template>

<style scoped>
.grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: var(--space-4);
    margin-bottom: var(--space-6);
}

.grid2 {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
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
    font-weight: 700;
}

.muted {
    margin: 0;
    opacity: 0.75;
}

@media (max-width: 960px) {
    .grid,
    .grid2 {
        grid-template-columns: 1fr;
    }

    .kv {
        grid-template-columns: 1fr;
    }
}
</style>
