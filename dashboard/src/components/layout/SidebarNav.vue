<script setup lang="ts">
import { useRoute } from "vue-router";
import AppIcon from "../icons/AppIcon.vue";

type Props = {
    open: boolean;
    collapsed: boolean;
};

const props = defineProps<Props>();
const emit = defineEmits<{
    (e: "navigate"): void;
    (e: "toggle-collapsed"): void;
}>();

const route = useRoute();

const navItems = [
    { name: "Dashboard", icon: "dashboard", to: { name: "dashboard" } },
    { name: "Reports", icon: "reports", to: { name: "reports" } },
    { name: "Usage", icon: "usage", to: { name: "usage" } },
    { name: "Account", icon: "account", to: { name: "account" } },
] as const;

function onNavigate() {
    emit("navigate");
}

function isActive(name: string) {
    if (name === "reports") return route.name === "reports";
    return route.name === name;
}
</script>

<template>
    <aside
        class="sidebar"
        :class="{ open, collapsed: props.collapsed }"
        aria-label="Sidebar navigation"
    >
        <div class="sidebarHeader">
            <div class="brand">
                <div class="brandMark" aria-hidden="true"></div>
                <div class="brandText">
                    <div class="brandName">BlueHub</div>
                    <div class="brandSub">Reporting</div>
                </div>
            </div>

            <button
                class="btn btn--ghost collapseBtn"
                type="button"
                :aria-label="
                    props.collapsed ? 'Expand sidebar' : 'Collapse sidebar'
                "
                @click="$emit('toggle-collapsed')"
            >
                <span
                    class="collapseIcon"
                    :class="{ flipped: !props.collapsed }"
                    aria-hidden="true"
                >
                    <AppIcon name="collapse" />
                </span>
            </button>
        </div>

        <nav class="nav" aria-label="Primary">
            <router-link
                v-for="item in navItems"
                :key="item.name"
                class="navItem"
                :class="{ active: isActive((item.to as any).name) }"
                :to="item.to"
                @click="onNavigate"
                :title="props.collapsed ? item.name : undefined"
            >
                <span class="navIcon" aria-hidden="true">
                    <AppIcon :name="item.icon" />
                </span>
                <span class="navLabel">{{ item.name }}</span>
            </router-link>
        </nav>

        <div class="sidebarFooter">
            <div class="hint">UI-only demo layout</div>
        </div>

        <div v-if="open" class="backdrop" @click="onNavigate"></div>
    </aside>
</template>

<style scoped>
.sidebar {
    position: sticky;
    top: 0;
    height: 100vh;
    padding: var(--space-6);
    border-right: 1px solid var(--border);
    background: var(--surface);
}

.sidebarHeader {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: var(--space-6);
}

.brand {
    display: flex;
    align-items: center;
    gap: var(--space-3);
    min-width: 0;
}

.brandMark {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    border: 1px solid var(--border);
    background: var(--surface-2);
    flex: 0 0 auto;
}

.brandText {
    min-width: 0;
}

.brandName {
    font-weight: 700;
    letter-spacing: 0.2px;
}

.brandSub {
    opacity: 0.7;
    font-size: 0.9rem;
}

.nav {
    display: grid;
    gap: var(--space-2);
}

.navItem {
    display: grid;
    grid-template-columns: 20px 1fr;
    align-items: center;
    gap: 12px;
    padding: 10px 12px;
    border-radius: 10px;
    border: 1px solid transparent;
    color: inherit;
    text-decoration: none;
    position: relative;
}

.navItem:hover {
    border-color: var(--border);
    text-decoration: none;
}

.navItem.active {
    background: var(--surface-2);
    border-color: var(--border);
}

.navItem.active::before {
    content: "";
    position: absolute;
    left: -6px;
    top: 8px;
    bottom: 8px;
    width: 3px;
    border-radius: 999px;
    background: var(--color-primary);
}

.navIcon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    color: var(--color-muted);
}

.navItem.active .navIcon {
    color: var(--color-primary);
}

.navLabel {
    font-weight: 650;
}

.collapseBtn {
    padding: 8px 10px;
}

.collapseIcon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
}

.collapseIcon.flipped {
    transform: rotate(180deg);
}

.sidebar.collapsed {
    padding: var(--space-5);
}

.sidebar.collapsed .brandText {
    display: none;
}

.sidebar.collapsed .navItem {
    grid-template-columns: 20px;
    justify-content: center;
}

.sidebar.collapsed .navLabel {
    display: none;
}

.sidebarFooter {
    margin-top: var(--space-8);
    opacity: 0.7;
    font-size: 0.9rem;
}

.backdrop {
    display: none;
}

@media (max-width: 960px) {
    .sidebar {
        position: fixed;
        left: 0;
        top: 0;
        z-index: 20;
        width: 280px;
        transform: translateX(-102%);
        transition: transform 200ms ease;
        box-shadow: none;
    }

    .sidebar.collapsed {
        padding: var(--space-6);
    }

    .sidebar.collapsed .brandText,
    .sidebar.collapsed .navLabel {
        display: block;
    }

    .sidebar.collapsed .navItem {
        grid-template-columns: 20px 1fr;
        justify-content: start;
    }

    .sidebar.open {
        transform: translateX(0);
    }

    .backdrop {
        display: block;
        position: fixed;
        inset: 0;
        z-index: 10;
        background: rgba(0, 0, 0, 0.4);
    }
}
</style>
