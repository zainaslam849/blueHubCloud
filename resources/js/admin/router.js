import { createRouter, createWebHistory } from "vue-router";

const router = createRouter({
    history: createWebHistory("/admin"),
    routes: [
        {
            path: "/",
            redirect: { name: "admin.dashboard" },
        },
        {
            path: "/dashboard",
            name: "admin.dashboard",
            meta: { title: "Dashboard", breadcrumb: "Dashboard" },
            component: () => import("./views/AdminHomeView.vue"),
        },
        {
            path: "/:pathMatch(.*)*",
            name: "admin.notFound",
            meta: { title: "Not found" },
            component: () => import("./views/AdminNotFoundView.vue"),
        },
    ],
});

export default router;
