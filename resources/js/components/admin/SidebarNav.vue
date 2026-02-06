<template>
    <aside
        class="admin-sidebar metronic-admin-sidebar"
        :class="{ 'is-collapsed': collapsed }"
        aria-label="Admin navigation"
    >
        <!-- Brand/Header -->
        <div class="admin-sidebar__brand metronic-brand">
            <div
                class="admin-sidebar__logo metronic-logo"
                :class="{ 'has-image': !!logoUrl }"
                aria-hidden="true"
            >
                <img
                    v-if="logoUrl"
                    class="admin-sidebar__logoImg"
                    :src="logoUrl"
                    alt=""
                />
                <span v-else class="admin-sidebar__logoInitial">
                    {{ logoInitial }}
                </span>
            </div>
            <div v-if="!collapsed" class="admin-sidebar__brandText">
                <div class="admin-sidebar__app">{{ appName }}</div>
                <div class="admin-sidebar__area">Admin</div>
            </div>

            <button
                type="button"
                class="admin-sidebar__collapseBtn metronic-collapseBtn"
                :aria-label="collapsed ? 'Expand sidebar' : 'Collapse sidebar'"
                :title="collapsed ? 'Expand sidebar' : 'Collapse sidebar'"
                @click="$emit('toggle-collapsed')"
            >
                <span class="admin-icon metronic-chevron" aria-hidden="true">
                    <svg
                        viewBox="0 0 24 24"
                        fill="none"
                        xmlns="http://www.w3.org/2000/svg"
                    >
                        <path
                            d="M15 18l-6-6 6-6"
                            stroke="currentColor"
                            stroke-width="2"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        />
                    </svg>
                </span>
            </button>
        </div>

        <!-- Nav Section -->
        <nav class="admin-nav admin-nav--metronic">
            <template v-for="item in items" :key="item.key">
                <RouterLink
                    v-if="!item.children"
                    class="admin-nav__link"
                    :to="item.to"
                    :title="collapsed ? item.label : undefined"
                >
                    <span class="admin-nav__icon" aria-hidden="true">
                        <NavIcon :name="item.icon" />
                    </span>
                    <span v-if="!collapsed" class="admin-nav__label">{{
                        item.label
                    }}</span>
                </RouterLink>

                <div
                    v-else
                    class="admin-nav__group"
                    :class="{ 'is-open': isGroupOpen(item) }"
                    @mouseenter="onGroupEnter(item)"
                    @mouseleave="onGroupLeave"
                >
                    <button
                        type="button"
                        class="admin-nav__link admin-nav__link--parent"
                        :title="collapsed ? item.label : undefined"
                        :aria-expanded="isGroupOpen(item)"
                        @click="toggleSection(item)"
                    >
                        <span class="admin-nav__icon" aria-hidden="true">
                            <NavIcon :name="item.icon" />
                        </span>
                        <span v-if="!collapsed" class="admin-nav__label">{{
                            item.label
                        }}</span>
                        <span
                            v-if="!collapsed"
                            class="admin-nav__chevron"
                            aria-hidden="true"
                        >
                            <span class="admin-nav__chevronDown">
                                <svg
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    xmlns="http://www.w3.org/2000/svg"
                                >
                                    <path
                                        d="M9 10l3 3 3-3"
                                        stroke="currentColor"
                                        stroke-width="1.8"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                    />
                                </svg>
                            </span>
                            <span class="admin-nav__chevronUp">
                                <svg
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    xmlns="http://www.w3.org/2000/svg"
                                >
                                    <path
                                        d="M9 14l3-3 3 3"
                                        stroke="currentColor"
                                        stroke-width="1.8"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                    />
                                </svg>
                            </span>
                        </span>
                    </button>

                    <Transition name="admin-nav-sub">
                        <div
                            v-if="isGroupOpen(item) && !collapsed"
                            class="admin-nav__children"
                        >
                            <RouterLink
                                v-for="child in item.children"
                                :key="child.key"
                                class="admin-nav__child"
                                :to="child.to"
                            >
                                <span
                                    class="admin-nav__childBullet"
                                    aria-hidden="true"
                                ></span>
                                <span class="admin-nav__childLabel">{{
                                    child.label
                                }}</span>
                            </RouterLink>
                        </div>
                    </Transition>

                    <Transition name="admin-nav-flyout">
                        <div
                            v-if="collapsed && hoverGroup === item.key"
                            class="admin-nav__flyout"
                        >
                            <div class="admin-nav__flyoutHeader">
                                {{ item.label }}
                            </div>
                            <div class="admin-nav__flyoutBody">
                                <RouterLink
                                    v-for="child in item.children"
                                    :key="child.key"
                                    class="admin-nav__child"
                                    :to="child.to"
                                >
                                    <span
                                        class="admin-nav__childBullet"
                                        aria-hidden="true"
                                    ></span>
                                    <span class="admin-nav__childLabel">{{
                                        child.label
                                    }}</span>
                                </RouterLink>
                            </div>
                        </div>
                    </Transition>
                </div>
            </template>
        </nav>

        <div class="admin-sidebar__footer metronic-footer">
            <div v-if="!collapsed" class="admin-sidebar__meta">
                Future: permissions • roles • feature flags
            </div>
        </div>
    </aside>
