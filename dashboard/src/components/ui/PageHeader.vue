<script setup lang="ts">
type Props = {
    title: string;
    description?: string;
};

defineProps<Props>();
</script>

<template>
    <header class="pageHeader">
        <div>
            <h1 class="h1">{{ title }}</h1>
            <p v-if="description" class="desc">{{ description }}</p>
        </div>
        <div class="actions">
            <slot name="actions" />
        </div>
    </header>
</template>

<style scoped>
.pageHeader {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: var(--space-4);
    margin-bottom: var(--space-6);
}

.h1 {
    margin: 0;
    font-size: 1.6rem;
    line-height: 1.2;
}

.desc {
    margin: 6px 0 0;
    opacity: 0.75;
}

.actions {
    display: flex;
    align-items: center;
    gap: var(--space-2);
}

@media (max-width: 720px) {
    .pageHeader {
        flex-direction: column;
        align-items: stretch;
    }
}
</style>
