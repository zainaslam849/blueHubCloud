<script setup lang="ts">
import { useRoute } from "vue-router";

const route = useRoute();

const tabs = [
    { name: "dashboard", label: "Dashboard" },
    { name: "calls", label: "Calls" },
    { name: "reports", label: "Reports" },
    { name: "account", label: "Account" },
];

function isActive(name: string) {
    return route.name === name;
}
</script>

<template>
    <nav class="mobileTabs" aria-label="Primary">
        <RouterLink
            v-for="tab in tabs"
            :key="tab.name"
            :to="{ name: tab.name }"
            class="mobileTab"
            :class="{ active: isActive(tab.name) }"
        >
            <svg v-if="tab.name === 'dashboard'" viewBox="0 0 24 24" fill="none"><rect x="3" y="3" width="7" height="7" rx="1.6" stroke="currentColor" stroke-width="1.8"/><rect x="14" y="3" width="7" height="7" rx="1.6" stroke="currentColor" stroke-width="1.8"/><rect x="3" y="14" width="7" height="7" rx="1.6" stroke="currentColor" stroke-width="1.8"/><rect x="14" y="14" width="7" height="7" rx="1.6" stroke="currentColor" stroke-width="1.8"/></svg>
            <svg v-else-if="tab.name === 'calls'" viewBox="0 0 24 24" fill="none"><path d="M5 4h4l2 5-2.5 1.5a12 12 0 0 0 5 5L15 13l5 2v4a1.6 1.6 0 0 1-1.8 1.6A16.5 16.5 0 0 1 3.4 5.8 1.6 1.6 0 0 1 5 4Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg>
            <svg v-else-if="tab.name === 'reports'" viewBox="0 0 24 24" fill="none"><path d="M5 20V10m4.7 10V4m4.6 16v-7m4.7 7V8" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>
            <svg v-else viewBox="0 0 24 24" fill="none"><circle cx="12" cy="8.4" r="3.6" stroke="currentColor" stroke-width="1.7"/><path d="M4.8 20a7.2 7.2 0 0 1 14.4 0" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>
            <span>{{ tab.label }}</span>
        </RouterLink>
    </nav>
</template>

<style scoped>
.mobileTabs {
    display: none;
}

@media (max-width: 720px) {
    .mobileTabs {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        align-items: center;
        position: fixed;
        left: 0;
        right: 0;
        bottom: 0;
        height: 62px;
        padding-bottom: env(safe-area-inset-bottom, 0);
        background: var(--color-surface);
        border-top: 1px solid var(--color-border);
        z-index: 25;
    }
}

.mobileTab {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 3px;
    height: 100%;
    color: var(--color-muted);
    text-decoration: none;
}

.mobileTab:hover { text-decoration: none; }

.mobileTab svg { width: 20px; height: 20px; }

.mobileTab span { font-size: 0.62rem; font-weight: 500; }

.mobileTab.active { color: var(--color-primary); }
</style>
