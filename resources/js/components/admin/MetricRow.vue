<template>
    <div class="admin-metricRow">
        <div class="admin-metricRow__label">{{ label }}</div>
        <div class="admin-metricRow__value">{{ value }}</div>
        <StatusBadge :variant="statusVariant">{{ statusLabel }}</StatusBadge>
    </div>
</template>

<script setup>
import StatusBadge from "./StatusBadge.vue";

defineProps({
    label: { type: String, required: true },
    value: { type: String, required: true },
    statusLabel: { type: String, required: true },
    statusVariant: {
        type: String,
        required: true,
        validator: (v) => ["active", "processing", "failed"].includes(v),
    },
});
</script>
