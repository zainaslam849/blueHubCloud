<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from "vue";
import { useRoute } from "vue-router";
import AppIcon from "../icons/AppIcon.vue";
import { auth } from "../../composables/useAuth";

type Props = {
    open: boolean;
    collapsed: boolean;
    logoUrl?: string;
    appName?: string;
};
const props = defineProps<Props>();
const emit = defineEmits<{
    (e: "navigate"): void;
    (e: "toggle-collapsed"): void;
    (e: "sign-out"): void;
}>();
const route = useRoute();

// ── Theme ──────────────────────────────────────────────
const THEME_KEY = "user_theme";

function getSystemTheme(): "light" | "dark" {
    return window.matchMedia?.("(prefers-color-scheme: dark)").matches ? "dark" : "light";
}

function getStored(): "light" | "dark" | "system" {
    try {
        const v = localStorage.getItem(THEME_KEY);
        if (v === "light" || v === "dark" || v === "system") return v;
    } catch { /* ignore */ }
    return "system";
}

function resolveTheme(pref: "light" | "dark" | "system"): "light" | "dark" {
    return pref === "system" ? getSystemTheme() : pref;
}

function applyTheme(resolved: "light" | "dark") {
    document.documentElement.dataset.theme = resolved;
}

const themePref = ref<"light" | "dark" | "system">(getStored());
const resolvedTheme = computed(() => resolveTheme(themePref.value));

function cycleTheme() {
    const next = themePref.value === "system" ? "light" : themePref.value === "light" ? "dark" : "system";
    themePref.value = next;
    try { localStorage.setItem(THEME_KEY, next); } catch { /* ignore */ }
    applyTheme(resolveTheme(next));
}

let mq: MediaQueryList | null = null;
let mqListener: (() => void) | null = null;

onMounted(() => {
    applyTheme(resolvedTheme.value);
    mq = window.matchMedia?.("(prefers-color-scheme: dark)") ?? null;
    if (mq) {
        mqListener = () => { if (themePref.value === "system") applyTheme(getSystemTheme()); };
        mq.addEventListener("change", mqListener);
    }
});

onUnmounted(() => {
    if (mq && mqListener) mq.removeEventListener("change", mqListener);
});

// ── Nav ────────────────────────────────────────────────
const openGroups = ref<{ [key: string]: boolean }>({});
void openGroups;

const isAdmin = computed(() =>
    auth.state.user?.role === "admin" || auth.state.user?.role === "super-admin",
);

const navGroups = computed(() => {
    if (isAdmin.value) {
        return [
            {
                label: "OVERVIEW",
                items: [{ name: "Reports", icon: "reports", to: { name: "reports" } }],
            },
            {
                label: "MANAGEMENT",
                items: [
                    { name: "Companies", icon: "companies", to: { name: "companies" } },
                    { name: "PBX Accounts", icon: "pbx", to: { name: "pbx-accounts" } },
                ],
            },
            {
                label: "SAAS",
                items: [
                    { name: "Users", icon: "account", to: { name: "admin-users" } },
                ],
            },
            {
                label: "TOOLS",
                items: [{ name: "AI Processing", icon: "dashboard", to: { name: "ai-processing" } }],
            },
        ];
    }

    return [
        {
            label: "MENU",
            items: [
                { name: "Dashboard", icon: "dashboard", to: { name: "dashboard" } },
                { name: "Calls", icon: "calls", to: { name: "calls" } },
                { name: "Transcriptions", icon: "generated", to: { name: "transcriptions" } },
                { name: "Weekly Call Reports", icon: "reports", to: { name: "reports" } },
            ],
        },
        {
            label: "ACCOUNT",
            items: [
                { name: "Account", icon: "account", to: { name: "account" } },
            ],
        },
    ];
});

function onNavigate() { emit("navigate"); }
function isActive(name: string | undefined) {
    if (!name) return false;
    return route.name === name;
}
</script>

