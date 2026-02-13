<script setup lang="ts">
import { ref } from "vue";
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

const openGroups = ref<{ [key: string]: boolean }>({});
function toggleGroup(key: string) {
    openGroups.value[key] = !openGroups.value[key];
}

const navGroups = [
    {
        label: "PAGES",
        items: [
            { name: "Dashboard", icon: "dashboard", to: { name: "dashboard" } },
            { name: "Reports", icon: "reports", to: { name: "reports" } },
            { name: "Companies", icon: "companies", to: { name: "companies" } },
            { name: "PBX Accounts", icon: "pbx", to: { name: "pbx-accounts" } },
            { name: "Usage", icon: "usage", to: { name: "usage" } },
            { name: "Account", icon: "account", to: { name: "account" } },
        ],
    },
];

const outlineLinks = [
    { icon: "x", label: "@keenthemes", href: "#" },
    { icon: "slack", label: "@keenthemes_hub", href: "#" },
    { icon: "figma", label: "metronic", href: "#" },
];

function onNavigate() {
    emit("navigate");
}
function isActive(name: string) {
    return route.name === name;
}
</script>

<template>
    <aside
        class="sidebar metronic-sidebar"
        :class="{ open: props.open, collapsed: props.collapsed }"
        aria-label="Sidebar navigation"
    >
        <!-- Brand/Logo -->
        <div class="sidebarHeader">
            <div class="brand">
                <div class="brandMark metronic-logo" aria-hidden="true"></div>
                <div class="brandText">
                    <div class="brandName">Metronic</div>
                </div>
            </div>
            <button
                class="collapseBtn"
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

        <!-- Add New/Search -->
        <div class="sidebarActions">
            <button class="addBtn" title="Add New">
                <AppIcon name="plus" />
                <span v-if="!props.collapsed">Add New</span>
            </button>
            <button class="iconBtn" title="Search">
                <AppIcon name="search" />
            </button>
        </div>

        <!-- Nav Groups -->
        <div class="navGroups">
            <template v-for="group in navGroups" :key="group.label">
                <div class="navSectionHeader">{{ group.label }}</div>
                <nav class="nav" aria-label="Primary">
                    <router-link
                        v-for="item in group.items"
                        :key="item.name"
                        class="navItem metronic-nav-item"
                        :class="{ active: isActive(item.to.name) }"
                        :to="item.to"
                        @click="onNavigate"
                        :title="props.collapsed ? item.name : undefined"
                    >
                        <span class="navIcon"
                            ><AppIcon :name="item.icon"
                        /></span>
                        <span class="navLabel">{{ item.name }}</span>
                        <span
                            class="activeBar"
                            v-if="isActive(item.to.name)"
                        ></span>
                    </router-link>
                </nav>
            </template>
        </div>

        <!-- Outline/Social -->
        <div class="navSectionHeader">OUTLINE</div>
        <div class="outlineLinks">
            <a
                v-for="link in outlineLinks"
                :key="link.label"
                :href="link.href"
                class="outlineLink"
                target="_blank"
            >
                <AppIcon :name="link.icon" />
                <span class="outlineLabel">{{ link.label }}</span>
            </a>
        </div>

        <!-- Footer -->
        <div class="sidebarFooter metronic-footer">
            <img
                class="avatar"
                src="https://randomuser.me/api/portraits/men/32.jpg"
                alt="User"
            />
            <div class="footerIcons">
                <button class="iconBtn"><AppIcon name="settings" /></button>
                <button class="iconBtn"><AppIcon name="logout" /></button>
            </div>
        </div>

        <div v-if="props.open" class="backdrop" @click="onNavigate"></div>
    </aside>
</template>

<style scoped>
.metronic-sidebar {
    position: sticky;
    top: 0;
    height: 100vh;
    width: 100%;
    max-width: 280px;
    background: linear-gradient(
        180deg,
        color-mix(in srgb, var(--surface) 94%, transparent) 0%,
        color-mix(in srgb, var(--surface-2) 80%, transparent) 100%
    );
    border-right: 1px solid var(--border);
    display: flex;
    flex-direction: column;
    transition:
        max-width 0.2s var(--ease-standard),
        box-shadow 0.2s;
    z-index: 20;
    overflow-y: auto;
    overflow-x: hidden;
    box-shadow: var(--shadow-sm);
    backdrop-filter: blur(14px);
}

