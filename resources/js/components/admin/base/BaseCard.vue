<template>
    <component :is="as" class="admin-card" :class="cardClass">
        <header v-if="hasHeader" class="admin-card__header">
            <div class="admin-card__headerMain">
                <h3 v-if="title" class="admin-card__title">{{ title }}</h3>
                <slot v-else name="title" />

                <p v-if="description" class="admin-card__desc">
                    {{ description }}
                </p>
                <slot v-else name="description" />
            </div>

            <div v-if="$slots.actions" class="admin-card__actions">
                <slot name="actions" />
            </div>
        </header>

        <div
            class="admin-card__body"
            :class="{ 'admin-card__body--flush': flush }"
        >
            <slot />
        </div>

        <footer v-if="$slots.footer" class="admin-card__footer">
            <slot name="footer" />
        </footer>
    </component>
</template>

<script setup>
import { computed, useSlots } from "vue";

const slots = useSlots();

const props = defineProps({
    as: { type: String, default: "section" },

    title: { type: String, default: "" },
    description: { type: String, default: "" },

    variant: {
        type: String,
        default: "surface",
        validator: (v) => ["surface", "glass", "faint"].includes(v),
    },

    hover: { type: Boolean, default: false },
    flush: { type: Boolean, default: false },
});

const hasHeader = computed(() => {
    return (
        Boolean(props.title) ||
        Boolean(props.description) ||
        Boolean(slots.title) ||
        Boolean(slots.description) ||
        Boolean(slots.actions)
    );
});

const cardClass = computed(() => {
    return {
        "admin-card--glass": props.variant === "glass",
        "admin-card--faint": props.variant === "faint",
        "admin-card--hover": props.hover,
    };
});
</script>