<template>
    <aside
        class="sidebar"
        :class="{ open: props.open, collapsed: props.collapsed }"
        aria-label="Sidebar navigation"
    >
        <!-- Brand/Logo -->
        <div class="sidebarHeader">
            <div class="brand" :class="{ 'brand--centered': props.collapsed }">
                <!-- Logo image fills the header area when available -->
                <div v-if="props.logoUrl" class="logoWrap">
                    <img :src="props.logoUrl" :alt="props.appName ?? 'Logo'" class="logoImg" />
                </div>
                <!-- Fallback: gradient mark + name -->
                <template v-else>
                    <div class="brandMark" aria-hidden="true">
                        <span class="brandInitial">{{ (props.appName ?? 'B').charAt(0).toUpperCase() }}</span>
                    </div>
                    <span v-if="!props.collapsed" class="brandName">{{ props.appName ?? 'BlueHub' }}</span>
                </template>
            </div>

            <button
                class="collapseBtn"
                type="button"
                :aria-label="props.collapsed ? 'Expand sidebar' : 'Collapse sidebar'"
                @click="$emit('toggle-collapsed')"
            >
                <span class="collapseIcon" :class="{ flipped: !props.collapsed }" aria-hidden="true">
                    <AppIcon name="collapse" />
                </span>
            </button>
        </div>

        <!-- Nav Groups -->
        <div class="navGroups">
            <template v-for="group in navGroups" :key="group.label">
                <div v-if="!props.collapsed" class="navSectionHeader">{{ group.label }}</div>
                <nav class="nav" :aria-label="group.label">
                    <router-link
                        v-for="item in group.items"
                        :key="item.name"
                        class="navItem"
                        :class="{ active: isActive(item.to.name) }"
                        :to="item.to"
                        @click="onNavigate"
                        :title="props.collapsed ? item.name : undefined"
                    >
                        <span class="navIcon"><AppIcon :name="item.icon as any" /></span>
                        <span v-if="!props.collapsed" class="navLabel">{{ item.name }}</span>
                        <span v-if="isActive(item.to?.name)" class="activeBar"></span>
                    </router-link>
                </nav>
            </template>
        </div>

        <!-- Footer -->
        <div class="sidebarFooter">
            <div v-if="!props.collapsed" class="footerUser">
                <div class="footerName">{{ auth.state.user?.name ?? '—' }}</div>
                <div class="footerRole">{{ auth.state.user?.role ?? '' }}</div>
            </div>

            <div class="footerActions">
                <!-- Theme toggle -->
                <button class="iconBtn" :title="themePref === 'system' ? 'Theme: System' : themePref === 'dark' ? 'Theme: Dark' : 'Theme: Light'" @click="cycleTheme">
                    <!-- System -->
                    <svg v-if="themePref === 'system'" viewBox="0 0 24 24" fill="none"><rect x="2" y="3" width="20" height="14" rx="2" stroke="currentColor" stroke-width="1.8"/><path d="M8 21h8M12 17v4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                    <!-- Light (sun) -->
                    <svg v-else-if="resolvedTheme === 'dark'" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="4" stroke="currentColor" stroke-width="1.8"/><path d="M12 2v2M12 20v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M2 12h2M20 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                    <!-- Dark (moon) -->
                    <svg v-else viewBox="0 0 24 24" fill="none"><path d="M21 13.2A7.6 7.6 0 0 1 10.8 3a6.9 6.9 0 1 0 10.2 10.2Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>
                </button>

                <!-- Sign out -->
                <button class="iconBtn" title="Sign out" @click="$emit('sign-out')">
                    <AppIcon name="logout" />
                </button>
            </div>
        </div>

        <div v-if="props.open" class="backdrop" @click="onNavigate"></div>
    </aside>
</template>

<style scoped>
/* ── Shell ───────────────────────────────────────────── */
.sidebar {
    position: sticky;
    top: 0;
    height: 100vh;
    width: 260px;
    flex-shrink: 0;
    background: var(--color-surface);
    border-right: 1px solid var(--color-border);
    display: flex;
    flex-direction: column;
    transition: width 0.22s cubic-bezier(0.2, 0.8, 0.2, 1);
    z-index: 20;
    overflow: hidden;
}

.sidebar.collapsed { width: 72px; }

/* ── Header ──────────────────────────────────────────── */
.sidebarHeader {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 14px;
    height: 80px;
    border-bottom: 1px solid var(--color-border);
    flex-shrink: 0;
    gap: 8px;
}

.brand {
    display: flex;
    align-items: center;
    gap: 10px;
    min-width: 0;
    flex: 1;
}

.brand--centered { justify-content: center; }

/* Logo image — fills the header area */
.logoWrap {
    flex: 1;
    min-width: 0;
    height: 60px;
    display: flex;
    align-items: center;
}

.logoImg {
    height: 100%;
    width: 100%;
    object-fit: contain;
    object-position: center center;
    display: block;
}

.sidebar.collapsed .logoWrap {
    flex: none;
    width: 40px;
    height: 40px;
}

.sidebar.collapsed .logoImg {
    object-position: center center;
}