.sidebarHeader {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 18px;
    border-bottom: 1px solid var(--border);
    flex: 0 0 auto;
}

.brand {
    display: flex;
    align-items: center;
    gap: var(--space-3);
    min-width: 0;
}

.brandMark {
    width: 40px;
    height: 40px;
    border-radius: 12px;
    flex: 0 0 auto;
    box-shadow: 0 10px 24px rgba(34, 211, 238, 0.25);
}

.metronic-logo {
    background: linear-gradient(135deg, #22d3ee 0%, #6366f1 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-weight: 700;
    font-size: 1.5rem;
}

.brandText {
    min-width: 0;
}

.brandName {
    font-weight: var(--weight-semibold);
    letter-spacing: var(--tracking-tight);
    font-size: var(--text-lg);
}

.collapseBtn {
    background: none;
    border: none;
    padding: 8px 10px;
    border-radius: var(--radius-sm);
    cursor: pointer;
    transition:
        background 0.15s,
        color 0.15s;
    color: var(--color-muted);
}

.collapseBtn:hover {
    background: var(--surface-2);
    color: var(--color-primary);
}

.collapseIcon {
    display: flex;
    align-items: center;
    justify-content: center;
    transition: transform 0.2s;
}

.collapseIcon.flipped {
    transform: rotate(180deg);
}

.sidebarActions {
    display: flex;
    align-items: center;
    gap: 8px;
    margin: 18px 0 10px 0;
    padding: 0 18px;
    flex: 0 0 auto;
}

.addBtn {
    display: flex;
    align-items: center;
    gap: 8px;
    background: linear-gradient(135deg, #2f6bff 0%, #00a3ff 100%);
    color: #fff;
    border: none;
    border-radius: var(--radius-md);
    padding: 10px 16px;
    font-weight: var(--weight-semibold);
    cursor: pointer;
    font-size: 1rem;
    transition:
        transform 0.15s,
        box-shadow 0.15s;
    flex: 1 1 auto;
    white-space: nowrap;
    box-shadow: 0 10px 20px rgba(47, 107, 255, 0.25);
}

.addBtn:hover {
    transform: translateY(-1px);
    box-shadow: 0 14px 26px rgba(47, 107, 255, 0.3);
}

.iconBtn {
    background: none;
    border: none;
    color: var(--color-muted);
    border-radius: var(--radius-md);
    padding: 8px;
    cursor: pointer;
    transition:
        background 0.15s,
        color 0.15s;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.2rem;
}

.iconBtn:hover {
    background: var(--surface-2);
    color: var(--color-primary);
}

.navGroups {
    flex: 1 1 auto;
    display: flex;
    flex-direction: column;
    gap: 0;
}

.navSectionHeader {
    font-size: 0.85rem;
    font-weight: var(--weight-semibold);
    color: var(--color-muted);
    letter-spacing: var(--tracking-wide);
    margin: 18px 0 6px 18px;
    text-transform: uppercase;
}

.nav {
    display: flex;
    flex-direction: column;
    gap: 4px;
    padding: 0 10px;
}

.navGroup {
    display: flex;
    flex-direction: column;
}

.groupBtn {
    width: 100%;
    justify-content: space-between;
    background: none !important;
    border: none !important;
    cursor: pointer;
}

.chevron {
    margin-left: auto;
    transition: transform 0.2s;
    display: flex;
    align-items: center;
    color: var(--color-muted);
    font-size: 1.2rem;
}

.chevron.open {
    transform: rotate(180deg);
}

.groupChildren {
    display: flex;
    flex-direction: column;
    gap: 2px;
    padding-left: 8px;
    margin-top: 2px;
}

.child {
    font-size: 0.97rem;
    padding: 10px 12px;
    margin-left: 8px;
    border-left: 2px solid transparent;
}

.child:hover {
    border-left-color: var(--color-primary);
}

.child.active {
    border-left-color: var(--color-primary);
}

.metronic-nav-item {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 12px 18px;
    border-radius: var(--radius-md);
    color: var(--color-muted);
    text-decoration: none;
    font-weight: var(--weight-medium);
    position: relative;
    transition:
        background 0.15s,
        color 0.15s;
    overflow: hidden;
}

.metronic-nav-item .navIcon {
    font-size: 1.2rem;
    color: var(--color-muted);
    transition: color 0.15s;
}

.metronic-nav-item:hover {
    background: color-mix(in srgb, var(--color-primary) 10%, var(--surface-2));
    color: var(--color-primary);
}

.metronic-nav-item:hover .navIcon {
    color: var(--color-primary);
}

.metronic-nav-item.active {
    background: color-mix(in srgb, var(--color-primary) 12%, var(--surface-2));
    color: var(--color-primary);
    font-weight: var(--weight-semibold);
}

.metronic-nav-item.active .navIcon {
    color: var(--color-primary);
}

.activeBar {
    position: absolute;
    left: 0;
    top: 10px;
    bottom: 10px;
    width: 4px;
    border-radius: 4px;
    background: var(--color-primary);
    transition: background 0.2s;
}

.navLabel {
    flex: 1 1 auto;
    font-size: var(--text-md);
    white-space: nowrap;
}

.outlineLinks {
    display: flex;
    flex-direction: column;
    gap: 6px;
    margin: 10px 0 0 0;
    padding: 0 18px;
    flex: 0 0 auto;
}

.outlineLink {
    display: flex;
    align-items: center;
    gap: 10px;
    color: var(--color-muted);
    text-decoration: none;
    font-size: var(--text-sm);
    border-radius: var(--radius-md);
    padding: 8px 12px;
    transition: color 0.15s;
}

.outlineLink:hover {
    background: var(--surface-2);
    color: var(--color-primary);
}

.outlineLabel {
    font-weight: var(--weight-medium);
}

.metronic-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    padding: 18px;
    border-top: 1px solid var(--border);
    margin-top: auto;
    flex: 0 0 auto;
}

.avatar {
    width: 38px;
    height: 38px;
    border-radius: 50%;
    object-fit: cover;
    border: 2px solid var(--color-border);
    box-shadow: var(--shadow-xs);
}

.footerIcons {
    display: flex;
    gap: 8px;
}

@media (max-width: 960px) {
    .metronic-sidebar {
        position: fixed;
        left: 0;
        top: 0;
        width: 80vw;
        max-width: 280px;
        min-width: 64px;
        height: 100vh;
        transform: translateX(-102%);
        transition: transform 0.2s var(--ease-standard);
        box-shadow: var(--shadow-lg);
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

@media (max-width: 600px) {
    .metronic-sidebar {
        width: 100vw;
        max-width: 100vw;
    }
}

.sidebar.collapsed {
    max-width: 80px;
}

.sidebar.collapsed .brandText,
.sidebar.collapsed .navLabel,
.sidebar.collapsed .sidebarActions span,
.sidebar.collapsed .outlineLabel {
    display: none;
}

.sidebar.collapsed .metronic-nav-item,
.sidebar.collapsed .outlineLink {
    justify-content: center;
    padding: 12px;
}

.sidebar.collapsed .navIcon {
    margin: 0;
}

.sidebar.collapsed .chevron {
    display: none;
}

:root[data-theme="dark"] .metronic-sidebar {
    background: var(--color-surface);
    border-right: 1px solid var(--color-border);
}

:root[data-theme="dark"] .metronic-nav-item:hover,
:root[data-theme="dark"] .metronic-nav-item.active,
:root[data-theme="dark"] .outlineLink:hover {
    background: rgba(255, 255, 255, 0.06);
}

@media (prefers-reduced-motion: reduce) {
    .metronic-sidebar,
    .collapseIcon,
    .addBtn {
        transition: none;
    }
}
</style>