</template>

<script setup>
import { computed, h, ref, toRefs, watchEffect } from "vue";
import { useRoute } from "vue-router";

defineEmits(["toggle-collapsed"]);

const props = defineProps({
    items: {
        type: Array,
        required: true,
    },

    collapsed: {
        type: Boolean,
        default: false,
    },

    appName: {
        type: String,
        default: "BlueHubCloud",
    },

    logoUrl: {
        type: String,
        default: "",
    },
});

const { items, collapsed } = toRefs(props);

const logoInitial = computed(() => {
    const name = String(props.appName || "BlueHubCloud").trim();
    if (!name) return "B";
    const parts = name.split(/\s+/).filter(Boolean);
    const initials = parts.slice(0, 2).map((part) => part[0]?.toUpperCase());
    return initials.join("") || "B";
});

const route = useRoute();
const openSections = ref({});
const hoverGroup = ref(null);

const isItemActive = (item) => {
    if (typeof item.active === "function") {
        return item.active(route);
    }
    if (item.to?.name) {
        return route.name === item.to.name;
    }
    if (item.to?.path) {
        return route.path === item.to.path;
    }
    return false;
};

const isOpen = (item) => Boolean(openSections.value[item.key]);

const isGroupOpen = (item) =>
    isOpen(item) || (collapsed.value && hoverGroup.value === item.key);

const toggleSection = (item) => {
    openSections.value[item.key] = !openSections.value[item.key];
};

const onGroupEnter = (item) => {
    hoverGroup.value = item.key;
};

const onGroupLeave = () => {
    hoverGroup.value = null;
};

watchEffect(() => {
    items.value.forEach((item) => {
        if (item.children && item.children.length > 0) {
            const hasActiveChild = item.children.some((child) =>
                isItemActive(child),
            );
            if (hasActiveChild) {
                openSections.value[item.key] = true;
            }
        }
    });
});

