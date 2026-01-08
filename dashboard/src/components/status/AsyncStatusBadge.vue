<script setup lang="ts">
import { computed } from "vue";

type AsyncStatus = "processing" | "ready" | "failed" | "queued";

type Props = {
    status: AsyncStatus;
    tooltip?: string;
    size?: "sm" | "md";
};

const props = withDefaults(defineProps<Props>(), {
    size: "sm",
});

const ui = computed(() => {
    switch (props.status) {
        case "ready":
            return {
                label: "Ready",
                badgeClass: "badge--ready",
                tooltip:
                    props.tooltip ??
                    "Completed successfully. Downloads and actions are available.",
            };
        case "failed":
            return {
                label: "Failed",
                badgeClass: "badge--failed",
                tooltip:
                    props.tooltip ??
                    "Failed to complete. Retry generation or contact support.",
            };
        case "queued":
            return {
                label: "Queued",
                badgeClass: "badge--queued",
                tooltip: props.tooltip ?? "Waiting for processing to start.",
            };
        default:
            return {
                label: "Processing",
                badgeClass: "badge--processing",
                tooltip:
                    props.tooltip ??
                    "In progress. Exports will unlock when processing finishes.",
            };
    }
});
</script>

<template>
    <span
        class="badge status"
        :class="[
            ui.badgeClass,
            props.size === 'md' ? 'status--md' : 'status--sm',
        ]"
        :data-tooltip="ui.tooltip"
        :title="ui.tooltip"
        role="status"
        aria-live="polite"
    >
        <Transition name="statusFade" mode="out-in">
            <span class="inner" :key="props.status">
                <span class="icon" aria-hidden="true">
                    <span
                        v-if="props.status === 'processing'"
                        class="spinner"
                    ></span>

                    <svg
                        v-else-if="props.status === 'ready'"
                        viewBox="0 0 24 24"
                        width="16"
                        height="16"
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
                        v-else-if="props.status === 'failed'"
                        viewBox="0 0 24 24"
                        width="16"
                        height="16"
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

                    <svg
                        v-else
                        viewBox="0 0 24 24"
                        width="16"
                        height="16"
                        fill="none"
                        xmlns="http://www.w3.org/2000/svg"
                    >
                        <path
                            d="M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20Z"
                            stroke="currentColor"
                            stroke-width="1.8"
                        />
                        <path
                            d="M12 7v5l3 2"
                            stroke="currentColor"
                            stroke-width="1.8"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        />
                    </svg>
                </span>

                <span class="label">{{ ui.label }}</span>
            </span>
        </Transition>
    </span>
</template>

<style scoped>
.status {
    position: relative;
    gap: 8px;
    user-select: none;
    transition: background var(--duration-fast) var(--ease-standard),
        border-color var(--duration-fast) var(--ease-standard),
        box-shadow var(--duration-fast) var(--ease-standard),
        transform var(--duration-fast) var(--ease-standard);
}

.status:hover {
    transform: translateY(-1px);
    box-shadow: var(--shadow-md);
}

.status--sm {
    padding: 4px 10px;
}

.status--md {
    padding: 6px 12px;
    font-size: var(--text-sm);
}

.inner {
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.icon {
    width: 16px;
    height: 16px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}

.label {
    white-space: nowrap;
}

/* Spinner */
.spinner {
    width: 14px;
    height: 14px;
    border-radius: 999px;
    border: 2px solid color-mix(in srgb, currentColor 25%, transparent);
    border-top-color: currentColor;
    animation: spin 900ms linear infinite;
}

@keyframes spin {
    to {
        transform: rotate(360deg);
    }
}

/* Tooltip (CSS-only) */
.status::after {
    content: attr(data-tooltip);
    position: absolute;
    left: 50%;
    bottom: calc(100% + 10px);
    transform: translateX(-50%);
    width: max-content;
    max-width: min(320px, 70vw);
    padding: 10px 10px;
    border-radius: var(--radius-md);
    border: 1px solid var(--border);
    background: var(--surface);
    color: var(--color-text);
    box-shadow: var(--shadow-lg);
    font-size: var(--text-sm);
    font-weight: 650;
    line-height: var(--leading-normal);
    opacity: 0;
    pointer-events: none;
    white-space: normal;
    z-index: 30;
}

.status::before {
    content: "";
    position: absolute;
    left: 50%;
    bottom: calc(100% + 4px);
    transform: translateX(-50%);
    width: 10px;
    height: 10px;
    border-left: 1px solid var(--border);
    border-top: 1px solid var(--border);
    background: var(--surface);
    rotate: 45deg;
    opacity: 0;
    pointer-events: none;
    z-index: 29;
}

.status:hover::after,
.status:hover::before {
    opacity: 1;
}

/* Transition when status changes */
.statusFade-enter-active,
.statusFade-leave-active {
    transition: opacity var(--duration-fast) var(--ease-standard),
        transform var(--duration-fast) var(--ease-standard);
}

.statusFade-enter-from,
.statusFade-leave-to {
    opacity: 0;
    transform: translateY(2px);
}

/* Queued gets a subtle neutral tint (local-only) */
.badge--queued {
    background: color-mix(
        in srgb,
        var(--color-secondary) 8%,
        var(--color-surface)
    );
    border-color: color-mix(
        in srgb,
        var(--color-secondary) 18%,
        var(--color-border)
    );
}

@media (prefers-reduced-motion: reduce) {
    .status,
    .status:hover {
        transition: none;
        transform: none;
    }

    .spinner {
        animation: none;
    }

    .statusFade-enter-active,
    .statusFade-leave-active {
        transition: none;
    }
}
</style>
