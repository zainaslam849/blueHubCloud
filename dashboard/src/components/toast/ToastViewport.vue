<script setup lang="ts">
import { computed } from "vue";
import { useToasts } from "../../composables/useToasts";

const { toasts, remove } = useToasts();

const ariaLive = computed(() => {
    // If any error toast exists, promote to assertive.
    return toasts.value.some((t) => t.variant === "error")
        ? "assertive"
        : "polite";
});
</script>

<template>
    <div class="viewport" aria-label="Notifications" :aria-live="ariaLive">
        <TransitionGroup name="toast" tag="div" class="stack">
            <div
                v-for="t in toasts"
                :key="t.id"
                class="toast"
                :class="t.variant"
            >
                <div class="icon" aria-hidden="true">
                    <svg
                        v-if="t.variant === 'success'"
                        viewBox="0 0 24 24"
                        width="18"
                        height="18"
                        fill="none"
                        xmlns="http://www.w3.org/2000/svg"
                    >
                        <path
                            d="M20 7 10.5 16.5 4 10"
                            stroke="currentColor"
                            stroke-width="2"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        />
                    </svg>
                    <svg
                        v-else
                        viewBox="0 0 24 24"
                        width="18"
                        height="18"
                        fill="none"
                        xmlns="http://www.w3.org/2000/svg"
                    >
                        <path
                            d="M12 9v4"
                            stroke="currentColor"
                            stroke-width="2"
                            stroke-linecap="round"
                        />
                        <path
                            d="M12 17h.01"
                            stroke="currentColor"
                            stroke-width="3"
                            stroke-linecap="round"
                        />
                        <path
                            d="M10.2 4.7 2.7 18.2A2 2 0 0 0 4.4 21h15.2a2 2 0 0 0 1.7-2.8L13.8 4.7a2 2 0 0 0-3.6 0Z"
                            stroke="currentColor"
                            stroke-width="1.6"
                            stroke-linejoin="round"
                        />
                    </svg>
                </div>

                <div class="body">
                    <div class="title">{{ t.title }}</div>
                    <div v-if="t.message" class="message">{{ t.message }}</div>
                </div>

                <button
                    class="close btn btn--ghost"
                    type="button"
                    @click="remove(t.id)"
                >
                    Close
                </button>
            </div>
        </TransitionGroup>
    </div>
</template>

<style scoped>
.viewport {
    position: fixed;
    right: var(--space-6);
    bottom: var(--space-6);
    z-index: 50;
    pointer-events: none;
}

.stack {
    display: grid;
    gap: var(--space-3);
    width: min(360px, calc(100vw - 48px));
}

.toast {
    pointer-events: auto;
    display: grid;
    grid-template-columns: 18px 1fr auto;
    gap: var(--space-3);
    align-items: start;
    padding: var(--space-4);
    border-radius: var(--radius-lg);
    border: 1px solid var(--border);
    background: var(--surface);
    box-shadow: var(--shadow-lg);
}

.icon {
    width: 18px;
    height: 18px;
    margin-top: 2px;
    color: var(--color-muted);
}

.toast.success .icon {
    color: var(--color-success);
}

.toast.error .icon {
    color: var(--color-error);
}

.title {
    font-weight: 800;
    letter-spacing: 0.2px;
}

.message {
    margin-top: 4px;
    font-size: var(--text-sm);
    color: var(--color-muted);
}

.close {
    pointer-events: auto;
    padding: 6px 10px;
}

/* Animations */
.toast-enter-active,
.toast-leave-active {
    transition: transform var(--duration-fast) var(--ease-standard),
        opacity var(--duration-fast) var(--ease-standard);
}

.toast-enter-from,
.toast-leave-to {
    opacity: 0;
    transform: translateY(6px);
}

@media (max-width: 960px) {
    .viewport {
        right: var(--space-4);
        left: var(--space-4);
        bottom: var(--space-4);
    }

    .stack {
        width: 100%;
    }
}

@media (prefers-reduced-motion: reduce) {
    .toast-enter-active,
    .toast-leave-active {
        transition: none;
    }
}
</style>
