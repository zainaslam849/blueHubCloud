<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from "vue";

type Props = {
    title: string;
    companyName: string;
};

defineProps<Props>();

defineEmits<{
    (e: "toggle-nav"): void;
}>();

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
                    <span class="avatar" aria-hidden="true"></span>
                    <span class="userLabel">User</span>
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
                        :to="{ name: 'usage' }"
                        role="menuitem"
                        @click="closeMenu"
                    >
                        Usage
                    </router-link>
                    <div class="menuSep"></div>
                    <button
                        class="menuItem"
                        type="button"
                        role="menuitem"
                        @click="closeMenu"
                    >
                        Sign out (UI)
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
    background: var(--surface);
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
    font-weight: 800;
    letter-spacing: 0.2px;
    white-space: nowrap;
}

.sep {
    opacity: 0.5;
}

.title {
    font-weight: 700;
    letter-spacing: 0.2px;
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
    border: 1px solid var(--border);
    background: var(--surface-2);
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
    background: var(--surface);
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
    font-weight: 650;
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
