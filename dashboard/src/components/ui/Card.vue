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
    background: linear-gradient(
        180deg,
        color-mix(in srgb, var(--surface) 96%, transparent),
        color-mix(in srgb, var(--surface-2) 70%, transparent)
    );
    box-shadow: var(--shadow-sm);
    backdrop-filter: blur(14px);
    transition:
        transform var(--duration-fast) var(--ease-standard),
        box-shadow var(--duration-fast) var(--ease-standard),
        border-color var(--duration-fast) var(--ease-standard);
}

.card:hover {
    transform: translateY(-1px);
    box-shadow: var(--shadow-md);
    border-color: color-mix(in srgb, var(--color-primary) 26%, var(--border));
}

.cardHeader {
    padding: var(--space-4) var(--space-5);
    border-bottom: 1px solid var(--border);
}

.cardTitle {
    font-weight: var(--weight-semibold);
    letter-spacing: var(--tracking-tight);
}

.cardSubtitle {
    margin-top: 2px;
    opacity: 0.72;
    font-size: var(--text-sm);
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
