<template>
    <span class="admin-badge" :class="variantClass">
        <slot />
    </span>
</template>

<script setup>
import { computed } from "vue";

const props = defineProps({
    variant: {
        type: String,
        required: true,
        validator: (v) => ["active", "processing", "failed"].includes(v),
    },
});

const variantClass = computed(() => {
    if (props.variant === "active") return "admin-badge--active";
    if (props.variant === "processing") return "admin-badge--processing";
    return "admin-badge--failed";
});
</script>
