<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useRoute, useRouter } from "vue-router";
import SidebarNav from "../components/layout/SidebarNav.vue";
import TopBar from "../components/layout/TopBar.vue";
import MobileTabBar from "../components/layout/MobileTabBar.vue";
import ToastViewport from "../components/toast/ToastViewport.vue";
import { provideToasts } from "../composables/useToasts";
import { logout as apiLogout } from "../api/auth";
import { auth } from "../composables/useAuth";
import { useCreditBalance } from "../composables/useCreditBalance";
import { http } from "../api/http";

const { state: creditState, refresh: refreshCredits } = useCreditBalance();

const route = useRoute();
const router = useRouter();

const navOpen      = ref(false);
// Default to the compact icon rail on medium laptop widths so the full
// 260px sidebar doesn't crowd the content — still just a starting point,
// the user can expand it manually and it stays that way for the session.
const navCollapsed = ref(typeof window !== "undefined" && window.innerWidth <= 1360);
const logoUrl      = ref("");      // default / fallback
const logoLightUrl = ref("");      // light logo (for dark backgrounds)
const logoDarkUrl  = ref("");      // dark logo (for light backgrounds)
const faviconUrl   = ref("");      // small square mark — collapsed sidebar rail + browser tab icon
const appName      = ref("BlueHub");

// Resolve correct URL based on current document theme
function fixUrl(url: string) {
    return url.replace(/([^:])\/\/+/g, "$1/");
}
function resolvedLogoUrl(): string {
    const isDark = document.documentElement.dataset.theme === "dark";
    if (isDark && logoLightUrl.value) return logoLightUrl.value;
    if (!isDark && logoDarkUrl.value)  return logoDarkUrl.value;
    return logoUrl.value; // fallback
}

// Reactive logo that updates when theme changes (via MutationObserver)
const activeLogoUrl = ref("");

// The admin-uploaded favicon has no <link rel="icon"> in the HTML shell to
// bind to (it's set purely from JS after the branding fetch), so point the
// browser tab icon at it directly here.
function applyFavicon(url: string) {
    let link = document.querySelector<HTMLLinkElement>('link[rel="icon"]');
    if (!link) {
        link = document.createElement("link");
        link.rel = "icon";
        document.head.appendChild(link);
    }
    link.href = url;
}

provideToasts();

onMounted(async () => {
    try {
        const res = await http.get("/api/v1/settings/branding");
        const data = res.data?.data || {};
        if (data.logo_url)       logoUrl.value      = fixUrl(data.logo_url);
        if (data.logo_light_url) logoLightUrl.value = fixUrl(data.logo_light_url);
        if (data.logo_dark_url)  logoDarkUrl.value  = fixUrl(data.logo_dark_url);
        if (data.site_name)      appName.value      = data.site_name;
        if (data.favicon_url) {
            faviconUrl.value = fixUrl(data.favicon_url);
            applyFavicon(faviconUrl.value);
        }
        activeLogoUrl.value = resolvedLogoUrl();
    } catch {
        // use defaults
    }

    // Watch for theme changes on <html data-theme>
    const observer = new MutationObserver(() => {
        activeLogoUrl.value = resolvedLogoUrl();
    });
    observer.observe(document.documentElement, { attributes: true, attributeFilter: ["data-theme"] });

    refreshCredits();
});

const pageTitle = computed(() => {
    const metaTitle = route.meta.title;
    return typeof metaTitle === "string" ? metaTitle : "Dashboard";
});

const companyName = computed(() => auth.state.user?.company_name ?? appName.value);

function toggleNav() {
    navOpen.value = !navOpen.value;
}

function closeNav() {
    navOpen.value = false;
}

function toggleCollapsed() {
    navCollapsed.value = !navCollapsed.value;
}

async function handleSignOut() {
    try {
        await apiLogout();
    } finally {
        auth.logout();
        await router.push({ name: "login" });
    }
}
</script>

<template>
    <div class="appShell" :class="{ collapsed: navCollapsed }">
        <SidebarNav
            :open="navOpen"
            :collapsed="navCollapsed"
            :logo-url="activeLogoUrl"
            :favicon-url="faviconUrl"
            :app-name="appName"
            :credits="creditState.credits"
            :credits-loaded="creditState.loaded"
            @navigate="closeNav"
            @toggle-collapsed="toggleCollapsed"
            @sign-out="handleSignOut"
        />

        <div class="appMain">
            <TopBar
                :title="pageTitle"
                :company-name="companyName"
                :credits="creditState.credits"
                :credits-loaded="creditState.loaded"
                @toggle-nav="toggleNav"
                @sign-out="handleSignOut"
            />

            <main class="appContent">
                <router-view v-slot="{ Component }">
                    <Transition name="page" mode="out-in">
                        <component :is="Component" />
                    </Transition>
                </router-view>
            </main>
        </div>

        <MobileTabBar v-if="!navOpen" />

        <ToastViewport />
    </div>
</template>

<style scoped>
.appShell {
    min-height: 100vh;
    display: flex;
}

.appShell.collapsed {
    /* sidebar self-manages its width */
}

.appMain {
    flex: 1;
    min-width: 0;
    display: grid;
    grid-template-rows: auto 1fr;
}

.appContent {
    padding: var(--space-6);
    /* Grid items default to min-width:auto (their content's min-content
       size), so a page with a wide child (e.g. a filter bar that can't
       shrink further) would silently force this whole track — and the
       page — wider than the viewport instead of clipping to it. */
    min-width: 0;
}

/* Page transition */
.page-enter-active,
.page-leave-active {
    transition: opacity var(--duration-fast) var(--ease-standard),
        transform var(--duration-fast) var(--ease-standard);
}

.page-enter-from,
.page-leave-to {
    opacity: 0;
    transform: translateY(6px);
}

@media (prefers-reduced-motion: reduce) {
    .page-enter-active,
    .page-leave-active {
        transition: none;
    }
}

@media (max-width: 960px) {
    .appShell {
        grid-template-columns: 1fr;
    }

    .appShell.collapsed {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 720px) {
    .appContent {
        padding: var(--space-4);
        padding-bottom: calc(62px + env(safe-area-inset-bottom, 0) + var(--space-4));
    }
}
</style>
