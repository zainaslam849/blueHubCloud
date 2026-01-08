<template>
    <div class="admin-shell">
        <aside class="admin-sidebar" aria-label="Admin navigation">
            <div class="admin-sidebar__brand">
                <div class="admin-sidebar__logo" aria-hidden="true"></div>
                <div class="admin-sidebar__brandText">
                    <div class="admin-sidebar__app">BlueHubCloud</div>
                    <div class="admin-sidebar__area">Admin</div>
                </div>
            </div>

            <nav class="admin-nav">
                <RouterLink
                    v-for="item in navItems"
                    :key="item.key"
                    class="admin-nav__link"
                    :to="item.to"
                >
                    <span class="admin-nav__icon" aria-hidden="true">
                        <svg
                            v-if="item.icon === 'dashboard'"
                            viewBox="0 0 24 24"
                            fill="none"
                            xmlns="http://www.w3.org/2000/svg"
                        >
                            <path
                                d="M4 13.5a2 2 0 0 0 2 2h2.5a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v7.5Z"
                                stroke="currentColor"
                                stroke-width="1.8"
                            />
                            <path
                                d="M13.5 18a2 2 0 0 0 2 2H18a2 2 0 0 0 2-2v-4.5a2 2 0 0 0-2-2h-2.5a2 2 0 0 0-2 2V18Z"
                                stroke="currentColor"
                                stroke-width="1.8"
                            />
                            <path
                                d="M13.5 8.5a2 2 0 0 0 2 2H18a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2h-2.5a2 2 0 0 0-2 2v2.5Z"
                                stroke="currentColor"
                                stroke-width="1.8"
                            />
                        </svg>
                    </span>
                    <span class="admin-nav__label">{{ item.label }}</span>
                </RouterLink>
            </nav>

            <div class="admin-sidebar__footer">
                <div class="admin-sidebar__meta">
                    Future: permissions • roles • feature flags
                </div>
            </div>
        </aside>

        <section class="admin-main">
            <header class="admin-topbar">
                <nav class="admin-breadcrumbs" aria-label="Breadcrumb">
                    <RouterLink
                        class="admin-breadcrumbs__link"
                        :to="{ name: 'admin.dashboard' }"
                    >
                        Admin
                    </RouterLink>

                    <span class="admin-breadcrumbs__sep" aria-hidden="true"
                        >/</span
                    >
                    <span class="admin-breadcrumbs__current">{{
                        pageTitle
                    }}</span>
                </nav>

                <div class="admin-user">
                    <div class="admin-user__badge" aria-hidden="true">AU</div>
                    <div class="admin-user__meta">
                        <div class="admin-user__name">Admin User</div>
                        <div class="admin-user__role">Role placeholder</div>
                    </div>
                </div>
            </header>

            <main class="admin-content">
                <RouterView />
            </main>
        </section>
    </div>
</template>

<script setup>
import { computed } from "vue";
import { useRoute } from "vue-router";

const route = useRoute();

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
];
</script>
