<script setup lang="ts">
import { computed } from "vue";
import { safeUrl } from "../../utils/safeUrl";

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

const safePdfUrl = computed(() => safeUrl(props.pdfUrl));
const safeCsvUrl = computed(() => safeUrl(props.csvUrl));

function onDisabledClick(e: MouseEvent) {
    e.preventDefault();
}
</script>

<template>
    <div class="downloads">
        <a
            class="btn btn--secondary"
            :class="{ disabled: loading || !safePdfUrl }"
            :href="safePdfUrl"
            target="_blank"
            rel="noopener noreferrer"
            :aria-disabled="loading || !safePdfUrl"
            @click="loading || !safePdfUrl ? onDisabledClick($event) : undefined"
        >
            {{ loading ? "Loading…" : "Open PDF" }}
        </a>

        <a
            class="btn btn--secondary"
            :class="{ disabled: loading || !safeCsvUrl }"
            :href="safeCsvUrl"
            target="_blank"
            rel="noopener noreferrer"
            :aria-disabled="loading || !safeCsvUrl"
            @click="loading || !safeCsvUrl ? onDisabledClick($event) : undefined"
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
