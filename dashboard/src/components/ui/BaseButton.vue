<script setup lang="ts">
type Props = {
    size?: "sm" | "md" | "lg";
    type?: "button" | "submit" | "reset";
    disabled?: boolean;
};

const props = withDefaults(defineProps<Props>(), {
    size: "md",
    type: "button",
    disabled: false,
});

defineEmits<{ (e: "click", evt: MouseEvent): void }>();
</script>

<template>
    <button
        :type="props.type"
        :disabled="props.disabled"
        class="inline-flex items-center justify-center rounded font-medium transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
        :class="{
            'px-2 py-1 text-xs': props.size === 'sm',
            'px-3 py-1.5 text-sm': props.size === 'md',
            'px-4 py-2 text-base': props.size === 'lg',
        }"
        @click="$emit('click', $event)"
    >
        <slot />
    </button>
</template>
