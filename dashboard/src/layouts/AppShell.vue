<script setup lang="ts">
import { computed, ref } from "vue";
import { useRoute } from "vue-router";
import SidebarNav from "../components/layout/SidebarNav.vue";
import TopBar from "../components/layout/TopBar.vue";
import ToastViewport from "../components/toast/ToastViewport.vue";
import { provideToasts } from "../composables/useToasts";

const route = useRoute();

const navOpen = ref(false);
const navCollapsed = ref(false);

provideToasts();

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
</script>

<template>
    <div class="appShell" :class="{ collapsed: navCollapsed }">
        <SidebarNav
            :open="navOpen"
            :collapsed="navCollapsed"
            @navigate="closeNav"
            @toggle-collapsed="toggleCollapsed"
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
    display: grid;
    grid-template-columns: 280px 1fr;
}

.appShell.collapsed {
    grid-template-columns: 92px 1fr;
}

.appMain {
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
