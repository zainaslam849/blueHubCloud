<script setup lang="ts">
import Card from "../components/ui/Card.vue";
import PageHeader from "../components/ui/PageHeader.vue";

const profileRows = [
    { label: "Company", value: "BlueHub" },
    { label: "Plan", value: "Standard" },
    { label: "Status", value: "Active" },
] as const;
</script>

<template>
    <div>
        <PageHeader
            title="Account"
            description="Account settings layout (UI-only)."
        />

        <section class="grid">
            <Card title="Profile">
                <div class="kv">
                    <template v-for="r in profileRows" :key="r.label">
                        <div class="k">{{ r.label }}</div>
                        <div class="v">{{ r.value }}</div>
                    </template>
                </div>
            </Card>

            <Card title="Security" subtitle="Placeholder">
                <p class="muted">Password, SSO, and access controls go here.</p>
                <div class="actions">
                    <button class="btn" type="button">Reset password</button>
                    <button class="btn btn--secondary" type="button">
                        Configure SSO
                    </button>
                </div>
            </Card>
        </section>
    </div>
</template>

<style scoped>
.grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: var(--space-4);
}

.kv {
    display: grid;
    grid-template-columns: 160px 1fr;
    gap: 10px;
}

.k {
    opacity: 0.75;
}

.v {
    font-weight: 750;
}

.muted {
    margin: 0;
    opacity: 0.75;
}

.actions {
    margin-top: var(--space-4);
    display: flex;
    gap: var(--space-3);
    flex-wrap: wrap;
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
