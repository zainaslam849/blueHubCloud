<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from "vue";
import { auth } from "../../composables/useAuth";

type Props = {
    title: string;
    companyName: string;
};

defineProps<Props>();

const emit = defineEmits<{
    (e: "toggle-nav"): void;
    (e: "sign-out"): void;
}>();

const userName = computed(() => auth.state.user?.name ?? "Account");
const userInitials = computed(() => {
    const name = auth.state.user?.name?.trim();
    if (!name) return "?";
    const parts = name.split(/\s+/).filter(Boolean);
    if (parts.length === 0) return "?";
    const first = parts[0] ?? "";
    const last = parts.length > 1 ? (parts[parts.length - 1] ?? "") : "";
    const initials = last ? first.charAt(0) + last.charAt(0) : first.slice(0, 2);
    return initials.toUpperCase();
});

const menuOpen = ref(false);

function toggleMenu() {
    menuOpen.value = !menuOpen.value;
}

function closeMenu() {
    menuOpen.value = false;
}

function onDocClick(e: MouseEvent) {
    const target = e.target as HTMLElement | null;
    if (!target) return;
    if (
        target.closest("[data-user-menu]") ||
        target.closest("[data-user-trigger]")
    ) {
        return;
    }
    closeMenu();
}

onMounted(() => {
    document.addEventListener("click", onDocClick);
});

onBeforeUnmount(() => {
    document.removeEventListener("click", onDocClick);
});
</script>

<template>
    <header class="topbar">
        <div class="left">
            <button
                class="btn btn--ghost"
                type="button"
                @click="$emit('toggle-nav')"
            >
                Menu
            </button>
            <div class="crumbs">
                <span class="company">{{ companyName }}</span>
                <span class="sep">/</span>
                <span class="title">{{ title }}</span>
            </div>
        </div>

        <div class="right">
            <div class="user" data-user-menu>
                <button
                    class="btn btn--ghost userTrigger"
                    type="button"
                    data-user-trigger
                    :aria-expanded="menuOpen"
                    @click="toggleMenu"
                >
                    <span class="avatar" aria-hidden="true">{{ userInitials }}</span>
                    <span class="userLabel">{{ userName }}</span>
                </button>

                <div v-if="menuOpen" class="menu" role="menu">
                    <router-link
                        class="menuItem"
                        :to="{ name: 'account' }"
                        role="menuitem"
                        @click="closeMenu"
                    >
                        Account
                    </router-link>
                    <router-link
                        class="menuItem"
                        :to="{ name: 'billing' }"
                        role="menuitem"
                        @click="closeMenu"
                    >
                        Billing History
                    </router-link>
                    <div class="menuSep"></div>
                    <button
                        class="menuItem"
                        type="button"
                        role="menuitem"
                        @click="closeMenu(); emit('sign-out')"
                    >
                        Sign out
                    </button>
                </div>
            </div>
        </div>
    </header>
</template>

<style scoped>
.topbar {
    position: sticky;
    top: 0;
    z-index: 5;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-4);
    padding: var(--space-4) var(--space-6);
    border-bottom: 1px solid var(--border);
    background: color-mix(in srgb, var(--surface) 85%, transparent);
    backdrop-filter: blur(16px);
}

.left {
    display: flex;
    align-items: center;
    gap: var(--space-4);
    min-width: 0;
}

.crumbs {
    display: flex;
    align-items: center;
    gap: 10px;
    min-width: 0;
}

.company {
    font-weight: var(--weight-semibold);
    letter-spacing: var(--tracking-tight);
    white-space: nowrap;
}

.sep {
    opacity: 0.5;
}

.title {
    font-weight: var(--weight-semibold);
    letter-spacing: var(--tracking-tight);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.right {
    display: flex;
    align-items: center;
    gap: var(--space-3);
}

.user {
    position: relative;
}

.avatar {
    width: 34px;
    height: 34px;
    border-radius: 999px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--color-primary);
    color: #fff;
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 0.01em;
    flex-shrink: 0;
    box-shadow: var(--shadow-xs);
}

.userTrigger {
    gap: 10px;
}

.userLabel {
    font-weight: 700;
}

.menu {
    position: absolute;
    top: calc(100% + 8px);
    right: 0;
    width: 220px;
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    background: color-mix(in srgb, var(--surface) 94%, transparent);
    box-shadow: var(--shadow-lg);
    padding: 8px;
    z-index: 10;
}

.menuItem {
    width: 100%;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 10px 10px;
    border-radius: var(--radius-md);
    border: 1px solid transparent;
    background: transparent;
    color: inherit;
    text-decoration: none;
    cursor: pointer;
    font-weight: var(--weight-medium);
}

.menuItem:hover {
    background: var(--surface-2);
    border-color: var(--border);
    text-decoration: none;
}

.menuSep {
    height: 1px;
    margin: 8px 4px;
    background: var(--border);
}

@media (max-width: 960px) {
    .company {
        display: none;
    }
}
</style>
