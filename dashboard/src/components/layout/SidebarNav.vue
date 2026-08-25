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
    credits?: number | null;
    creditsLoaded?: boolean;
};
const props = defineProps<Props>();

const creditsLabel = computed(() =>
    props.credits == null ? "—" : props.credits.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }),
);
const creditsLow = computed(() => props.credits != null && props.credits < 50);
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

// The sidebar becomes a full-width overlay drawer below this width — the
// desktop "collapsed to icon rail" state doesn't make sense there, so
// rendering must ignore props.collapsed once we're in that range (see
// effectiveCollapsed below). Mirrors the 960px breakpoint in <style>.
const MOBILE_BREAKPOINT = "(max-width: 960px)";
const isMobile = ref(false);
let mobileMq: MediaQueryList | null = null;
let mobileMqListener: (() => void) | null = null;

onMounted(() => {
    applyTheme(resolvedTheme.value);
    mq = window.matchMedia?.("(prefers-color-scheme: dark)") ?? null;
    if (mq) {
        mqListener = () => { if (themePref.value === "system") applyTheme(getSystemTheme()); };
        mq.addEventListener("change", mqListener);
    }

    mobileMq = window.matchMedia?.(MOBILE_BREAKPOINT) ?? null;
    if (mobileMq) {
        isMobile.value = mobileMq.matches;
        mobileMqListener = () => { isMobile.value = mobileMq!.matches; };
        mobileMq.addEventListener("change", mobileMqListener);
    }
});

onUnmounted(() => {
    if (mq && mqListener) mq.removeEventListener("change", mqListener);
    if (mobileMq && mobileMqListener) mobileMq.removeEventListener("change", mobileMqListener);
});

