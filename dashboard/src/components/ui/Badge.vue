<script setup lang="ts">
type Props = {
    tone?:
        | "neutral"
        | "success"
        | "warning"
        | "danger"
        | "processing"
        | "completed"
        | "failed";
};

const props = withDefaults(defineProps<Props>(), {
    tone: "neutral",
});
</script>

<template>
    <span
        class="badge"
        :class="{
            'badge--processing':
                props.tone === 'processing' || props.tone === 'warning',
            'badge--completed':
                props.tone === 'completed' || props.tone === 'success',
            'badge--failed': props.tone === 'failed' || props.tone === 'danger',
        }"
        :data-tone="props.tone"
    >
        <slot />
    </span>
</template>

<style scoped>
.badge {
    /* Use global badge tokens/classes; keep this block minimal */
}
</style>
