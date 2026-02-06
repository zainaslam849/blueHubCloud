<template>
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
                <RouterView v-slot="{ Component }">
                    <Transition name="admin-route" mode="out-in">
                        <component :is="Component" />
                    </Transition>
                </RouterView>
            </main>
        </section>
    </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from "vue";
import { useRoute } from "vue-router";

import SidebarNav from "../../components/admin/SidebarNav.vue";
import TopBar from "../../components/admin/TopBar.vue";
import adminApi from "../../router/admin/api";

const route = useRoute();

const isAuthShell = computed(() => {
    if (!route.name) return true;
    return route.name === "admin.login" || route.meta?.public === true;
});

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

function applyBranding() {
    const name = appName.value || "BlueHubCloud";
    document.title = `${name} — Admin`;

    if (faviconUrl.value) {
        let link = document.querySelector("link[rel~='icon']");
        if (!link) {
            link = document.createElement("link");
            link.setAttribute("rel", "icon");
            document.head.appendChild(link);
        }
        link.setAttribute("href", faviconUrl.value);
    }
}

async function loadSettings() {
    try {
        const res = await adminApi.get("/settings");
        const data = res?.data?.data || {};
        appName.value = data.site_name || "BlueHubCloud";
        logoUrl.value = data.admin_logo_url || "";
        faviconUrl.value = data.admin_favicon_url || "";
        applyBranding();
    } catch (e) {
        // ignore
    }
}

function handleSettingsUpdated(event) {
    const detail = event?.detail || {};
    appName.value = detail.site_name || appName.value || "BlueHubCloud";
    logoUrl.value = detail.admin_logo_url || "";
    faviconUrl.value = detail.admin_favicon_url || "";
    applyBranding();
}

onMounted(() => {
    loadSettings();
    window.addEventListener("admin-settings-updated", handleSettingsUpdated);
});

onBeforeUnmount(() => {
    window.removeEventListener("admin-settings-updated", handleSettingsUpdated);
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
        ],
        requiredRoles: ["admin"],
        requiredPermissions: ["admin.settings.view"],
        featureKey: "admin_settings",
    },
];
</script>
