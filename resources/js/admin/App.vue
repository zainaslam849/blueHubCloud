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
                        <svg
                            v-else-if="item.icon === 'server'"
                            viewBox="0 0 24 24"
                            fill="none"
                            xmlns="http://www.w3.org/2000/svg"
                        >
                            <rect
                                x="3"
                                y="4"
                                width="18"
                                height="5"
                                rx="2"
                                stroke="currentColor"
                                stroke-width="1.8"
                            />
                            <rect
                                x="3"
                                y="15"
                                width="18"
                                height="5"
                                rx="2"
                                stroke="currentColor"
                                stroke-width="1.8"
                            />
                            <circle
                                cx="7"
                                cy="6.5"
                                r="0.5"
                                fill="currentColor"
                            />
                            <circle
                                cx="7"
                                cy="17.5"
                                r="0.5"
                                fill="currentColor"
                            />
                        </svg>
                        <svg
                            v-else-if="item.icon === 'phone'"
                            viewBox="0 0 24 24"
                            fill="none"
                            xmlns="http://www.w3.org/2000/svg"
                        >
                            <path
                                d="M3 5a2 2 0 0 1 2-2h3.28a1 1 0 0 1 .948.684l1.498 4.493a1 1 0 0 1-.502 1.21l-2.257 1.13a11.042 11.042 0 0 0 5.516 5.516l1.13-2.257a1 1 0 0 1 1.21-.502l4.493 1.498a1 1 0 0 1 .684.949V19a2 2 0 0 1-2 2h-1C9.716 21 3 14.284 3 6V5Z"
                                stroke="currentColor"
                                stroke-width="1.8"
                            />
                        </svg>
                        <svg
                            v-else-if="item.icon === 'document'"
                            viewBox="0 0 24 24"
                            fill="none"
                            xmlns="http://www.w3.org/2000/svg"
                        >
                            <path
                                d="M9 12h6m-6 4h6m2 5H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5.586a1 1 0 0 1 .707.293l5.414 5.414a1 1 0 0 1 .293.707V19a2 2 0 0 1-2 2Z"
                                stroke="currentColor"
                                stroke-width="1.8"
                            />
                        </svg>
                        <svg
                            v-else-if="item.icon === 'chart'"
                            viewBox="0 0 24 24"
                            fill="none"
                            xmlns="http://www.w3.org/2000/svg"
                        >
                            <path
                                d="M9 17v-6m6 6v-3M5 21h14a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2Z"
                                stroke="currentColor"
                                stroke-width="1.8"
                            />
                        </svg>
                        <svg
                            v-else-if="item.icon === 'clock'"
                            viewBox="0 0 24 24"
                            fill="none"
                            xmlns="http://www.w3.org/2000/svg"
                        >
                            <circle
                                cx="12"
                                cy="12"
                                r="9"
                                stroke="currentColor"
                                stroke-width="1.8"
                            />
                            <path
                                d="M12 7v5l3 3"
                                stroke="currentColor"
                                stroke-width="1.8"
                            />
                        </svg>
                        <svg
                            v-else-if="item.icon === 'users'"
                            viewBox="0 0 24 24"
                            fill="none"
                            xmlns="http://www.w3.org/2000/svg"
                        >
                            <path
                                d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm14 10v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"
                                stroke="currentColor"
                                stroke-width="1.8"
                            />
                        </svg>
                        <svg
                            v-else-if="item.icon === 'tag'"
                            viewBox="0 0 24 24"
                            fill="none"
                            xmlns="http://www.w3.org/2000/svg"
                        >
                            <path
                                d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82Z"
                                stroke="currentColor"
                                stroke-width="1.8"
                            />
                            <circle cx="7" cy="7" r="1" fill="currentColor" />
                        </svg>
                        <svg
                            v-else-if="item.icon === 'settings'"
                            viewBox="0 0 24 24"
                            fill="none"
                            xmlns="http://www.w3.org/2000/svg"
                        >
                            <path
                                d="M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z"
                                stroke="currentColor"
                                stroke-width="1.8"
                            />
                            <path
                                d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1Z"
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
    },
    {
        key: "calls",
        label: "Calls",
        icon: "phone",
        to: { name: "admin.calls" },
    },
    {
        key: "transcriptions",
        label: "Transcriptions",
        icon: "document",
        to: { name: "admin.transcriptions" },
    },
    {
        key: "weekly-reports",
        label: "Weekly Reports",
        icon: "chart",
        to: { name: "admin.weeklyReports" },
    },
    {
        key: "jobs",
        label: "Jobs",
        icon: "clock",
        to: { name: "admin.jobs" },
    },
    {
        key: "users",
        label: "Users",
        icon: "users",
        to: { name: "admin.users" },
    },
    {
        key: "categories",
        label: "Categories",
        icon: "tag",
        to: { name: "admin.categories" },
    },
    {
        key: "settings",
        label: "Settings",
        icon: "settings",
        to: { name: "admin.settings" },
    },
];
</script>
