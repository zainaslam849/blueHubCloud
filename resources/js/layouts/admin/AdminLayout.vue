<template>
    <!-- Top Loading Bar -->
    <div v-if="isLoading" class="admin-topLoader">
        <div class="admin-topLoader__bar"></div>
    </div>

    <div v-if="isAuthShell" class="admin-authShell">
        <RouterView v-slot="{ Component }">
            <Transition name="admin-route" mode="out-in">
                <component :is="Component" />
            </Transition>
        </RouterView>
    </div>

    <div
        v-else
        class="admin-shell"
        :class="{ 'is-collapsed': sidebarCollapsed }"
    >
        <SidebarNav
            :items="navItems"
            :collapsed="sidebarCollapsed"
            :app-name="appName"
            :logo-url="logoUrl"
            @toggle-collapsed="toggleSidebar"
        />

        <section class="admin-main">
            <TopBar
                :title="pageTitle"
                :sidebar-collapsed="sidebarCollapsed"
                @toggle-sidebar="toggleSidebar"
            />

            <main class="admin-content">
                <section
                    v-if="routeRenderError"
                    class="admin-card admin-card--glass admin-routeError"
                    role="alert"
                >
                    <h2 class="admin-card__headline">
                        This page failed to render
                    </h2>
                    <p class="admin-card__hint" style="margin-top: 8px">
                        {{ routeRenderError }}
                    </p>
                    <div class="admin-routeError__actions">
                        <BaseButton
                            variant="primary"
                            size="sm"
                            @click="retryCurrentRoute"
                        >
                            Retry
                        </BaseButton>
                        <BaseButton
                            variant="secondary"
                            size="sm"
                            @click="hardReloadCurrentRoute"
                        >
                            Hard reload
                        </BaseButton>
                    </div>
                </section>
                <RouterView v-else v-slot="{ Component }">
                    <Transition name="admin-route">
                        <component :is="Component" />
                    </Transition>
                </RouterView>
            </main>
        </section>
    </div>
</template>

<script setup>
import {
    computed,
    onBeforeUnmount,
    onErrorCaptured,
    onMounted,
    ref,
    watch,
} from "vue";
import { useRoute, useRouter } from "vue-router";

import { BaseButton } from "../../components/admin/base";
import SidebarNav from "../../components/admin/SidebarNav.vue";
import TopBar from "../../components/admin/TopBar.vue";
import adminApi from "../../router/admin/api";

const route = useRoute();
const router = useRouter();
const routeRenderError = ref("");

const isLoading = ref(false);
let loadingTimeout = null;
let removeBeforeGuard = null;
let removeAfterHook = null;
let removeErrorHook = null;

const isAuthShell = computed(() => {
    if (!route.name) return true;
    return route.name === "admin.login" || route.meta?.public === true;
});

watch(
    () => route.fullPath,
    () => {
        routeRenderError.value = "";
    },
);

onErrorCaptured((error, instance, info) => {
    const message = String(error?.message || "Unexpected page error");
    routeRenderError.value = message;

    if (loadingTimeout) {
        clearTimeout(loadingTimeout);
        loadingTimeout = null;
    }
    isLoading.value = false;

    console.error("[AdminLayout] Route render error:", { message, info });
    return false;
});

function retryCurrentRoute() {
    routeRenderError.value = "";
    router.replace({
        path: route.path,
        query: route.query,
        hash: route.hash,
    });
}

function hardReloadCurrentRoute() {
    window.location.assign(route.fullPath || window.location.pathname);
}

const SIDEBAR_KEY = "admin_sidebar_collapsed";
const sidebarCollapsed = ref(false);

try {
    const stored = localStorage.getItem(SIDEBAR_KEY);
    if (stored !== null) {
        sidebarCollapsed.value = stored === "1";
    } else {
        // default collapsed on small screens so content is usable
        sidebarCollapsed.value = window.innerWidth < 768;
    }
} catch (e) {
    // ignore
    sidebarCollapsed.value = window.innerWidth < 768;
}

function toggleSidebar() {
    sidebarCollapsed.value = !sidebarCollapsed.value;
    try {
        localStorage.setItem(SIDEBAR_KEY, sidebarCollapsed.value ? "1" : "0");
    } catch (e) {
        // ignore
    }
}

const pageTitle = computed(() => {
    if (typeof route.meta?.title === "string" && route.meta.title.length > 0) {
        return route.meta.title;
    }

    return "Dashboard";
});

const appName = ref("BlueHubCloud");
const logoUrl = ref("");
const faviconUrl = ref("");
let brandingRequestId = 0;

function normalizeAssetUrl(value) {
    if (!value || typeof value !== "string") {
        return "";
    }

    const trimmed = value.trim();
    if (!trimmed) {
        return "";
    }

    // Keep protocol-relative URLs untouched.
    if (trimmed.startsWith("//") && !trimmed.startsWith("///")) {
        return trimmed;
    }

    if (/^https?:\/\//i.test(trimmed)) {
        return trimmed.replace(/^(https?:\/\/[^/]+)\/+/, "$1/");
    }

    return trimmed.startsWith("/") ? trimmed : `/${trimmed}`;
}

function applyBranding() {
    const name = appName.value || "BlueHubCloud";
    document.title = `${name} — Admin`;

    let link = document.querySelector("link[rel~='icon']");
    if (faviconUrl.value) {
        if (!link) {
            link = document.createElement("link");
            link.setAttribute("rel", "icon");
            document.head.appendChild(link);
        }
        link.setAttribute("href", faviconUrl.value);
    } else if (link) {
        link.parentNode?.removeChild(link);
    }
}

