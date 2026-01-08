<template>
    <div
        v-if="variant === 'text'"
        class="admin-skeletonLines"
        role="status"
        :aria-label="ariaLabel"
    >
        <div
            v-for="i in lines"
            :key="i"
            class="admin-skeleton admin-skeleton--line"
        />
    </div>

    <div
        v-else-if="variant === 'circle'"
        class="admin-skeleton admin-skeleton--circle"
        role="status"
        :aria-label="ariaLabel"
    />

    <div
        v-else
        class="admin-skeleton admin-skeleton--rect"
        role="status"
        :aria-label="ariaLabel"
        :class="rectClass"
    />
</template>

<script setup>
import { computed } from "vue";

const props = defineProps({
    variant: {
        type: String,
        default: "rect",
        validator: (v) => ["rect", "text", "circle"].includes(v),
    },

    lines: { type: Number, default: 3 },

    size: {
        type: String,
        default: "md",
        validator: (v) => ["sm", "md", "lg"].includes(v),
    },

    ariaLabel: { type: String, default: "Loading" },
});

const rectClass = computed(() => {
    return {
        "admin-skeleton--sm": props.size === "sm",
        "admin-skeleton--lg": props.size === "lg",
    };
});
</script>
