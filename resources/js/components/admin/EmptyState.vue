<template>
    <div class="admin-empty" role="status">
        <div class="admin-empty__title">{{ title }}</div>
        <div v-if="description" class="admin-empty__desc">
            {{ description }}
        </div>
    </div>
</template>

<script setup>
defineProps({
    title: { type: String, required: true },
    description: { type: String, default: "" },
});
</script>
