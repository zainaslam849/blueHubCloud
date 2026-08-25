<template>
    <div class="admin-auth">
        <section class="admin-auth__card admin-auth__card--enterprise">
            <header class="admin-auth__header">
                <div class="admin-auth__brand">
                    <img v-if="activeLogo" :src="activeLogo" :alt="siteName" class="admin-auth__logo" />
                    <span v-else class="admin-auth__kicker">{{ siteName }}</span>
                </div>
                <span class="admin-auth__kindBadge">Admin Login</span>
                <h1 class="admin-auth__title">Admin sign in</h1>
                <p class="admin-auth__hint">
                    Use an admin account to continue. This is the staff admin panel —
                    not the customer dashboard.
                </p>
            </header>

            <form
                class="admin-auth__form"
                @submit.prevent="submit"
                :aria-busy="loading ? 'true' : 'false'"
            >
                <div class="admin-floatField">
                    <input
                        id="admin-login-email"
                        v-model.trim="email"
                        class="admin-floatField__input"
                        type="email"
                        autocomplete="username"
                        required
                        placeholder=" "
                        :disabled="loading"
                    />
                    <label
                        class="admin-floatField__label"
                        for="admin-login-email"
                    >
                        Email
                    </label>
                </div>

                <div class="admin-floatField admin-floatField--withAction">
                    <input
                        id="admin-login-password"
                        v-model="password"
                        class="admin-floatField__input admin-floatField__input--withAction"
                        :type="showPassword ? 'text' : 'password'"
                        autocomplete="current-password"
                        required
                        placeholder=" "
                        :disabled="loading"
                    />
                    <label
                        class="admin-floatField__label"
                        for="admin-login-password"
                    >
                        Password
                    </label>

                    <button
                        type="button"
                        class="admin-floatField__action"
                        :aria-label="
                            showPassword ? 'Hide password' : 'Show password'
                        "
                        :title="
                            showPassword ? 'Hide password' : 'Show password'
                        "
                        :disabled="loading"
                        @click="showPassword = !showPassword"
                    >
                        {{ showPassword ? "Hide" : "Show" }}
                    </button>
                </div>

                <div class="admin-auth__row">
                    <label class="admin-toggle">
                        <input
                            v-model="remember"
                            class="admin-toggle__input"
                            type="checkbox"
                            :disabled="loading"
                        />
                        <span class="admin-toggle__track" aria-hidden="true">
                            <span class="admin-toggle__thumb"></span>
                        </span>
                        <span class="admin-toggle__label">Remember me</span>
                    </label>
                </div>

                <div
                    v-if="error"
                    class="admin-alert admin-alert--error"
                    role="alert"
                >
                    {{ error }}
                </div>

                <button
                    class="admin-btn admin-btn--wide"
                    type="submit"
                    :disabled="loading"
                >
                    <span
                        v-if="loading"
                        class="admin-btn__spinner"
                        aria-hidden="true"
                    ></span>
                    <span>{{ loading ? "Signing in…" : "Sign in" }}</span>
                </button>
            </form>
        </section>
    </div>
</template>

<script setup>
import { onMounted, onUnmounted, ref } from "vue";
import { useRouter } from "vue-router";

import adminApi, { setCsrfToken } from "../../router/admin/api";
import { setAdminUser } from "../../router/admin/auth";
import {
    applyResolvedTheme, getThemePreference, resolveTheme,
} from "../../admin/theme";

// Apply theme immediately on the login page too
applyResolvedTheme(resolveTheme(getThemePreference()));

// Branding
const activeLogo = ref("");
const siteName   = ref("BlueHubCloud");

function pickLogo(data) {
    const isDark = document.documentElement.dataset.theme === "dark";
    if (isDark && data.admin_logo_light_url) return normalizeAdminUrl(data.admin_logo_light_url);
    if (!isDark && data.admin_logo_dark_url) return normalizeAdminUrl(data.admin_logo_dark_url);
    return normalizeAdminUrl(data.admin_logo_url);
}
function normalizeAdminUrl(url) {
    if (!url) return "";
    return url.replace(/([^:])\/\/+/g, "$1/");
}

let _brandingData = null;
let _observer = null;

onMounted(async () => {
    try {
        const res = await adminApi.get("/settings");
        _brandingData = res?.data?.data || {};
        siteName.value = _brandingData.site_name || "BlueHubCloud";
        activeLogo.value = pickLogo(_brandingData);
    } catch { /* ignore */ }

    _observer = new MutationObserver(() => {
        if (_brandingData) activeLogo.value = pickLogo(_brandingData);
    });
    _observer.observe(document.documentElement, { attributes: true, attributeFilter: ["data-theme"] });
});

onUnmounted(() => { _observer?.disconnect(); });

const router = useRouter();

const email = ref("");
const password = ref("");
const remember = ref(false);
const showPassword = ref(false);
const error = ref("");
const loading = ref(false);

function getAuthErrorMessage(e) {
    const status = e?.response?.status;
    if (status === 403) {
        return "This account is not allowed in the admin area.";
    }

    const serverMessage = e?.response?.data?.message;
    if (typeof serverMessage === "string" && serverMessage.trim().length > 0) {
        return serverMessage;
    }

    const fieldErrors = e?.response?.data?.errors;
    if (fieldErrors && typeof fieldErrors === "object") {
        const first = Object.values(fieldErrors)
            .flat()
            .map((v) => String(v))
            .find((v) => v.trim().length > 0);
        if (first) return first;
    }

    return "Invalid email or password.";
}

async function submit() {
    if (loading.value) return;

    loading.value = true;
    error.value = "";

    try {
        const res = await adminApi.post("/login", {
            email: email.value,
            password: password.value,
            remember: remember.value,
        });

        if (res?.data?.csrf_token) {
            setCsrfToken(res.data.csrf_token);
        }

        setAdminUser(res?.data?.user ?? null);

        await router.replace("/dashboard");
    } catch (e) {
        error.value = getAuthErrorMessage(e);
    } finally {
        loading.value = false;
    }
}
</script>
