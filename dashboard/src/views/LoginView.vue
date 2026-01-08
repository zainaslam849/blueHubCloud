<script setup lang="ts">
import { ref } from "vue";
import { useRoute, useRouter } from "vue-router";
import { auth } from "../composables/useAuth";

const router = useRouter();
const route = useRoute();

const email = ref("");
const password = ref("");
const loading = ref(false);

async function onSubmit() {
    loading.value = true;

    try {
        // UI-only: replace with Laravel auth later.
        auth.setToken("demo-token");
        const redirect =
            typeof route.query.redirect === "string"
                ? route.query.redirect
                : "/dashboard";
        await router.replace(redirect);
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
                    <div class="brandMark" aria-hidden="true"></div>
                    <div>
                        <div class="brandName">BlueHub</div>
                        <div class="brandSub">SaaS Reporting</div>
                    </div>
                </div>

                <h1 class="h1">Sign in</h1>
                <p class="muted">Enter your account credentials.</p>
            </header>

            <form class="form" @submit.prevent="onSubmit">
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
                <span class="muted">UI-only layout • no backend calls</span>
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
}

.brandName {
    font-weight: 800;
}

.brandSub {
    opacity: 0.7;
    font-size: 0.95rem;
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

.foot {
    margin-top: var(--space-5);
}

.muted {
    margin: 0;
    opacity: 0.75;
}
</style>
