<script setup lang="ts">
import AppIcon from "../icons/AppIcon.vue";

type Props = {
    icon: "calls" | "minutes" | "generated" | "jobs";
    label: string;
    value: string;
    loading?: boolean;
};

const props = withDefaults(defineProps<Props>(), {
    loading: false,
});
</script>

<template>
    <div class="card" :class="{ loading: props.loading }">
        <div class="top">
            <div class="icon" aria-hidden="true">
                <AppIcon :name="props.icon" />
            </div>
            <div class="label">
                <span v-if="!props.loading">{{ props.label }}</span>
                <span v-else class="sk sk--label" aria-hidden="true"></span>
            </div>
        </div>

        <div class="value" :aria-label="props.label">
            <span v-if="!props.loading">{{ props.value }}</span>
            <span v-else class="sk sk--value" aria-hidden="true"></span>
        </div>
    </div>
</template>

<style scoped>
.card {
    border-radius: var(--radius-lg);
    border: 1px solid var(--border);
    background: var(--surface);
    box-shadow: var(--shadow-sm);
    padding: var(--space-5);
    display: grid;
    gap: var(--space-3);
    position: relative;
    overflow: hidden;

    /* subtle load animation */
    animation: enter var(--duration-fast) var(--ease-standard) both;
}

@keyframes enter {
    from {
        transform: translateY(4px);
        opacity: 0;
    }
    to {
        transform: translateY(0);
        opacity: 1;
    }
}

.card:hover {
    transform: translateY(-1px);
    box-shadow: var(--shadow-md);
    transition: transform var(--duration-fast) var(--ease-standard),
        box-shadow var(--duration-fast) var(--ease-standard);
}

.top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-3);
}

.icon {
    width: 40px;
    height: 40px;
    border-radius: var(--radius-md);
    border: 1px solid var(--border);
    background: var(--surface-2);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    color: var(--color-muted);
    flex: 0 0 auto;
}

.label {
    min-width: 0;
    font-size: var(--text-sm);
    color: var(--color-muted);
    font-weight: 700;
    text-align: right;
}

.value {
    font-size: 1.9rem;
    font-weight: 800;
    letter-spacing: -0.02em;
}

/* Skeleton */
.card.loading {
    box-shadow: var(--shadow-sm);
}

.sk {
    display: inline-block;
    border-radius: 999px;
    background: color-mix(in srgb, var(--color-text) 10%, transparent);
    position: relative;
    overflow: hidden;
}

.sk::after {
    content: "";
    position: absolute;
    inset: 0;
    transform: translateX(-100%);
    background: linear-gradient(
        90deg,
        transparent,
        color-mix(in srgb, var(--color-text) 14%, transparent),
        transparent
    );
    animation: shimmer 1.1s var(--ease-standard) infinite;
}

@keyframes shimmer {
    100% {
        transform: translateX(100%);
    }
}

.sk--label {
    width: 120px;
    height: 12px;
}

.sk--value {
    width: 140px;
    height: 22px;
}

@media (prefers-reduced-motion: reduce) {
    .card {
        animation: none;
    }

    .card:hover {
        transition: none;
        transform: none;
    }

    .sk::after {
        animation: none;
    }
}
</style>
