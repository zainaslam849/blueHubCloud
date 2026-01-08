<template>
    <button
        type="button"
        class="admin-themeToggle"
        :aria-label="label"
        :title="label"
        @click="toggle"
    >
        <span class="admin-themeToggle__icon" aria-hidden="true">
            <svg
                v-if="preference === 'system'"
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
                    d="M9 19h6"
                    stroke="currentColor"
                    stroke-width="1.8"
                    stroke-linecap="round"
                />
                <path
                    d="M10 5h4"
                    stroke="currentColor"
                    stroke-width="1.8"
                    stroke-linecap="round"
                />
            </svg>

            <svg
                v-else-if="resolved === 'dark'"
                viewBox="0 0 24 24"
                fill="none"
                xmlns="http://www.w3.org/2000/svg"
            >
                <path
                    d="M12 3v2"
                    stroke="currentColor"
                    stroke-width="1.8"
                    stroke-linecap="round"
                />
                <path
                    d="M12 19v2"
                    stroke="currentColor"
                    stroke-width="1.8"
                    stroke-linecap="round"
                />
                <path
                    d="M4.22 5.22 5.64 6.64"
                    stroke="currentColor"
                    stroke-width="1.8"
                    stroke-linecap="round"
                />
                <path
                    d="M18.36 17.36 19.78 18.78"
                    stroke="currentColor"
                    stroke-width="1.8"
                    stroke-linecap="round"
                />
                <path
                    d="M3 12h2"
                    stroke="currentColor"
                    stroke-width="1.8"
                    stroke-linecap="round"
                />
                <path
                    d="M19 12h2"
                    stroke="currentColor"
                    stroke-width="1.8"
                    stroke-linecap="round"
                />
                <path
                    d="M4.22 18.78 5.64 17.36"
                    stroke="currentColor"
                    stroke-width="1.8"
                    stroke-linecap="round"
                />
                <path
                    d="M18.36 6.64 19.78 5.22"
                    stroke="currentColor"
                    stroke-width="1.8"
                    stroke-linecap="round"
                />
                <path
                    d="M12 17a5 5 0 1 0 0-10 5 5 0 0 0 0 10Z"
                    stroke="currentColor"
                    stroke-width="1.8"
                />
            </svg>

            <svg
                v-else
                viewBox="0 0 24 24"
                fill="none"
                xmlns="http://www.w3.org/2000/svg"
            >
                <path
                    d="M21 13.2A7.6 7.6 0 0 1 10.8 3a6.9 6.9 0 1 0 10.2 10.2Z"
                    stroke="currentColor"
                    stroke-width="1.8"
                    stroke-linejoin="round"
                />
            </svg>
        </span>
    </button>
</template>

<script setup>
import { computed, onMounted, onUnmounted, ref } from "vue";

import {
    applyResolvedTheme,
    cycleThemePreference,
    getThemePreference,
    resolveTheme,
    setThemePreference,
} from "../../admin/theme";

const preference = ref(getThemePreference());
const resolved = ref(resolveTheme(preference.value));

let mediaQuery;
let mediaQueryListener;

function sync() {
    resolved.value = resolveTheme(preference.value);
    applyResolvedTheme(resolved.value);
}

onMounted(() => {
    sync();

    if (!window.matchMedia) return;

    mediaQuery = window.matchMedia("(prefers-color-scheme: dark)");
    mediaQueryListener = () => {
        if (preference.value !== "system") return;
        sync();
    };

    if (typeof mediaQuery.addEventListener === "function") {
        mediaQuery.addEventListener("change", mediaQueryListener);
    } else if (typeof mediaQuery.addListener === "function") {
        mediaQuery.addListener(mediaQueryListener);
    }
});

onUnmounted(() => {
    if (!mediaQuery || !mediaQueryListener) return;
    if (typeof mediaQuery.removeEventListener === "function") {
        mediaQuery.removeEventListener("change", mediaQueryListener);
    } else if (typeof mediaQuery.removeListener === "function") {
        mediaQuery.removeListener(mediaQueryListener);
    }
});

const label = computed(() => {
    if (preference.value === "system") return "Theme: System";
    return preference.value === "dark" ? "Theme: Dark" : "Theme: Light";
});

function toggle() {
    preference.value = cycleThemePreference(preference.value);
    setThemePreference(preference.value);
    resolved.value = resolveTheme(preference.value);
}
</script>
