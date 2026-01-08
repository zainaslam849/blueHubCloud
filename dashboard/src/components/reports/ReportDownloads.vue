<script setup lang="ts">
type Props = {
    pdfUrl?: string | null;
    csvUrl?: string | null;
    loading?: boolean;
};

const props = withDefaults(defineProps<Props>(), {
    pdfUrl: null,
    csvUrl: null,
    loading: false,
});

function onDisabledClick(e: MouseEvent) {
    e.preventDefault();
}
</script>

<template>
    <div class="downloads">
        <a
            class="btn btn--secondary"
            :class="{ disabled: loading || !pdfUrl }"
            :href="pdfUrl || undefined"
            target="_blank"
            rel="noopener"
            :aria-disabled="loading || !pdfUrl"
            @click="loading || !pdfUrl ? onDisabledClick($event) : undefined"
        >
            {{ loading ? "Loading…" : "Open PDF" }}
        </a>

        <a
            class="btn btn--secondary"
            :class="{ disabled: loading || !csvUrl }"
            :href="csvUrl || undefined"
            target="_blank"
            rel="noopener"
            :aria-disabled="loading || !csvUrl"
            @click="loading || !csvUrl ? onDisabledClick($event) : undefined"
        >
            {{ loading ? "Loading…" : "Open CSV" }}
        </a>
    </div>
</template>

<style scoped>
.downloads {
    display: grid;
    gap: 10px;
}

.disabled {
    opacity: 0.5;
    pointer-events: none;
}
</style>
