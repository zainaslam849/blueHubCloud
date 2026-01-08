<template>
    <div class="admin-dropdown" ref="rootEl">
        <button
            type="button"
            class="admin-dropdown__trigger"
            :aria-expanded="open ? 'true' : 'false'"
            aria-haspopup="menu"
            :disabled="disabled"
            @click="toggle"
            @keydown.down.prevent="openAndFocus"
            @keydown.esc.prevent="close"
        >
            <slot name="trigger">{{ label }}</slot>
            <span class="admin-icon" aria-hidden="true">
                <svg
                    viewBox="0 0 24 24"
                    fill="none"
                    xmlns="http://www.w3.org/2000/svg"
                >
                    <path
                        d="M7 10l5 5 5-5"
                        stroke="currentColor"
                        stroke-width="1.8"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    />
                </svg>
            </span>
        </button>

        <div
            v-if="open"
            class="admin-dropdown__panel"
            :class="panelClass"
            role="menu"
            @keydown.esc.prevent="close"
        >
            <slot>
                <button
                    v-for="item in items"
                    :key="item.key"
                    type="button"
                    class="admin-dropdown__item"
                    role="menuitem"
                    :disabled="Boolean(item.disabled)"
                    @click="select(item)"
                >
                    {{ item.label }}
                </button>
            </slot>
        </div>
    </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from "vue";

const props = defineProps({
    modelValue: { type: Boolean, default: undefined },

    label: { type: String, default: "Menu" },
    disabled: { type: Boolean, default: false },

    align: {
        type: String,
        default: "end",
        validator: (v) => ["start", "end"].includes(v),
    },

    items: { type: Array, default: () => [] },
});

const emit = defineEmits(["update:modelValue", "select", "open", "close"]);

const uncontrolledOpen = ref(false);
const rootEl = ref(null);

const open = computed(() => {
    if (typeof props.modelValue === "boolean") return props.modelValue;
    return uncontrolledOpen.value;
});

function setOpen(next) {
    if (typeof props.modelValue === "boolean") {
        emit("update:modelValue", next);
    } else {
        uncontrolledOpen.value = next;
    }

    emit(next ? "open" : "close");
}

function toggle() {
    if (props.disabled) return;
    setOpen(!open.value);
}

function close() {
    setOpen(false);
}

function openAndFocus() {
    if (props.disabled) return;
    setOpen(true);

    requestAnimationFrame(() => {
        const first = rootEl.value?.querySelector(
            ".admin-dropdown__panel .admin-dropdown__item:not(:disabled)"
        );
        first?.focus?.();
    });
}

function select(item) {
    if (item?.disabled) return;
    emit("select", item);
    close();
}

const panelClass = computed(() => {
    return {
        "admin-dropdown__panel--start": props.align === "start",
        "admin-dropdown__panel--end": props.align === "end",
    };
});

const onDocClick = (e) => {
    if (!rootEl.value) return;
    if (rootEl.value.contains(e.target)) return;
    close();
};

onMounted(() => {
    document.addEventListener("click", onDocClick);
});

onBeforeUnmount(() => {
    document.removeEventListener("click", onDocClick);
});

watch(
    () => props.disabled,
    (v) => {
        if (v) close();
    }
);
</script>
