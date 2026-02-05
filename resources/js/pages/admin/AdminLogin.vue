<template>
    <div class="admin-auth">
        <section class="admin-auth__card admin-auth__card--enterprise">
            <header class="admin-auth__header">
                <p class="admin-auth__kicker">BlueHubCloud</p>
                <h1 class="admin-auth__title">Admin sign in</h1>
                <p class="admin-auth__hint">
                    Use an admin account to continue.
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
import { ref } from "vue";
import { useRoute, useRouter } from "vue-router";

import adminApi, { setCsrfToken } from "../../router/admin/api";
import { setAdminUser } from "../../router/admin/auth";

const router = useRouter();
const route = useRoute();

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

        const redirect =
            typeof route.query.redirect === "string"
                ? route.query.redirect
                : "/dashboard";
        await router.replace(redirect);
    } catch (e) {
        error.value = getAuthErrorMessage(e);
    } finally {
        loading.value = false;
    }
}
</script>
