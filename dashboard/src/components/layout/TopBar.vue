<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from "vue";
import { useRouter } from "vue-router";
import { auth } from "../../composables/useAuth";

type Props = {
    title: string;
    companyName: string;
    credits?: number | null;
    creditsLoaded?: boolean;
};

const props = defineProps<Props>();

const emit = defineEmits<{
    (e: "toggle-nav"): void;
    (e: "sign-out"): void;
}>();

const router = useRouter();
const searchQuery = ref("");

function submitSearch() {
    const q = searchQuery.value.trim();
    if (!q) return;
    router.push({ name: "calls", query: { q } });
}

const creditsLabel = computed(() =>
    props.credits == null ? "—" : props.credits.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }),
);

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
                class="navToggle"
                type="button"
                aria-label="Toggle navigation"
                @click="$emit('toggle-nav')"
            >
                <svg viewBox="0 0 24 24" fill="none"><path d="M4 7h16M4 12h16M4 17h16" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
            </button>

            <form class="searchWrap" @submit.prevent="submitSearch">
                <svg class="searchIcon" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="11" cy="11" r="7.2" stroke="currentColor" stroke-width="1.8"/><path d="m16.5 16.5 4 4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                <input
                    v-model="searchQuery"
                    type="search"
                    class="searchInput"
                    placeholder="Search calls…"
                    aria-label="Search calls"
                />
            </form>
        </div>

        <div class="right">
            <router-link
                v-if="creditsLoaded"
                :to="{ name: 'select-plan' }"
                class="creditsPill"
                :title="`${creditsLabel} credits`"
            >
                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="8.4" stroke="currentColor" stroke-width="1.9"/><path d="M12 8.3v7.4M8.3 12h7.4" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/></svg>
                <span>{{ creditsLabel }}</span>
                <span class="creditsPill__label">credits</span>
            </router-link>

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
    gap: var(--space-3);
    min-width: 0;
    flex: 1 1 auto;
}

.navToggle {
    display: none;
    flex-shrink: 0;
    width: 38px;
    height: 38px;
    border-radius: 10px;
    border: 1px solid var(--color-border);
    background: var(--color-surface);
    color: var(--color-text);
    align-items: center;
    justify-content: center;
    cursor: pointer;
}

.navToggle svg { width: 18px; height: 18px; }

.searchWrap {
    position: relative;
    flex: 1 1 auto;
    max-width: 380px;
    display: flex;
    align-items: center;
}

.searchIcon {
    position: absolute;
    left: 13px;
    width: 16px;
    height: 16px;
    color: var(--color-muted);
    pointer-events: none;
}

.searchInput {
    width: 100%;
    height: 38px;
    padding: 0 13px 0 38px;
    border-radius: 10px;
    border: 1px solid var(--color-border);
    background: var(--color-surface-2);
    color: var(--color-text);
    font-size: 0.85rem;
    font-family: inherit;
}

.searchInput::placeholder { color: var(--color-muted); }

.searchInput:focus {
    outline: none;
    border-color: color-mix(in srgb, var(--color-primary) 60%, var(--color-border));
    background: var(--color-surface);
    box-shadow: 0 0 0 3px var(--ring);
}

.creditsPill {
    display: flex;
    align-items: center;
    gap: 6px;
    height: 34px;
    padding: 0 13px;
    border-radius: 999px;
    background: var(--color-primary-soft);
    border: 1px solid var(--color-primary-soft-border);
    color: var(--color-primary);
    text-decoration: none;
    font-size: 0.8rem;
    font-weight: 500;
    white-space: nowrap;
}

.creditsPill:hover { text-decoration: none; filter: brightness(0.97); }

.creditsPill svg { width: 14px; height: 14px; flex-shrink: 0; }

.creditsPill__label {
    color: color-mix(in srgb, var(--color-primary) 75%, var(--color-muted));
    font-weight: 400;
}

@media (max-width: 960px) {
    .navToggle { display: flex; }
}

@media (max-width: 720px) {
    .searchWrap { display: none; }
    .creditsPill__label { display: none; }
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

</style>
