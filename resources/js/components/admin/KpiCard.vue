<template>
    <article class="admin-kpi">
        <div class="admin-kpi__top">
            <div class="admin-kpi__icon" aria-hidden="true">
                <svg
                    v-if="icon === 'calls'"
                    viewBox="0 0 24 24"
                    fill="none"
                    xmlns="http://www.w3.org/2000/svg"
                >
                    <path
                        d="M7.5 10.2c1.4 2.7 3.6 4.9 6.3 6.3l2.1-2.1c.4-.4 1-.5 1.5-.3 1 .3 2.1.5 3.2.5.8 0 1.4.6 1.4 1.4V20c0 .8-.6 1.4-1.4 1.4C10.6 21.4 2.6 13.4 2.6 3.4 2.6 2.6 3.2 2 4 2h3.9c.8 0 1.4.6 1.4 1.4 0 1.1.2 2.2.5 3.2.2.5.1 1.1-.3 1.5L7.5 10.2Z"
                        stroke="currentColor"
                        stroke-width="1.8"
                        stroke-linejoin="round"
                    />
                </svg>
                <svg
                    v-else-if="icon === 'jobs'"
                    viewBox="0 0 24 24"
                    fill="none"
                    xmlns="http://www.w3.org/2000/svg"
                >
                    <path
                        d="M8 7V6a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v1"
                        stroke="currentColor"
                        stroke-width="1.8"
                        stroke-linecap="round"
                    />
                    <path
                        d="M6 7h12a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V9a2 2 0 0 1 2-2Z"
                        stroke="currentColor"
                        stroke-width="1.8"
                    />
                    <path
                        d="M4 12h16"
                        stroke="currentColor"
                        stroke-width="1.8"
                        stroke-linecap="round"
                    />
                </svg>
                <svg
                    v-else
                    viewBox="0 0 24 24"
                    fill="none"
                    xmlns="http://www.w3.org/2000/svg"
                >
                    <path
                        d="M16 20v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2"
                        stroke="currentColor"
                        stroke-width="1.8"
                        stroke-linecap="round"
                    />
                    <path
                        d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z"
                        stroke="currentColor"
                        stroke-width="1.8"
                    />
                    <path
                        d="M21 20v-2a4 4 0 0 0-3-3.87"
                        stroke="currentColor"
                        stroke-width="1.8"
                        stroke-linecap="round"
                    />
                    <path
                        d="M18 8a4 4 0 0 1 0 8"
                        stroke="currentColor"
                        stroke-width="1.8"
                        stroke-linecap="round"
                    />
                </svg>
            </div>

            <div class="admin-kpi__meta">
                <div class="admin-kpi__label">{{ label }}</div>
                <div class="admin-kpi__value">{{ value }}</div>
            </div>
        </div>

        <div class="admin-kpi__bottom">
            <span class="admin-kpi__hint">{{ hint }}</span>
            <StatusBadge class="admin-kpi__badge" :variant="badgeVariant">
                {{ badgeLabel }}
            </StatusBadge>
        </div>
    </article>
</template>

<script setup>
import StatusBadge from "./StatusBadge.vue";

defineProps({
    label: { type: String, required: true },
    value: { type: String, required: true },
    hint: { type: String, required: true },
    badgeLabel: { type: String, required: true },
    badgeVariant: {
        type: String,
        required: true,
        validator: (v) => ["active", "processing", "failed"].includes(v),
    },
    icon: {
        type: String,
        required: true,
        validator: (v) => ["calls", "jobs", "users"].includes(v),
    },
});
</script>
