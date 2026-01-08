<template>
    <RouterLink
        v-if="to"
        class="admin-btn admin-btnBase"
        :class="btnClass"
        :to="to"
        :aria-busy="loading ? 'true' : 'false'"
        :aria-disabled="disabled || loading ? 'true' : 'false'"
        @click="onLinkClick"
    >
        <span v-if="loading" class="admin-btn__spinner" aria-hidden="true" />
        <span class="admin-btnBase__content"><slot /></span>
    </RouterLink>

    <a
        v-else-if="href"
        class="admin-btn admin-btnBase"
        :class="btnClass"
        :href="href"
        :target="target"
        :rel="rel"
        :aria-busy="loading ? 'true' : 'false'"
        :aria-disabled="disabled || loading ? 'true' : 'false'"
        @click="onLinkClick"
    >
        <span v-if="loading" class="admin-btn__spinner" aria-hidden="true" />
        <span class="admin-btnBase__content"><slot /></span>
    </a>

    <button
        v-else
        class="admin-btn admin-btnBase"
        :class="btnClass"
        :type="type"
        :disabled="disabled || loading"
        :aria-busy="loading ? 'true' : 'false'"
    >
        <span v-if="loading" class="admin-btn__spinner" aria-hidden="true" />
        <span class="admin-btnBase__content"><slot /></span>
    </button>
</template>

<script setup>
import { computed } from "vue";
import { RouterLink } from "vue-router";

const props = defineProps({
    to: { type: [String, Object], default: null },
    href: { type: String, default: "" },
    target: { type: String, default: "" },
    rel: { type: String, default: "" },

    type: { type: String, default: "button" },

    variant: {
        type: String,
        default: "primary",
        validator: (v) =>
            ["primary", "secondary", "ghost", "danger"].includes(v),
    },

    size: {
        type: String,
        default: "md",
        validator: (v) => ["sm", "md", "lg"].includes(v),
    },

    block: { type: Boolean, default: false },
    loading: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
});

const emit = defineEmits(["click"]);

function onLinkClick(e) {
    if (props.disabled || props.loading) {
        e.preventDefault();
        return;
    }

    emit("click", e);
}

const btnClass = computed(() => {
    return {
        "admin-btn--primary": props.variant === "primary",
        "admin-btn--secondary": props.variant === "secondary",
        "admin-btn--ghost": props.variant === "ghost",
        "admin-btn--danger": props.variant === "danger",

        "admin-btn--sm": props.size === "sm",
        "admin-btn--lg": props.size === "lg",

        "admin-btn--wide": props.block,
        "is-loading": props.loading,
    };
});
</script>