const NavIcon = {
    props: { name: String },
    setup(iconProps) {
        return () => {
            switch (iconProps.name) {
                case "dashboard":
                    return h(
                        "svg",
                        {
                            viewBox: "0 0 24 24",
                            fill: "none",
                            xmlns: "http://www.w3.org/2000/svg",
                        },
                        [
                            h("path", {
                                d: "M4 13.5a2 2 0 0 0 2 2h2.5a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v7.5Z",
                                stroke: "currentColor",
                                "stroke-width": 1.8,
                            }),
                            h("path", {
                                d: "M13.5 18a2 2 0 0 0 2 2H18a2 2 0 0 0 2-2v-4.5a2 2 0 0 0-2-2h-2.5a2 2 0 0 0-2 2V18Z",
                                stroke: "currentColor",
                                "stroke-width": 1.8,
                            }),
                            h("path", {
                                d: "M13.5 8.5a2 2 0 0 0 2 2H18a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2h-2.5a2 2 0 0 0-2 2v2.5Z",
                                stroke: "currentColor",
                                "stroke-width": 1.8,
                            }),
                        ],
                    );
                case "calls":
                    return h(
                        "svg",
                        {
                            viewBox: "0 0 24 24",
                            fill: "none",
                            xmlns: "http://www.w3.org/2000/svg",
                        },
                        [
                            h("path", {
                                d: "M8.5 5.5h7",
                                stroke: "currentColor",
                                "stroke-width": 1.8,
                                "stroke-linecap": "round",
                            }),
                            h("path", {
                                d: "M6.5 7.5v7a4 4 0 0 0 4 4h3a4 4 0 0 0 4-4v-7",
                                stroke: "currentColor",
                                "stroke-width": 1.8,
                                "stroke-linecap": "round",
                            }),
                            h("path", {
                                d: "M8.5 12h7",
                                stroke: "currentColor",
                                "stroke-width": 1.8,
                                "stroke-linecap": "round",
                            }),
                        ],
                    );
                case "transcriptions":
                    return h(
                        "svg",
                        {
                            viewBox: "0 0 24 24",
                            fill: "none",
                            xmlns: "http://www.w3.org/2000/svg",
                        },
                        [
                            h("path", {
                                d: "M6 7h12",
                                stroke: "currentColor",
                                "stroke-width": 1.8,
                                "stroke-linecap": "round",
                            }),
                            h("path", {
                                d: "M6 12h8",
                                stroke: "currentColor",
                                "stroke-width": 1.8,
                                "stroke-linecap": "round",
                            }),
                            h("path", {
                                d: "M6 17h10",
                                stroke: "currentColor",
                                "stroke-width": 1.8,
                                "stroke-linecap": "round",
                            }),
                        ],
                    );
                case "reports":
                    return h(
                        "svg",
                        {
                            viewBox: "0 0 24 24",
                            fill: "none",
                            xmlns: "http://www.w3.org/2000/svg",
                        },
                        [
                            h("path", {
                                d: "M4 4h16v16H4V4Z",
                                stroke: "currentColor",
                                "stroke-width": 1.8,
                            }),
                            h("path", {
                                d: "M8 14v2",
                                stroke: "currentColor",
                                "stroke-width": 1.8,
                                "stroke-linecap": "round",
                            }),
                            h("path", {
                                d: "M12 11v5",
                                stroke: "currentColor",
                                "stroke-width": 1.8,
                                "stroke-linecap": "round",
                            }),
                            h("path", {
                                d: "M16 8v8",
                                stroke: "currentColor",
                                "stroke-width": 1.8,
                                "stroke-linecap": "round",
                            }),
                        ],
                    );
                case "jobs":
                    return h(
                        "svg",
                        {
                            viewBox: "0 0 24 24",
                            fill: "none",
                            xmlns: "http://www.w3.org/2000/svg",
                        },
                        [
                            h("path", {
                                d: "M7 7h10v10H7V7Z",
                                stroke: "currentColor",
                                "stroke-width": 1.8,
                            }),
                            h("path", {
                                d: "M7 11h10",
                                stroke: "currentColor",
                                "stroke-width": 1.8,
                                "stroke-linecap": "round",
                            }),
                            h("path", {
                                d: "M7 15h6",
                                stroke: "currentColor",
                                "stroke-width": 1.8,
                                "stroke-linecap": "round",
                            }),
                        ],
                    );
                case "categories":
                    return h(
                        "svg",
                        {
                            viewBox: "0 0 24 24",
                            fill: "none",
                            xmlns: "http://www.w3.org/2000/svg",
                        },
                        [
                            h("path", {
                                d: "M7 7h10v10H7V7Z",
                                stroke: "currentColor",
                                "stroke-width": 1.8,
                            }),
                            h("path", {
                                d: "M7 11h10",
                                stroke: "currentColor",
                                "stroke-width": 1.8,
                                "stroke-linecap": "round",
                            }),
                            h("path", {
                                d: "M7 15h6",
                                stroke: "currentColor",
                                "stroke-width": 1.8,
                                "stroke-linecap": "round",
                            }),
                        ],
                    );
                case "users":
                    return h(
                        "svg",
                        {
                            viewBox: "0 0 24 24",
                            fill: "none",
                            xmlns: "http://www.w3.org/2000/svg",
                        },
                        [
                            h("path", {
                                d: "M16 11a4 4 0 1 0-8 0 4 4 0 0 0 8 0Z",
                                stroke: "currentColor",
                                "stroke-width": 1.8,
                            }),
                            h("path", {
                                d: "M4.5 20a7.5 7.5 0 0 1 15 0",
                                stroke: "currentColor",
                                "stroke-width": 1.8,
                                "stroke-linecap": "round",
                            }),
                        ],
                    );
                case "settings":
                    return h(
                        "svg",
                        {
                            viewBox: "0 0 24 24",
                            fill: "none",
                            xmlns: "http://www.w3.org/2000/svg",
                        },
                        [
                            h("path", {
                                d: "M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z",
                                stroke: "currentColor",
                                "stroke-width": 1.8,
                            }),
                            h("path", {
                                d: "M19.4 15a7.9 7.9 0 0 0 .1-6l-1.8.3a6.3 6.3 0 0 0-1.2-1.2l.3-1.8a7.9 7.9 0 0 0-6-.1l.3 1.8a6.3 6.3 0 0 0-1.2 1.2L7 9a7.9 7.9 0 0 0-.1 6l1.8-.3a6.3 6.3 0 0 0 1.2 1.2l-.3 1.8a7.9 7.9 0 0 0 6 .1l-.3-1.8a6.3 6.3 0 0 0 1.2-1.2l1.8.3Z",
                                stroke: "currentColor",
                                "stroke-width": 1.4,
                                "stroke-linejoin": "round",
                            }),
                        ],
                    );
                default:
                    return null;
            }
        };
    },
};
</script>
