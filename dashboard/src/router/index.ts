import {
    createRouter,
    createWebHistory,
    type RouteRecordRaw,
} from "vue-router";

const routes: RouteRecordRaw[] = [
    {
        path: "/",
        component: () => import("../layouts/AppShell.vue"),
        children: [
            {
                path: "",
                redirect: { name: "dashboard" },
            },
            {
                path: "dashboard",
                name: "dashboard",
                component: () => import("../views/DashboardView.vue"),
                meta: { title: "Dashboard" },
            },
            {
                path: "reports",
                name: "reports",
                component: () => import("../views/ReportsView.vue"),
                meta: { title: "Reports" },
            },
            {
                path: "account",
                name: "account",
                component: () => import("../views/AccountView.vue"),
                meta: { title: "Account" },
            },
            {
                path: "usage",
                name: "usage",
                component: () => import("../views/UsageView.vue"),
                meta: { title: "Usage" },
            },
            {
                path: "companies",
                name: "companies",
                component: () => import("../views/CompaniesView.vue"),
                meta: { title: "Companies" },
            },
        ],
    },
    {
        path: "/:pathMatch(.*)*",
        name: "not-found",
        component: () => import("../views/NotFoundView.vue"),
    },
];

export const router = createRouter({
    history: createWebHistory(),
    routes,
});
