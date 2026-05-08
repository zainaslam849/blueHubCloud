<script setup lang="ts">
// Minimal pass-through wrapper. CategoriesView relies on Tailwind utility
// classes passed via `class`, so we just render a span and forward slot
// content. Keeps the component tree typed without imposing additional
// behaviour.
type Props = { tone?: string };
defineProps<Props>();
</script>

<template>
    <span class="inline-flex items-center rounded px-2 py-0.5 text-xs font-medium">
        <slot />
    </span>
</template>
