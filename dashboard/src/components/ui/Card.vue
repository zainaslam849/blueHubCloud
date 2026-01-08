<script setup lang="ts">
type Props = {
    title?: string;
    subtitle?: string;
};

defineProps<Props>();
</script>

<template>
    <section class="card">
        <header v-if="title || subtitle" class="cardHeader">
            <div class="cardTitle">{{ title }}</div>
            <div v-if="subtitle" class="cardSubtitle">{{ subtitle }}</div>
        </header>

        <div class="cardBody">
            <slot />
        </div>
    </section>
</template>

<style scoped>
.card {
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    background: var(--surface);
    box-shadow: var(--shadow-sm);
    transition: transform var(--duration-fast) var(--ease-standard),
        box-shadow var(--duration-fast) var(--ease-standard),
        border-color var(--duration-fast) var(--ease-standard);
}

.card:hover {
    transform: translateY(-1px);
    box-shadow: var(--shadow-md);
    border-color: color-mix(in srgb, var(--color-text) 16%, var(--border));
}

.cardHeader {
    padding: var(--space-4) var(--space-5);
    border-bottom: 1px solid var(--border);
}

.cardTitle {
    font-weight: 700;
}

.cardSubtitle {
    margin-top: 2px;
    opacity: 0.7;
    font-size: 0.95rem;
}

.cardBody {
    padding: var(--space-5);
}

@media (prefers-reduced-motion: reduce) {
    .card,
    .card:hover {
        transition: none;
        transform: none;
    }
}
</style>
