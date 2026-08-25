<script setup lang="ts">
import { ref } from "vue";
import { useRoute, useRouter } from "vue-router";
import { login as apiLogin } from "../api/auth";
import { auth } from "../composables/useAuth";
import { useBranding } from "../composables/useBranding";

const { siteName, activeLogo } = useBranding();

const router = useRouter();
const route = useRoute();

const email = ref("");
const password = ref("");
const loading = ref(false);
const error = ref<string | null>(null);
const isSuspended = ref(false);

// A redirect value must be a relative in-app path — never an absolute URL.
// Vue Router's web-history mode treats a bare "https://..." string as a
// literal path segment (not a navigation target), which produces a broken
// doubled-domain URL like "site.com/https://site.com/calls". Guards against
// that regardless of where the value came from (query string, bookmark, etc).
function safeRedirect(value: unknown): string {
    if (typeof value !== "string" || !value.startsWith("/") || value.startsWith("//") || value.includes("://")) {
        return "/dashboard";
    }
    return value;
}

async function onSubmit() {
    error.value = null;
    isSuspended.value = false;
    loading.value = true;

    try {
        const user = await apiLogin(email.value, password.value);
        auth.setUser(user);
        await router.replace(safeRedirect(route.query.redirect));
    } catch (e: unknown) {
        const resp = (e as { response?: { status?: number; data?: { message?: string; code?: string; email?: string } } })?.response;
        const status = resp?.status;
        const code = resp?.data?.code;
        const message = resp?.data?.message;

        if (code === "account_suspended") {
            isSuspended.value = true;
            error.value = message ?? "Your account has been suspended. Please contact support.";
        } else if (code === "email_not_verified") {
            // Auto-redirect to the verify step on the register page
            await router.replace({ name: "register", query: { verify: resp?.data?.email ?? email.value } });
            return;
        } else if (status === 422) {
            error.value = "Invalid email or password.";
        } else if (status === 403) {
            error.value = "This account does not have user access.";
        } else {
            error.value = "Unable to sign in. Please try again.";
        }
    } finally {
        loading.value = false;
    }
}


</script>

<template>
    <main class="auth">
        <div class="authCard">
            <header class="authHeader">
                <div class="brandLine">
                    <div class="brandMark" :class="{ 'brandMark--hasLogo': !!activeLogo }" aria-hidden="true">
                        <img v-if="activeLogo" :src="activeLogo" :alt="siteName || 'Logo'" class="brandLogo" />
                    </div>
                    <div v-if="!activeLogo">
                        <div class="brandName">{{ siteName || 'BlueHub' }}</div>
                        <div class="brandSub">SaaS Reporting</div>
                    </div>
                </div>

                <span class="loginKindBadge">User Login</span>
                <h1 class="h1">Sign in</h1>
                <p class="muted">Enter your account credentials.</p>
            </header>

            <form class="form" @submit.prevent="onSubmit">
                <!-- Suspension banner -->
                <div v-if="isSuspended" class="suspendBanner">
                    <div class="suspendBanner__icon">🚫</div>
                    <div class="suspendBanner__body">
                        <strong>Account Suspended</strong>
                        <p>{{ error }}</p>
                    </div>
                </div>
                <!-- Generic error -->
                <div v-else-if="error" class="errorBanner">{{ error }}</div>

                <label class="field">
                    <span>Email</span>
                    <input
                        v-model="email"
                        class="input"
                        type="email"
                        autocomplete="email"
                        required
                    />
                </label>

                <label class="field">
                    <span>Password</span>
                    <input
                        v-model="password"
                        class="input"
                        type="password"
                        autocomplete="current-password"
                        required
                    />
                </label>

                <button
                    class="btn btn--primary"
                    type="submit"
                    :disabled="loading"
                >
                    {{ loading ? "Signing in…" : "Sign in" }}
                </button>
            </form>

            <footer class="foot">
                <span class="muted">
                    Don't have an account?
                    <router-link :to="{ name: 'register' }">Create one</router-link>
                </span>
            </footer>
        </div>
    </main>
</template>

<style scoped>
.auth {
    min-height: 100vh;
    display: grid;
    place-items: center;
    padding: var(--space-6);
}

.authCard {
    width: min(460px, 100%);
    border: 1px solid var(--border);
    border-radius: 16px;
    background: var(--surface);
    padding: var(--space-6);
}

.authHeader {
    margin-bottom: var(--space-5);
}

.brandLine {
    display: flex;
    align-items: center;
    gap: var(--space-3);
    margin-bottom: var(--space-5);
}

.brandMark {
    width: 40px;
    height: 40px;
    border-radius: 12px;
    border: 1px solid var(--border);
    background: var(--surface-2);
    overflow: hidden;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.brandMark--hasLogo {
    width: auto;
    height: 44px;
    min-width: 44px;
    max-width: 160px;
    background: transparent;
    border: none;
}
.brandLogo {
    height: 100%;
    width: auto;
    max-width: 160px;
    object-fit: contain;
    display: block;
}

.brandName {
    font-weight: 800;
}

.brandSub {
    opacity: 0.7;
    font-size: 0.95rem;
}

.loginKindBadge {
    display: inline-block;
    margin-bottom: 8px;
    padding: 3px 10px;
    border-radius: 999px;
    background: var(--color-primary-soft);
    color: var(--color-primary);
    font-size: 0.68rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}

.h1 {
    margin: 0;
    font-size: 1.7rem;
    line-height: 1.2;
}

.form {
    display: grid;
    gap: var(--space-4);
}

.field {
    display: grid;
    gap: 8px;
    font-weight: 600;
}

.input {
    /* global .input */
}

.errorBanner {
    background: color-mix(in srgb, var(--error, #e53e3e) 15%, transparent);
    border: 1px solid var(--error, #e53e3e);
    border-radius: 8px;
    padding: var(--space-3) var(--space-4);
    font-size: 0.9rem;
    color: var(--error, #e53e3e);
}


.suspendBanner {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    background: color-mix(in srgb, #f97316 12%, transparent);
    border: 1px solid color-mix(in srgb, #f97316 40%, transparent);
    border-radius: 10px;
    padding: 14px 16px;
}
.suspendBanner__icon { font-size: 22px; flex-shrink: 0; line-height: 1; }
.suspendBanner__body { display: flex; flex-direction: column; gap: 4px; }
.suspendBanner__body strong { font-size: 0.95rem; color: #ea580c; }
.suspendBanner__body p { margin: 0; font-size: 0.85rem; color: #c2410c; line-height: 1.5; }

.foot {
    margin-top: var(--space-5);
}

.muted {
    margin: 0;
    opacity: 0.75;
}
</style>
