import { createRouter, createWebHistory } from "vue-router";
import { getAdminAccess, getAdminUser } from "./auth";

const router = createRouter({
    history: createWebHistory("/admin"),
    routes: [
        {
            path: "/",
            redirect: { name: "admin.dashboard" },
        },
        {
            path: "/login",
            name: "admin.login",
            meta: { title: "Login", public: true },
            component: () => import("../../pages/admin/AdminLogin.vue"),
        },
        {
            path: "/dashboard",
            name: "admin.dashboard",
            meta: { title: "Dashboard", breadcrumb: "Dashboard" },
            component: () => import("../../pages/admin/DashboardPage.vue"),
        },
        {
            path: "/forbidden",
            name: "admin.forbidden",
            meta: { title: "Forbidden", public: true },
            component: () => import("../../pages/admin/ForbiddenPage.vue"),
        },
        {
            path: "/calls",
            name: "admin.calls",
            meta: { title: "Calls", breadcrumb: "Calls" },
            component: () => import("../../pages/admin/CallsPage.vue"),
        },
        {
            path: "/calls/:callId",
            name: "admin.calls.detail",
            meta: { title: "Call Detail", breadcrumb: "Call" },
            component: () => import("../../pages/admin/CallDetailPage.vue"),
        },
        {
            path: "/transcriptions",
            name: "admin.transcriptions",
            meta: { title: "Transcriptions", breadcrumb: "Transcriptions" },
            component: () => import("../../pages/admin/TranscriptionsPage.vue"),
        },
        {
            path: "/transcriptions/:id",
            name: "admin.transcriptions.detail",
            meta: { title: "Transcription", breadcrumb: "Transcription" },
            component: () =>
                import("../../pages/admin/TranscriptionDetailPage.vue"),
        },
        {
            path: "/weekly-call-reports",
            name: "admin.weeklyReports",
            meta: {
                title: "Weekly Call Reports",
                breadcrumb: "Weekly Reports",
            },
            component: () =>
                import("../../pages/admin/WeeklyCallReportsPage.vue"),
        },
        {
            path: "/weekly-call-reports/:id",
            name: "admin.weeklyReports.detail",
            meta: { title: "Weekly Report", breadcrumb: "Weekly Report" },
            component: () =>
                import("../../pages/admin/WeeklyReportDetailPage.vue"),
        },
        {
            path: "/jobs",
            name: "admin.jobs",
            meta: { title: "Jobs / Queue", breadcrumb: "Jobs" },
            component: () => import("../../pages/admin/JobsPage.vue"),
        },
        {
            path: "/users",
            name: "admin.users",
            meta: { title: "Users", breadcrumb: "Users" },
            component: () => import("../../pages/admin/UsersPage.vue"),
        },
        {
            path: "/settings",
            name: "admin.settings",
            meta: { title: "Settings", breadcrumb: "Settings" },
            component: () => import("../../pages/admin/SettingsPage.vue"),
        },
        {
            path: "/settings/ai",
            name: "admin.settings.ai",
            meta: { title: "AI Settings", breadcrumb: "AI Settings" },
            component: () => import("../../pages/admin/AiSettingsPage.vue"),
        },
        {
            path: "/categories",
            name: "admin.categories",
            meta: { title: "Categories", breadcrumb: "Categories" },
            component: () => import("../../pages/admin/CategoriesPage.vue"),
        },
        {
            path: "/pbx-accounts",
            name: "admin.pbxAccounts",
            meta: { title: "PBX Accounts", breadcrumb: "PBX Accounts" },
            component: () => import("../../pages/admin/PbxAccountsPage.vue"),
        },
        {
            path: "/:pathMatch(.*)*",
            name: "admin.notFound",
            meta: { title: "Not found" },
            component: () => import("../../pages/admin/NotFoundPage.vue"),
        },
    ],
});

router.beforeEach(async (to) => {
    const isPublic = Boolean(to.meta?.public);

    const user = await getAdminUser();
    const access = getAdminAccess();

    if (access === "forbidden") {
        if (to.name !== "admin.forbidden") {
            return { name: "admin.forbidden" };
        }
        return true;
    }

    if (isPublic) {
        if (user && to.name === "admin.login") {
            return { name: "admin.dashboard" };
        }
        return true;
    }

    if (!user) {
        return {
            name: "admin.login",
            query: { redirect: to.fullPath },
        };
    }

    return true;
});

export default router;
