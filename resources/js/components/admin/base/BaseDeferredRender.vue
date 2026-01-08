<template>
    <div ref="rootEl" class="admin-defer">
        <slot v-if="ready" />
        <slot v-else name="placeholder" />
    </div>
</template>

<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from "vue";

const props = defineProps({
    enabled: { type: Boolean, default: true },
    once: { type: Boolean, default: true },
    rootMargin: { type: String, default: "200px" },
});

const ready = ref(false);
const rootEl = ref(null);
let io = null;

function markReady() {
    ready.value = true;
    if (props.once) {
        io?.disconnect?.();
        io = null;
    }
}

function setupObserver() {
    if (!props.enabled) {
        ready.value = true;
        return;
    }

    if (typeof IntersectionObserver === "undefined") {
        ready.value = true;
        return;
    }

    if (!rootEl.value) return;

    io = new IntersectionObserver(
        (entries) => {
            const entry = entries[0];
            if (entry?.isIntersecting) markReady();
        },
        { root: null, rootMargin: props.rootMargin, threshold: 0.01 }
    );

    io.observe(rootEl.value);
}

onMounted(() => {
    setupObserver();
});

onBeforeUnmount(() => {
    io?.disconnect?.();
    io = null;
});

watch(
    () => props.enabled,
    () => {
        if (ready.value) return;
        io?.disconnect?.();
        io = null;
        setupObserver();
    }
);
</script>
