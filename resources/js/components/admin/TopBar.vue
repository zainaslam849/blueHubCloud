<template>
    <header class="admin-topbar admin-topbar--desktop">
        <div class="admin-topbar__left">
            <button
                type="button"
                class="admin-iconBtn"
                :aria-label="
                    sidebarCollapsed ? 'Expand sidebar' : 'Collapse sidebar'
                "
                :title="
                    sidebarCollapsed ? 'Expand sidebar' : 'Collapse sidebar'
                "
                @click="$emit('toggle-sidebar')"
            >
                <span class="admin-icon" aria-hidden="true">
                    <svg
                        viewBox="0 0 24 24"
                        fill="none"
                        xmlns="http://www.w3.org/2000/svg"
                    >
                        <path
                            d="M4 7h16"
                            stroke="currentColor"
                            stroke-width="1.8"
                            stroke-linecap="round"
                        />
                        <path
                            d="M4 12h16"
                            stroke="currentColor"
                            stroke-width="1.8"
                            stroke-linecap="round"
                        />
                        <path
                            d="M4 17h12"
                            stroke="currentColor"
                            stroke-width="1.8"
                            stroke-linecap="round"
                        />
                    </svg>
                </span>
            </button>

            <nav class="admin-breadcrumbs" aria-label="Breadcrumb">
                <RouterLink
                    class="admin-breadcrumbs__link"
                    :to="{ name: 'admin.dashboard' }"
                >
                    Admin
                </RouterLink>

                <span class="admin-breadcrumbs__sep" aria-hidden="true">/</span>
                <span class="admin-breadcrumbs__current">{{ title }}</span>
            </nav>
        </div>

        <div class="admin-topbar__right">
            <button type="button" class="admin-chip" disabled>
                <span class="admin-chip__label">Company</span>
                <span class="admin-chip__value">Placeholder</span>
            </button>

            <ThemeToggle />

            <div class="admin-menu" ref="menuEl">
                <button
                    type="button"
                    class="admin-userBtn"
                    :aria-expanded="menuOpen ? 'true' : 'false'"
                    aria-haspopup="menu"
                    @click="menuOpen = !menuOpen"
                >
                    <div class="admin-avatar" aria-hidden="true">
                        {{ initials }}
                    </div>
                    <div class="admin-userMeta">
                        <div class="admin-userMeta__name">{{ userName }}</div>
                        <div class="admin-userMeta__role">{{ userRole }}</div>
                    </div>
                    <span class="admin-icon" aria-hidden="true">
                        <svg
                            viewBox="0 0 24 24"
                            fill="none"
                            xmlns="http://www.w3.org/2000/svg"
                        >
                            <path
                                d="M7 10l5 5 5-5"
                                stroke="currentColor"
                                stroke-width="1.8"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                            />
                        </svg>
                    </span>
                </button>

                <div v-if="menuOpen" class="admin-menu__panel" role="menu">
                    <button
                        type="button"
                        class="admin-menu__item"
                        role="menuitem"
                        @click="logout"
                    >
                        Logout
                    </button>
                </div>
            </div>
        </div>
    </header>

    <header class="admin-topbar admin-topbar--mobile">
        <div class="admin-topbar__left">
            <button
                type="button"
                class="admin-iconBtn"
                :aria-label="sidebarCollapsed ? 'Open menu' : 'Close menu'"
                :title="sidebarCollapsed ? 'Open menu' : 'Close menu'"
                @click="$emit('toggle-sidebar')"
            >
                <span class="admin-icon" aria-hidden="true">
                    <svg
                        viewBox="0 0 24 24"
                        fill="none"
                        xmlns="http://www.w3.org/2000/svg"
                    >
                        <path
                            d="M4 7h16"
                            stroke="currentColor"
                            stroke-width="1.8"
                            stroke-linecap="round"
                        />
                        <path
                            d="M4 12h16"
                            stroke="currentColor"
                            stroke-width="1.8"
                            stroke-linecap="round"
                        />
                        <path
                            d="M4 17h12"
                            stroke="currentColor"
                            stroke-width="1.8"
                            stroke-linecap="round"
                        />
                    </svg>
                </span>
            </button>

            <div class="admin-topbar__title" aria-label="Page title">
                {{ title }}
            </div>
        </div>

        <div class="admin-topbar__right">
            <ThemeToggle />

            <div class="admin-menu" ref="menuElMobile">
                <button
                    type="button"
                    class="admin-userBtn admin-userBtn--compact"
                    :aria-expanded="menuOpen ? 'true' : 'false'"
                    aria-haspopup="menu"
                    @click="menuOpen = !menuOpen"
                >
                    <div class="admin-avatar" aria-hidden="true">
                        {{ initials }}
                    </div>
                    <span class="admin-icon" aria-hidden="true">
                        <svg
                            viewBox="0 0 24 24"
                            fill="none"
                            xmlns="http://www.w3.org/2000/svg"
                        >
                            <path
                                d="M7 10l5 5 5-5"
                                stroke="currentColor"
                                stroke-width="1.8"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                            />
                        </svg>
                    </span>
                </button>

                <div v-if="menuOpen" class="admin-menu__panel" role="menu">
                    <button
                        type="button"
                        class="admin-menu__item"
                        role="menuitem"
                        @click="logout"
                    >
                        Logout
                    </button>
                </div>
            </div>
        </div>
    </header>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from "vue";
import { useRouter } from "vue-router";

import ThemeToggle from "./ThemeToggle.vue";

import adminApi from "../../router/admin/api";
import { clearAdminUser, getAdminUser } from "../../router/admin/auth";

defineProps({
    title: {
        type: String,
        required: true,
    },

    sidebarCollapsed: {
        type: Boolean,
        default: false,
    },
});

defineEmits(["toggle-sidebar"]);

const router = useRouter();

const menuOpen = ref(false);
const menuEl = ref(null);
const menuElMobile = ref(null);

const user = ref(null);

onMounted(async () => {
    user.value = await getAdminUser();

    document.addEventListener("click", onDocClick);
});

const onDocClick = (e) => {
    const desktopEl = menuEl.value;
    const mobileEl = menuElMobile.value;
    if (desktopEl && desktopEl.contains(e.target)) return;
    if (mobileEl && mobileEl.contains(e.target)) return;
    menuOpen.value = false;
};

onBeforeUnmount(() => {
    document.removeEventListener("click", onDocClick);
});

const userName = computed(() => user.value?.name ?? "Admin");
const userRole = computed(() => user.value?.role ?? "—");
const initials = computed(() => {
    const name = String(userName.value || "").trim();
    if (!name) return "A";
    const parts = name.split(/\s+/).filter(Boolean);
    const letters = parts.slice(0, 2).map((p) => p[0]?.toUpperCase());
    return letters.join("") || "A";
});

async function logout() {
    try {
        await adminApi.post("/logout");
    } finally {
        clearAdminUser();
        menuOpen.value = false;
        await router.replace({ name: "admin.login" });
    }
}
</script>
