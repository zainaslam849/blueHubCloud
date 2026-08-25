<script setup lang="ts">
type Crumb = { label: string; to?: Record<string, unknown> };

defineProps<{ items: Crumb[] }>();
</script>

<template>
    <nav class="crumb" aria-label="Breadcrumb">
        <template v-for="(item, i) in items" :key="i">
            <router-link v-if="item.to" :to="item.to" class="crumb__link">{{ item.label }}</router-link>
            <span v-else class="crumb__item" :class="{ 'crumb__item--current': i === items.length - 1 }">{{ item.label }}</span>
            <svg v-if="i < items.length - 1" viewBox="0 0 24 24" fill="none" class="crumb__sep"><path d="m9 6 6 6-6 6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
        </template>
    </nav>
</template>

<style scoped>
.crumb { display: flex; align-items: center; gap: 6px; font-size: 0.82rem; color: var(--color-muted); }
.crumb__link { color: var(--color-muted); text-decoration: none; }
.crumb__link:hover { color: var(--color-text); text-decoration: underline; }
.crumb__sep { width: 13px; height: 13px; flex-shrink: 0; }
.crumb__item--current { color: var(--color-text); font-weight: 600; }
</style>