async function verifyAssetUrl(url) {
    if (!url) {
        return "";
    }

    // Validate only local storage assets to avoid unnecessary requests.
    const isStorageAsset = /\/storage\/app-settings\//i.test(url);
    if (!isStorageAsset) {
        return url;
    }

    try {
        const res = await fetch(url, {
            method: "HEAD",
            cache: "no-store",
            credentials: "same-origin",
        });
        return res.ok ? url : "";
    } catch (e) {
        return "";
    }
}

async function applyBrandingSettings(data) {
    const requestId = ++brandingRequestId;

    appName.value = data.site_name || appName.value || "BlueHubCloud";

    const normalizedLogo = normalizeAssetUrl(data.admin_logo_url);
    const normalizedFavicon = normalizeAssetUrl(data.admin_favicon_url);

    const [safeLogoUrl, safeFaviconUrl] = await Promise.all([
        verifyAssetUrl(normalizedLogo),
        verifyAssetUrl(normalizedFavicon),
    ]);

    if (requestId !== brandingRequestId) {
        return;
    }

    logoUrl.value = safeLogoUrl;
    faviconUrl.value = safeFaviconUrl;
    applyBranding();
}

async function loadSettings() {
    try {
        const res = await adminApi.get("/settings");
        const data = res?.data?.data || {};
        await applyBrandingSettings(data);
    } catch (e) {
        // ignore
    }
}

async function handleSettingsUpdated(event) {
    const detail = event?.detail || {};
    await applyBrandingSettings(detail);
}

onMounted(() => {
    loadSettings();
    window.addEventListener("admin-settings-updated", handleSettingsUpdated);

    // Router loading bar
    removeBeforeGuard = router.beforeEach((to, from) => {
        // Show loading bar only if navigating between different routes
        if (to.path !== from.path) {
            // Small delay to avoid flashing for instant navigations
            loadingTimeout = setTimeout(() => {
                isLoading.value = true;
            }, 100);
        }

        return true;
    });

    removeAfterHook = router.afterEach(() => {
        // Clear timeout if navigation completed quickly
        if (loadingTimeout) {
            clearTimeout(loadingTimeout);
            loadingTimeout = null;
        }
        // Hide loading bar
        isLoading.value = false;
    });

    removeErrorHook = router.onError(() => {
        if (loadingTimeout) {
            clearTimeout(loadingTimeout);
            loadingTimeout = null;
        }
        isLoading.value = false;
    });
});

onBeforeUnmount(() => {
    window.removeEventListener("admin-settings-updated", handleSettingsUpdated);
    if (loadingTimeout) {
        clearTimeout(loadingTimeout);
        loadingTimeout = null;
    }

    if (typeof removeBeforeGuard === "function") {
        removeBeforeGuard();
        removeBeforeGuard = null;
    }

    if (typeof removeAfterHook === "function") {
        removeAfterHook();
        removeAfterHook = null;
    }

    if (typeof removeErrorHook === "function") {
        removeErrorHook();
        removeErrorHook = null;
    }
});

const navItems = [
    {
        key: "dashboard",
        label: "Dashboard",
        icon: "dashboard",
        to: { name: "admin.dashboard" },

        // Structure for future authorization & feature flags
        requiredRoles: ["admin"],
        requiredPermissions: ["admin.dashboard.view"],
        featureKey: "admin_dashboard",
    },
    {
        key: "calls",
        label: "Calls",
        icon: "calls",
        to: { name: "admin.calls" },
        requiredRoles: ["admin"],
        requiredPermissions: ["admin.calls.view"],
        featureKey: "admin_calls",
    },
    {
        key: "transcriptions",
        label: "Transcriptions",
        icon: "transcriptions",
        to: { name: "admin.transcriptions" },
        requiredRoles: ["admin"],
        requiredPermissions: ["admin.transcriptions.view"],
        featureKey: "admin_transcriptions",
    },
    {
        key: "weeklyReports",
        label: "Weekly Call Reports",
        icon: "reports",
        to: { name: "admin.weeklyReports" },
        // Mark active for both list and detail
        active: (route) => {
            return route.path.startsWith("/admin/weekly-call-reports");
        },
        requiredRoles: ["admin"],
        requiredPermissions: ["admin.weeklyReports.view"],
        featureKey: "admin_weekly_reports",
    },
    {
        key: "jobs",
        label: "Jobs / Queue",
        icon: "jobs",
        to: { name: "admin.jobs" },
        requiredRoles: ["admin"],
        requiredPermissions: ["admin.jobs.view"],
        featureKey: "admin_jobs",
    },
    {
        key: "users",
        label: "Users",
        icon: "users",
        to: { name: "admin.users" },
        requiredRoles: ["admin"],
        requiredPermissions: ["admin.users.view"],
        featureKey: "admin_users",
    },
    {
        key: "companies",
        label: "Companies",
        icon: "companies",
        to: { name: "admin.companies" },
        requiredRoles: ["admin"],
        requiredPermissions: ["admin.companies.view"],
        featureKey: "admin_companies",
    },
    {
        key: "categories",
        label: "Categories",
        icon: "categories",
        to: { name: "admin.categories" },
        requiredRoles: ["admin"],
        requiredPermissions: ["admin.categories.view"],
        featureKey: "admin_categories",
    },
    {
        key: "settings",
        label: "Settings",
        icon: "settings",
        children: [
            {
                key: "settings-general",
                label: "General",
                to: { name: "admin.settings" },
            },
            {
                key: "settings-ai",
                label: "AI Settings",
                to: { name: "admin.settings.ai" },
            },
            {
                key: "settings-tenant-sync",
                label: "Tenant Sync",
                to: { name: "admin.settings.tenantSync" },
            },
        ],
        requiredRoles: ["admin"],
        requiredPermissions: ["admin.settings.view"],
        featureKey: "admin_settings",
    },
];
</script>
