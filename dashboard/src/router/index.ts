import {
    createRouter,
    createWebHistory,
    type RouteRecordRaw,
} from "vue-router";
import { auth } from "../composables/useAuth";

const routes: RouteRecordRaw[] = [
    {
        path: "/login",
        name: "login",
        component: () => import("../views/LoginView.vue"),
        meta: { title: "Sign in", requiresAuth: false },
    },
    {
        path: "/register",
        name: "register",
        component: () => import("../views/RegisterView.vue"),
        meta: { title: "Create account", requiresAuth: false },
    },
    {
        path: "/",
        component: () => import("../layouts/AppShell.vue"),
        meta: { requiresAuth: true },
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
                meta: { title: "Weekly Call Reports" },
            },
            {
                path: "calls",
                name: "calls",
                component: () => import("../views/CallsView.vue"),
                meta: { title: "Calls" },
            },
            {
                path: "calls/:callId",
                name: "calls-detail",
                component: () => import("../views/CallDetailView.vue"),
                meta: { title: "Call Detail" },
            },
            {
                path: "transcriptions",
                name: "transcriptions",
                component: () => import("../views/TranscriptionsView.vue"),
                meta: { title: "Transcriptions" },
            },
            {
                path: "reports/:companySlug/:weekStart",
                name: "report-detail",
                component: () => import("../views/ReportDetailView.vue"),
                meta: { title: "Report Detail" },
            },
            {
                path: "ai-processing",
                name: "ai-processing",
                component: () => import("../views/AiProcessingView.vue"),
                meta: { title: "AI Processing" },
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
            {
                path: "pbx-accounts",
                name: "pbx-accounts",
                component: () => import("../views/PbxAccountsView.vue"),
                meta: { title: "PBX Accounts" },
            },
            {
                path: "admin-users",
                name: "admin-users",
                component: () => import("../views/AdminUsersView.vue"),
                meta: { title: "Users" },
            },
            {
                path: "plans",
                name: "plans",
                component: () => import("../views/PlansView.vue"),
                meta: { title: "Plans" },
            },
            {
                path: "select-plan",
                name: "select-plan",
                component: () => import("../views/PlanSelectView.vue"),
                meta: { title: "Buy Credits" },
            },
            {
                path: "billing",
                name: "billing",
                redirect: { name: "select-plan" },
            },
            {
                path: "purchase/success",
                name: "purchase-success",
                component: () => import("../views/PurchaseSuccessView.vue"),
                meta: { title: "Payment Successful" },
            },
            {
                path: "admin/purchases",
                name: "admin-purchases",
                component: () => import("../views/AdminPurchasesView.vue"),
                meta: { title: "Purchase History" },
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

router.beforeEach((to) => {
    const requiresAuth = to.matched.some(
        (record) => record.meta?.requiresAuth !== false,
    );

    if (!requiresAuth) {
        return true;
    }

    if (auth.isAuthenticated()) {
        return true;
    }

    return {
        name: "login",
        query: { redirect: to.fullPath },
    };
});
