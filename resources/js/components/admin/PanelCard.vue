<template>
    <section class="admin-panel">
        <header class="admin-panel__header">
            <h2 class="admin-panel__title">{{ title }}</h2>
            <span v-if="meta" class="admin-panel__meta">{{ meta }}</span>
        </header>

        <slot />
    </section>
</template>

<script setup>
defineProps({
    title: { type: String, required: true },
    meta: { type: String, default: "" },
});
</script>
