<template>
    <span class="admin-badge" :class="badgeClass">
        <slot />
    </span>
</template>

<script setup>
import { computed } from "vue";

const props = defineProps({
    variant: {
        type: String,
        default: "neutral",
        validator: (v) =>
            ["neutral", "active", "processing", "failed", "info"].includes(v),
    },
});

const badgeClass = computed(() => {
    return {
        "admin-badge--neutral": props.variant === "neutral",
        "admin-badge--active": props.variant === "active",
        "admin-badge--processing": props.variant === "processing",
        "admin-badge--failed": props.variant === "failed",
        "admin-badge--info": props.variant === "info",
    };
});
</script>
