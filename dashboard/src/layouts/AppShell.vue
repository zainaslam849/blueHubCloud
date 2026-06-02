<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useRoute, useRouter } from "vue-router";
import SidebarNav from "../components/layout/SidebarNav.vue";
import TopBar from "../components/layout/TopBar.vue";
import ToastViewport from "../components/toast/ToastViewport.vue";
import { provideToasts } from "../composables/useToasts";
import { logout as apiLogout } from "../api/auth";
import { auth } from "../composables/useAuth";
import { http } from "../api/http";

const route = useRoute();
const router = useRouter();

const navOpen = ref(false);
const navCollapsed = ref(false);
const logoUrl = ref("");
const appName = ref("BlueHub");

provideToasts();

onMounted(async () => {
    try {
        const res = await http.get("/api/v1/settings/branding");
        const data = res.data?.data || {};
        if (data.logo_url) {
            // Normalise any double-slash outside the protocol (http://host//path → http://host/path)
            logoUrl.value = data.logo_url.replace(/([^:])\/\/+/g, "$1/");
        }
        if (data.site_name) appName.value = data.site_name;
    } catch {
        // use defaults
    }
});

const pageTitle = computed(() => {
    const metaTitle = route.meta.title;
    return typeof metaTitle === "string" ? metaTitle : "Dashboard";
});

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
            :logo-url="logoUrl"
            :app-name="appName"
            @navigate="closeNav"
            @toggle-collapsed="toggleCollapsed"
            @sign-out="handleSignOut"
        />

        <div class="appMain">
            <TopBar
                :title="pageTitle"
                company-name="BlueHub"
                @toggle-nav="toggleNav"
            />

            <main class="appContent">
                <router-view v-slot="{ Component }">
                    <Transition name="page" mode="out-in">
                        <component :is="Component" />
                    </Transition>
                </router-view>
            </main>
        </div>

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
</style>
