<template>
    <div v-if="isLogin" class="admin-authShell">
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
import { computed, ref } from "vue";
import { useRoute } from "vue-router";

import SidebarNav from "../../components/admin/SidebarNav.vue";
import TopBar from "../../components/admin/TopBar.vue";

const route = useRoute();

const isLogin = computed(() => route.name === "admin.login");

const SIDEBAR_KEY = "admin_sidebar_collapsed";
const sidebarCollapsed = ref(false);

try {
    sidebarCollapsed.value = localStorage.getItem(SIDEBAR_KEY) === "1";
} catch (e) {
    // ignore
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
        key: "recordings",
        label: "Recordings",
        icon: "recordings",
        to: { name: "admin.recordings" },
        requiredRoles: ["admin"],
        requiredPermissions: ["admin.recordings.view"],
        featureKey: "admin_recordings",
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
        key: "settings",
        label: "Settings",
        icon: "settings",
        to: { name: "admin.settings" },
        requiredRoles: ["admin"],
        requiredPermissions: ["admin.settings.view"],
        featureKey: "admin_settings",
    },
];
</script>
