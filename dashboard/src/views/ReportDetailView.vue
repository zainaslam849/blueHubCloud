<script setup lang="ts">
import { computed } from "vue";
import { useRoute } from "vue-router";
import Card from "../components/ui/Card.vue";
import PageHeader from "../components/ui/PageHeader.vue";
import Badge from "../components/ui/Badge.vue";

const route = useRoute();

const reportId = computed(() => String(route.params.id ?? ""));
</script>

<template>
    <div>
        <PageHeader
            :title="`Report ${reportId}`"
            description="Report detail layout (UI-only)."
        >
            <template #actions>
                <Badge tone="success">completed</Badge>
            </template>
        </PageHeader>

        <section class="grid">
            <Card title="Summary">
                <div class="kv">
                    <div class="k">Week</div>
                    <div class="v">2025-12-22 → 2025-12-28</div>
                    <div class="k">Company</div>
                    <div class="v">Example Co</div>
                    <div class="k">Generated</div>
                    <div class="v">2025-12-29 12:15 UTC</div>
                </div>
            </Card>

            <Card title="Downloads" subtitle="Wired to backend later">
                <div class="downloads">
                    <button class="btn" type="button">Download PDF</button>
                    <button class="btn" type="button">Download CSV</button>
                </div>
            </Card>
        </section>

        <section class="grid2">
            <Card title="Highlights">
                <ul class="list">
                    <li>Top extensions: 101, 203, 302</li>
                    <li>Average call duration increased</li>
                    <li>Higher after-hours volume</li>
                </ul>
            </Card>

            <Card title="Notes">
                <p class="muted">
                    This section is intentionally static. Replace with
                    backend-driven insights when your Laravel endpoints are
                    ready.
                </p>
            </Card>
        </section>
    </div>
</template>

<style scoped>
.grid {
    display: grid;
    grid-template-columns: 2fr 1fr;
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
    grid-template-columns: 130px 1fr;
    gap: 10px;
}

.k {
    opacity: 0.75;
}

.v {
    font-weight: 700;
}

.downloads {
    display: grid;
    gap: 10px;
}

.btn {
    width: 100%;
}

.list {
    margin: 0;
    padding-left: 18px;
    display: grid;
    gap: 10px;
}

.muted {
    margin: 0;
    opacity: 0.75;
}

@media (max-width: 960px) {
    .grid {
        grid-template-columns: 1fr;
    }

    .grid2 {
        grid-template-columns: 1fr;
    }

    .kv {
        grid-template-columns: 1fr;
    }
}
</style>
