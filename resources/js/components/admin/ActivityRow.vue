<template>
    <div class="admin-activityRow">
        <div class="admin-activityRow__main">
            <div class="admin-activityRow__title">{{ title }}</div>
            <div class="admin-activityRow__sub">{{ sub }}</div>
        </div>

        <div class="admin-activityRow__right">
            <StatusBadge :variant="statusVariant">{{
                statusLabel
            }}</StatusBadge>
            <div class="admin-activityRow__time">{{ time }}</div>
        </div>
    </div>
</template>

<script setup>
import StatusBadge from "./StatusBadge.vue";

defineProps({
    title: { type: String, required: true },
    sub: { type: String, required: true },
    statusLabel: { type: String, required: true },
    statusVariant: {
        type: String,
        required: true,
        validator: (v) => ["active", "processing", "failed"].includes(v),
    },
    time: { type: String, required: true },
});
</script>