const effectiveCollapsed = computed(() => props.collapsed && !isMobile.value);

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
            label: "OVERVIEW",
            items: [
                { name: "Dashboard", icon: "dashboard", to: { name: "dashboard" } },
                { name: "Calls", icon: "calls", to: { name: "calls" } },
                { name: "Transcriptions", icon: "generated", to: { name: "transcriptions" } },
                { name: "Weekly Call Reports", icon: "reports", to: { name: "reports" } },
            ],
        },
        {
            label: "BILLING",
            items: [
                { name: "Buy Credits", icon: "credits", to: { name: "select-plan" } },
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
        :class="{ open: props.open, collapsed: effectiveCollapsed }"
        aria-label="Sidebar navigation"
    >
        <!-- Brand/Logo -->
        <div class="sidebarHeader">
            <div class="brand" :class="{ 'brand--centered': effectiveCollapsed }">
                <!-- Collapsed rail: always the compact mark — the full wordmark
                     logo has no room to breathe squeezed into an icon-sized box. -->
                <div v-if="effectiveCollapsed" class="brandMark" aria-hidden="true">
                    <span class="brandInitial">{{ (props.appName ?? 'B').charAt(0).toUpperCase() }}</span>
                </div>
                <!-- Expanded: the real logo image when one is configured -->
                <div v-else-if="props.logoUrl" class="logoWrap">
                    <img :src="props.logoUrl" :alt="props.appName ?? 'Logo'" class="logoImg" />
                </div>
                <!-- Expanded fallback: gradient mark + name, when no logo is configured -->
                <template v-else>
                    <div class="brandMark" aria-hidden="true">
                        <span class="brandInitial">{{ (props.appName ?? 'B').charAt(0).toUpperCase() }}</span>
                    </div>
                    <span class="brandName">{{ props.appName ?? 'BlueHub' }}</span>
                </template>
            </div>

            <button
                class="collapseBtn"
                type="button"
                :aria-label="effectiveCollapsed ? 'Expand sidebar' : 'Collapse sidebar'"
                @click="$emit('toggle-collapsed')"
            >
                <span class="collapseIcon" :class="{ flipped: !effectiveCollapsed }" aria-hidden="true">
                    <AppIcon name="collapse" />
                </span>
            </button>

            <button
                class="mobileCloseBtn"
                type="button"
                aria-label="Close menu"
                @click="onNavigate"
            >
                <svg viewBox="0 0 24 24" fill="none"><path d="M6 6l12 12M18 6 6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
            </button>
        </div>

        <!-- Nav Groups -->
        <div class="navGroups">
            <template v-for="group in navGroups" :key="group.label">
                <div v-if="!effectiveCollapsed" class="navSectionHeader">{{ group.label }}</div>
                <nav class="nav" :aria-label="group.label">
                    <router-link
                        v-for="item in group.items"
                        :key="item.name"
                        class="navItem"
                        :class="{ active: isActive(item.to.name) }"
                        :to="item.to"
                        @click="onNavigate"
                        :title="effectiveCollapsed ? item.name : undefined"
                    >
                        <span class="navIcon"><AppIcon :name="item.icon as any" /></span>
                        <span v-if="!effectiveCollapsed" class="navLabel">{{ item.name }}</span>
                        <span v-if="isActive(item.to?.name)" class="activeBar"></span>
                    </router-link>
                </nav>
            </template>
        </div>

        <!-- Credits meter -->
        <div v-if="!effectiveCollapsed" class="creditsCard" :class="{ 'creditsCard--low': creditsLow }">
            <div class="creditsCard__row">
                <span class="creditsCard__label">Credits left</span>
                <span class="creditsCard__value">{{ creditsLabel }}</span>
            </div>
            <router-link v-if="creditsLow" :to="{ name: 'select-plan' }" class="creditsCard__cta" @click="onNavigate">
                Running low — add credits
            </router-link>
        </div>
        <router-link
            v-else
            :to="{ name: 'select-plan' }"
            class="creditsCollapsedBtn"
            title="Buy credits"
            @click="onNavigate"
        >
            <AppIcon name="credits" />
        </router-link>

        <!-- Footer -->
        <div class="sidebarFooter">
            <div v-if="!effectiveCollapsed" class="footerUser">
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
    </aside>

    <!-- Rendered as a sibling of <aside>, not nested inside it: the sidebar
         has a `transform` (for the slide animation) and `overflow: hidden`,
         and a transformed ancestor becomes the containing block for
         position:fixed descendants — nesting the backdrop inside it clipped
         it to the drawer's own width, so taps outside the drawer never
         reached it. -->
    <div v-if="props.open" class="backdrop" @click="onNavigate"></div>
</template>

<style scoped>
/* ── Shell ───────────────────────────────────────────── */
.sidebar {
    position: sticky;
    top: 0;
    height: 100vh;
    width: 260px;
    flex-shrink: 0;
    background: var(--sidebar-bg);
    border-right: 1px solid var(--sidebar-border);
    display: flex;
    flex-direction: column;
    transition: width 0.22s cubic-bezier(0.2, 0.8, 0.2, 1);
    /* Above MobileTabBar's z-index:25 so the open mobile drawer fully
       covers the bottom tab bar instead of it poking through on top —
       the tab bar should only be usable once the sidebar is closed. */
    z-index: 30;
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
    border-bottom: 1px solid var(--sidebar-border);
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

/* Logo image — on a light patch so it stays legible regardless of which
   logo variant is configured, since the sidebar itself is always dark. */
.logoWrap {
    flex: 1;
    min-width: 0;
    height: 52px;
    display: flex;
    align-items: center;
    background: #ffffff;
    border-radius: 10px;
    padding: 8px 12px;
}

.logoImg {
    height: 100%;
    width: 100%;
    object-fit: contain;
    object-position: center center;
    display: block;
}

/* Compact brand mark — shown on the collapsed rail, and as the expanded
   fallback when no logo image is configured. */
.brandMark {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    background: linear-gradient(135deg, #52b3df 0%, #27699b 100%);
    box-shadow: 0 4px 12px rgba(43, 110, 159, 0.25);
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
    color: var(--sidebar-text);
}

/* Collapse button */
.collapseBtn {
    flex-shrink: 0;
    background: none;
    border: none;
    padding: 7px;
    border-radius: 8px;
    cursor: pointer;
    color: var(--sidebar-text-muted);
    transition: background 0.15s, color 0.15s;
    display: flex;
}

.collapseBtn:hover {
    background: rgba(255, 255, 255, 0.08);
    color: var(--sidebar-text);
}

.collapseIcon {
    display: flex;
    align-items: center;
    transition: transform 0.2s;
}

.collapseIcon.flipped { transform: rotate(180deg); }

/* Mobile close (X) button — the collapse button above only shrinks the
   sidebar to an icon rail (a desktop concept); on mobile the sidebar is
   a full-width overlay, so it needs an explicit close action instead. */
.mobileCloseBtn {
    display: none;
    flex-shrink: 0;
    width: 34px;
    height: 34px;
    background: none;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    color: var(--sidebar-text-muted);
    align-items: center;
    justify-content: center;
}

.mobileCloseBtn svg { width: 20px; height: 20px; }

.mobileCloseBtn:hover {
    background: rgba(255, 255, 255, 0.08);
    color: var(--sidebar-text);
}

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
    color: var(--sidebar-text-faint);
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
    color: var(--sidebar-text-muted);
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
    background: rgba(255, 255, 255, 0.06);
    color: var(--sidebar-text);
}

.navItem:hover .navIcon { color: var(--sidebar-text); }

.navItem.active {
    background: var(--sidebar-active-bg);
    color: #ffffff;
    font-weight: 600;
}

.navItem.active .navIcon { color: #ffffff; }

.activeBar { display: none; }

/* ── Credits meter ───────────────────────────────────── */
.creditsCard {
    flex-shrink: 0;
    margin: 4px 12px 10px;
    padding: 12px 13px;
    border-radius: 12px;
    background: var(--sidebar-bg-2);
    border: 1px solid var(--sidebar-border);
}

.creditsCard--low {
    border-color: color-mix(in srgb, var(--color-error) 45%, var(--sidebar-border));
}

.creditsCard__row {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 8px;
}

.creditsCard__label {
    font-size: 0.68rem;
    color: var(--sidebar-text-faint);
    letter-spacing: 0.02em;
}

.creditsCard__value {
    font-family: var(--font-mono);
    font-size: 0.82rem;
    font-weight: 500;
    color: var(--sidebar-text);
}

.creditsCard__cta {
    display: block;
    margin-top: 8px;
    font-size: 0.72rem;
    font-weight: 600;
    color: var(--color-error);
    text-decoration: none;
}

.creditsCard__cta:hover { text-decoration: underline; }

.creditsCollapsedBtn {
    flex-shrink: 0;
    margin: 4px auto 10px;
    width: 40px;
    height: 40px;
    border-radius: 10px;
    background: var(--sidebar-bg-2);
    border: 1px solid var(--sidebar-border);
    color: var(--sidebar-text-muted);
    display: flex;
    align-items: center;
    justify-content: center;
    text-decoration: none;
}

.creditsCollapsedBtn:hover {
    color: var(--sidebar-text);
    border-color: var(--color-primary);
}

/* ── Footer ──────────────────────────────────────────── */
.sidebarFooter {
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    padding: 14px 12px;
    border-top: 1px solid var(--sidebar-border);
}

.footerUser {
    min-width: 0;
    flex: 1;
}

.footerName {
    font-size: 0.85rem;
    font-weight: 700;
    color: var(--sidebar-text);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.footerRole {
    font-size: 0.72rem;
    color: var(--sidebar-text-faint);
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
    color: var(--sidebar-text-muted);
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
    background: rgba(255, 255, 255, 0.08);
    color: var(--sidebar-text);
}

/* ── Compact density (medium/small laptop) ──────────────
   Below ~1440px the full 260px sidebar with desktop-scale spacing feels
   oversized relative to the available content width — this keeps it as a
   real labeled nav (not the icon-only rail), just proportionally tighter:
   narrower shell, smaller type, less padding throughout. Scoped above the
   960px mobile-overlay breakpoint, which replaces the sizing wholesale. */
@media (max-width: 1440px) and (min-width: 961px) {
    .sidebar { width: 220px; }

    .sidebarHeader { height: 64px; padding: 0 10px; }
    .logoWrap { height: 40px; padding: 6px 10px; }
    .brandMark { width: 30px; height: 30px; }
    .brandName { font-size: 0.85rem; }

    .navGroups { padding: 8px 0 6px; }
    .navSectionHeader { font-size: 0.64rem; margin: 12px 0 3px 14px; }
    .nav { padding: 0 6px; gap: 1px; }
    .navItem { padding: 8px 10px; gap: 10px; font-size: 0.82rem; }
    .navIcon { font-size: 1rem; }

    .creditsCard { margin: 3px 10px 8px; padding: 10px 11px; }
    .creditsCard__label { font-size: 0.64rem; }
    .creditsCard__value { font-size: 0.78rem; }
    .creditsCard__cta { font-size: 0.68rem; margin-top: 6px; }

    .sidebarFooter { padding: 10px 10px; }
    .footerName { font-size: 0.8rem; }
    .footerRole { font-size: 0.68rem; }
    .iconBtn { padding: 6px; }
    .iconBtn svg { width: 16px; height: 16px; }
}

/* ── Mobile backdrop ─────────────────────────────────── */
.backdrop {
    display: none;
    position: fixed;
    inset: 0;
    /* Also above MobileTabBar (z-index:25) — otherwise the tab bar stays
       clickable through the dimmed backdrop while the drawer is open. */
    z-index: 26;
    background: rgba(0, 0, 0, 0.45);
    backdrop-filter: blur(2px);
}

@media (max-width: 960px) {
    .backdrop { display: block; }

    .sidebar,
    .sidebar.collapsed {
        position: fixed;
        left: 0;
        top: 0;
        height: 100vh;
        width: min(300px, 84vw);
        transform: translateX(-105%);
        transition: transform 0.22s cubic-bezier(0.2, 0.8, 0.2, 1);
        box-shadow: var(--shadow-lg);
        z-index: 20;
    }

    .sidebar.open {
        transform: translateX(0);
    }

    /* effectiveCollapsed (script) already keeps the .collapsed class off
       the sidebar on mobile, so no icon-rail overrides are needed here. */
    .collapseBtn { display: none; }
    .mobileCloseBtn { display: flex; }

    /* The sidebar overlay is full viewport height, but MobileTabBar sits
       fixed on top of it at a higher z-index — without this, the footer
       (user info, theme toggle, sign out) renders right where the tab bar
       covers it and is invisible/unclickable. */
    .sidebarFooter {
        padding-bottom: calc(14px + 62px + env(safe-area-inset-bottom, 0px));
    }
}

@media (prefers-reduced-motion: reduce) {
    .sidebar, .collapseIcon { transition: none; }
}
</style>
