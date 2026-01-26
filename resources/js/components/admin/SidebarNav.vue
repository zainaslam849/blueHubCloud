<template>
    <aside
        class="admin-sidebar"
        :class="{ 'is-collapsed': collapsed }"
        aria-label="Admin navigation"
    >
        <div class="admin-sidebar__brand">
            <div class="admin-sidebar__logo" aria-hidden="true"></div>
            <div v-if="!collapsed" class="admin-sidebar__brandText">
                <div class="admin-sidebar__app">BlueHubCloud</div>
                <div class="admin-sidebar__area">Admin</div>
            </div>

            <button
                type="button"
                class="admin-sidebar__collapseBtn"
                :aria-label="collapsed ? 'Expand sidebar' : 'Collapse sidebar'"
                :title="collapsed ? 'Expand sidebar' : 'Collapse sidebar'"
                @click="$emit('toggle-collapsed')"
            >
                <span class="admin-icon" aria-hidden="true">
                    <svg
                        viewBox="0 0 24 24"
                        fill="none"
                        xmlns="http://www.w3.org/2000/svg"
                    >
                        <path
                            d="M5 7h14"
                            stroke="currentColor"
                            stroke-width="1.8"
                            stroke-linecap="round"
                        />
                        <path
                            d="M5 12h14"
                            stroke="currentColor"
                            stroke-width="1.8"
                            stroke-linecap="round"
                        />
                        <path
                            d="M5 17h10"
                            stroke="currentColor"
                            stroke-width="1.8"
                            stroke-linecap="round"
                        />
                    </svg>
                </span>
            </button>
        </div>

        <nav class="admin-nav">
            <RouterLink
                v-for="item in items"
                :key="item.key"
                class="admin-nav__link"
                :to="item.to"
                :title="collapsed ? item.label : undefined"
            >
                <span class="admin-nav__icon" aria-hidden="true">
                    <svg
                        v-if="item.icon === 'dashboard'"
                        viewBox="0 0 24 24"
                        fill="none"
                        xmlns="http://www.w3.org/2000/svg"
                    >
                        <path
                            d="M4 13.5a2 2 0 0 0 2 2h2.5a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v7.5Z"
                            stroke="currentColor"
                            stroke-width="1.8"
                        />
                        <path
                            d="M13.5 18a2 2 0 0 0 2 2H18a2 2 0 0 0 2-2v-4.5a2 2 0 0 0-2-2h-2.5a2 2 0 0 0-2 2V18Z"
                            stroke="currentColor"
                            stroke-width="1.8"
                        />
                        <path
                            d="M13.5 8.5a2 2 0 0 0 2 2H18a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2h-2.5a2 2 0 0 0-2 2v2.5Z"
                            stroke="currentColor"
                            stroke-width="1.8"
                        />
                    </svg>

                    <svg
                        v-else-if="item.icon === 'calls'"
                        viewBox="0 0 24 24"
                        fill="none"
                        xmlns="http://www.w3.org/2000/svg"
                    >
                        <path
                            d="M8.5 5.5h7"
                            stroke="currentColor"
                            stroke-width="1.8"
                            stroke-linecap="round"
                        />
                        <path
                            d="M6.5 7.5v7a4 4 0 0 0 4 4h3a4 4 0 0 0 4-4v-7"
                            stroke="currentColor"
                            stroke-width="1.8"
                            stroke-linecap="round"
                        />
                        <path
                            d="M8.5 12h7"
                            stroke="currentColor"
                            stroke-width="1.8"
                            stroke-linecap="round"
                        />
                    </svg>

                    <svg
                        v-else-if="item.icon === 'transcriptions'"
                        viewBox="0 0 24 24"
                        fill="none"
                        xmlns="http://www.w3.org/2000/svg"
                    >
                        <path
                            d="M6 7h12"
                            stroke="currentColor"
                            stroke-width="1.8"
                            stroke-linecap="round"
                        />
                        <path
                            d="M6 12h8"
                            stroke="currentColor"
                            stroke-width="1.8"
                            stroke-linecap="round"
                        />
                        <path
                            d="M6 17h10"
                            stroke="currentColor"
                            stroke-width="1.8"
                            stroke-linecap="round"
                        />
                    </svg>

                    <svg
                        v-else-if="item.icon === 'reports'"
                        viewBox="0 0 24 24"
                        fill="none"
                        xmlns="http://www.w3.org/2000/svg"
                    >
                        <path
                            d="M4 4h16v16H4V4Z"
                            stroke="currentColor"
                            stroke-width="1.8"
                        />
                        <path
                            d="M8 14v2"
                            stroke="currentColor"
                            stroke-width="1.8"
                            stroke-linecap="round"
                        />
                        <path
                            d="M12 11v5"
                            stroke="currentColor"
                            stroke-width="1.8"
                            stroke-linecap="round"
                        />
                        <path
                            d="M16 8v8"
                            stroke="currentColor"
                            stroke-width="1.8"
                            stroke-linecap="round"
                        />
                    </svg>

                    <svg
                        v-else-if="item.icon === 'jobs'"
                        viewBox="0 0 24 24"
                        fill="none"
                        xmlns="http://www.w3.org/2000/svg"
                    >
                        <path
                            d="M7 7h10v10H7V7Z"
                            stroke="currentColor"
                            stroke-width="1.8"
                        />
                        <path
                            d="M7 11h10"
                            stroke="currentColor"
                            stroke-width="1.8"
                            stroke-linecap="round"
                        />
                        <path
                            d="M7 15h6"
                            stroke="currentColor"
                            stroke-width="1.8"
                            stroke-linecap="round"
                        />
                    </svg>

                    <svg
                        v-else-if="item.icon === 'users'"
                        viewBox="0 0 24 24"
                        fill="none"
                        xmlns="http://www.w3.org/2000/svg"
                    >
                        <path
                            d="M16 11a4 4 0 1 0-8 0 4 4 0 0 0 8 0Z"
                            stroke="currentColor"
                            stroke-width="1.8"
                        />
                        <path
                            d="M4.5 20a7.5 7.5 0 0 1 15 0"
                            stroke="currentColor"
                            stroke-width="1.8"
                            stroke-linecap="round"
                        />
                    </svg>

                    <svg
                        v-else-if="item.icon === 'settings'"
                        viewBox="0 0 24 24"
                        fill="none"
                        xmlns="http://www.w3.org/2000/svg"
                    >
                        <path
                            d="M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z"
                            stroke="currentColor"
                            stroke-width="1.8"
                        />
                        <path
                            d="M19.4 15a7.9 7.9 0 0 0 .1-6l-1.8.3a6.3 6.3 0 0 0-1.2-1.2l.3-1.8a7.9 7.9 0 0 0-6-.1l.3 1.8a6.3 6.3 0 0 0-1.2 1.2L7 9a7.9 7.9 0 0 0-.1 6l1.8-.3a6.3 6.3 0 0 0 1.2 1.2l-.3 1.8a7.9 7.9 0 0 0 6 .1l-.3-1.8a6.3 6.3 0 0 0 1.2-1.2l1.8.3Z"
                            stroke="currentColor"
                            stroke-width="1.4"
                            stroke-linejoin="round"
                        />
                    </svg>
                </span>
                <span v-if="!collapsed" class="admin-nav__label">{{
                    item.label
                }}</span>
            </RouterLink>
        </nav>

        <div class="admin-sidebar__footer">
            <div v-if="!collapsed" class="admin-sidebar__meta">
                Future: permissions • roles • feature flags
            </div>
        </div>
    </aside>
</template>

<script setup>
defineProps({
    items: {
        type: Array,
        required: true,
    },

    collapsed: {
        type: Boolean,
        default: false,
    },
});

defineEmits(["toggle-collapsed"]);
</script>