/* Fallback gradient mark */
.brandMark {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    background: linear-gradient(135deg, #22d3ee 0%, #6366f1 100%);
    box-shadow: 0 4px 12px rgba(34, 211, 238, 0.2);
}

.brandInitial {
    color: #fff;
    font-weight: 700;
    font-size: 1rem;
    line-height: 1;
}

.brandName {
    font-weight: 700;
    font-size: 0.95rem;
    letter-spacing: -0.015em;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    color: var(--color-text);
}

/* Collapse button */
.collapseBtn {
    flex-shrink: 0;
    background: none;
    border: none;
    padding: 7px;
    border-radius: 8px;
    cursor: pointer;
    color: var(--color-muted);
    transition: background 0.15s, color 0.15s;
    display: flex;
}

.collapseBtn:hover {
    background: var(--color-surface-2);
    color: var(--color-primary);
}

.collapseIcon {
    display: flex;
    align-items: center;
    transition: transform 0.2s;
}

.collapseIcon.flipped { transform: rotate(180deg); }

/* ── Nav ─────────────────────────────────────────────── */
.navGroups {
    flex: 1 1 auto;
    overflow-y: auto;
    overflow-x: hidden;
    padding: 12px 0 8px;
    display: flex;
    flex-direction: column;
}

.navSectionHeader {
    font-size: 0.7rem;
    font-weight: 700;
    color: var(--color-muted);
    letter-spacing: 0.07em;
    margin: 16px 0 4px 18px;
    text-transform: uppercase;
    user-select: none;
}

.nav {
    display: flex;
    flex-direction: column;
    gap: 2px;
    padding: 0 8px;
}

.navItem {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 12px;
    border-radius: 10px;
    color: var(--color-muted);
    text-decoration: none;
    font-size: 0.9rem;
    font-weight: 500;
    position: relative;
    transition: background 0.15s, color 0.15s;
    white-space: nowrap;
    overflow: hidden;
}

.sidebar.collapsed .navItem {
    justify-content: center;
    padding: 10px;
}

.navIcon {
    flex-shrink: 0;
    display: flex;
    font-size: 1.1rem;
    transition: color 0.15s;
}

.navLabel { flex: 1; }

.navItem:hover {
    background: color-mix(in srgb, var(--color-primary) 9%, var(--color-surface-2));
    color: var(--color-primary);
}

.navItem:hover .navIcon { color: var(--color-primary); }

.navItem.active {
    background: color-mix(in srgb, var(--color-primary) 12%, var(--color-surface-2));
    color: var(--color-primary);
    font-weight: 600;
}

.navItem.active .navIcon { color: var(--color-primary); }

.activeBar {
    position: absolute;
    left: 0;
    top: 8px;
    bottom: 8px;
    width: 3px;
    border-radius: 3px;
    background: var(--color-primary);
}

/* ── Footer ──────────────────────────────────────────── */
.sidebarFooter {
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    padding: 14px 12px;
    border-top: 1px solid var(--color-border);
}

.footerUser {
    min-width: 0;
    flex: 1;
}

.footerName {
    font-size: 0.85rem;
    font-weight: 700;
    color: var(--color-text);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.footerRole {
    font-size: 0.72rem;
    color: var(--color-muted);
    text-transform: capitalize;
    margin-top: 1px;
}

.footerActions {
    display: flex;
    align-items: center;
    gap: 2px;
    flex-shrink: 0;
}

.iconBtn {
    background: none;
    border: none;
    color: var(--color-muted);
    border-radius: 8px;
    padding: 7px;
    cursor: pointer;
    transition: background 0.15s, color 0.15s;
    display: flex;
    align-items: center;
    justify-content: center;
}

.iconBtn svg { width: 18px; height: 18px; }

.iconBtn:hover {
    background: var(--color-surface-2);
    color: var(--color-primary);
}

/* ── Mobile backdrop ─────────────────────────────────── */
.backdrop {
    display: block;
    position: fixed;
    inset: 0;
    z-index: -1;
    background: rgba(0, 0, 0, 0.45);
    backdrop-filter: blur(2px);
}

@media (max-width: 960px) {
    .sidebar {
        position: fixed;
        left: 0;
        top: 0;
        height: 100vh;
        transform: translateX(-105%);
        transition: transform 0.22s cubic-bezier(0.2, 0.8, 0.2, 1);
        box-shadow: var(--shadow-lg);
    }

    .sidebar.open {
        transform: translateX(0);
    }
}

@media (prefers-reduced-motion: reduce) {
    .sidebar, .collapseIcon { transition: none; }
}
</style>
