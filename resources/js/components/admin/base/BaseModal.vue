<template>
    <Teleport to="body">
        <Transition name="admin-modal">
            <div
                v-if="modelValue"
                class="admin-modalOverlay"
                role="presentation"
                @click="onOverlayClick"
            >
                <div
                    ref="panelEl"
                    class="admin-modal"
                    role="dialog"
                    aria-modal="true"
                    :aria-labelledby="title ? titleId : undefined"
                    :aria-describedby="descriptionId || undefined"
                    tabindex="-1"
                    @keydown="onKeydown"
                >
                    <header class="admin-modal__header">
                        <h2
                            v-if="title"
                            class="admin-modal__title"
                            :id="titleId"
                        >
                            {{ title }}
                        </h2>
                        <slot v-else name="title" />

                        <button
                            type="button"
                            class="admin-modal__close"
                            aria-label="Close"
                            title="Close"
                            @click="close"
                        >
                            <span class="admin-icon" aria-hidden="true">
                                <svg
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    xmlns="http://www.w3.org/2000/svg"
                                >
                                    <path
                                        d="M7 7l10 10"
                                        stroke="currentColor"
                                        stroke-width="1.8"
                                        stroke-linecap="round"
                                    />
                                    <path
                                        d="M17 7L7 17"
                                        stroke="currentColor"
                                        stroke-width="1.8"
                                        stroke-linecap="round"
                                    />
                                </svg>
                            </span>
                        </button>
                    </header>

                    <div
                        class="admin-modal__body"
                        :id="descriptionId || undefined"
                    >
                        <slot />
                    </div>

                    <footer v-if="$slots.footer" class="admin-modal__footer">
                        <slot name="footer" />
                    </footer>

                    <span
                        class="admin-modal__focusSentinel"
                        tabindex="0"
                        @focus="focusFirst"
                    />
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

<script setup>
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from "vue";

const props = defineProps({
    modelValue: { type: Boolean, required: true },

    title: { type: String, default: "" },
    descriptionId: { type: String, default: "" },

    closeOnOverlay: { type: Boolean, default: true },
    closeOnEsc: { type: Boolean, default: true },
});

const emit = defineEmits(["update:modelValue", "close"]);

const panelEl = ref(null);
let lastActive = null;

const titleId = computed(() => {
    return `admin-modal-title-${Math.random().toString(16).slice(2)}`;
});

function close() {
    emit("update:modelValue", false);
    emit("close");
}

function onOverlayClick(e) {
    if (!props.closeOnOverlay) return;
    if (e.target !== e.currentTarget) return;
    close();
}

function getFocusable() {
    const root = panelEl.value;
    if (!root) return [];

    return Array.from(
        root.querySelectorAll(
            'a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])'
        )
    );
}

function focusFirst() {
    const focusables = getFocusable();
    const first = focusables.find((el) => el !== panelEl.value);
    (first || panelEl.value)?.focus?.();
}

function focusLast() {
    const focusables = getFocusable();
    const last = focusables[focusables.length - 1];
    (last || panelEl.value)?.focus?.();
}

function onKeydown(e) {
    if (props.closeOnEsc && e.key === "Escape") {
        e.preventDefault();
        close();
        return;
    }

    if (e.key !== "Tab") return;

    const focusables = getFocusable();
    if (focusables.length === 0) {
        e.preventDefault();
        return;
    }

    const first = focusables[0];
    const last = focusables[focusables.length - 1];

    if (e.shiftKey && document.activeElement === first) {
        e.preventDefault();
        last.focus();
        return;
    }

    if (!e.shiftKey && document.activeElement === last) {
        e.preventDefault();
        first.focus();
    }
}

watch(
    () => props.modelValue,
    async (open) => {
        if (open) {
            lastActive = document.activeElement;
            await nextTick();
            panelEl.value?.focus?.();
            focusFirst();
        } else {
            lastActive?.focus?.();
            lastActive = null;
        }
    }
);

onMounted(() => {
    // noop: reserved for future body scroll lock if needed
});

onBeforeUnmount(() => {
    // ensure focus restored when unmounted
    lastActive?.focus?.();
});
</script>
