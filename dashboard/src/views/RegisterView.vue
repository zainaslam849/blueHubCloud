<script setup lang="ts">
import { ref, onMounted } from "vue";
import { useRouter, useRoute } from "vue-router";
import { register as apiRegister, verifyEmail as apiVerify, resendVerification } from "../api/auth";
import { useBranding } from "../composables/useBranding";

const { siteName, activeLogo } = useBranding();
import { auth } from "../composables/useAuth";

const router = useRouter();
const route  = useRoute();

// Step: "register" | "verify"
const step    = ref<"register" | "verify">("register");
const pending = ref(false);

// Register form
const name                = ref("");
const email               = ref("");
const password            = ref("");
const passwordConfirmation = ref("");
const registerError       = ref<string | null>(null);
const fieldErrors         = ref<Record<string, string>>({});

// Verify form
const pendingEmail = ref("");
const code         = ref("");
const verifyError  = ref<string | null>(null);
const resendMsg    = ref<string | null>(null);
const resending    = ref(false);

// If navigated here from login with ?verify=email, jump straight to verify step
onMounted(() => {
    const v = route.query.verify;
    if (typeof v === "string" && v) {
        pendingEmail.value = v;
        step.value = "verify";
    }
});

async function onRegister() {
    registerError.value = null;
    fieldErrors.value   = {};
    pending.value       = true;

    try {
        const res = await apiRegister(name.value, email.value, password.value, passwordConfirmation.value);
        pendingEmail.value = res.email;
        step.value = "verify";
    } catch (e: unknown) {
        const response = (e as { response?: { status?: number; data?: { errors?: Record<string, string[]>; message?: string } } })?.response;
        if (response?.status === 422) {
            const errors = response.data?.errors ?? {};
            const firstMessages: Record<string, string> = {};
            for (const [field, messages] of Object.entries(errors)) {
                firstMessages[field] = Array.isArray(messages) ? (messages[0] ?? String(messages)) : String(messages);
            }
            fieldErrors.value   = firstMessages;
            registerError.value = response.data?.message ?? "Please correct the errors below.";
        } else {
            registerError.value = "Unable to create account. Please try again.";
        }
    } finally {
        pending.value = false;
    }
}

async function onVerify() {
    verifyError.value = null;
    resendMsg.value   = null;
    pending.value     = true;

    try {
        const user = await apiVerify(pendingEmail.value, code.value.trim());
        auth.setUser(user);
        await router.replace("/select-plan");
    } catch (e: unknown) {
        const msg = (e as { response?: { data?: { message?: string } } })?.response?.data?.message;
        verifyError.value = msg ?? "The verification code is invalid or has expired.";
    } finally {
        pending.value = false;
    }
}

async function onResend() {
    resendMsg.value   = null;
    verifyError.value = null;
    resending.value   = true;
    try {
        await resendVerification(pendingEmail.value);
        code.value      = "";   // clear old code so user enters the new one
        resendMsg.value = "A new code has been sent — please check your email and enter the new code below.";
    } catch {
        verifyError.value = "Could not resend the code. Please try again.";
    } finally {
        resending.value = false;
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

                <template v-if="step === 'register'">
                    <h1 class="h1">Create account</h1>
                    <p class="muted">Sign up for BlueHub SaaS Reporting.</p>
                </template>
                <template v-else>
                    <h1 class="h1">Verify your email</h1>
                    <p class="muted">Enter the 6-digit code sent to <strong>{{ pendingEmail }}</strong>.</p>
                </template>
            </header>

            <!-- ── REGISTER STEP ──────────────────────────────── -->
            <form v-if="step === 'register'" class="form" @submit.prevent="onRegister">
                <div v-if="registerError" class="errorBanner">{{ registerError }}</div>

                <label class="field">
                    <span>Full name</span>
                    <input v-model="name" class="input" type="text" autocomplete="name" required />
                    <span v-if="fieldErrors.name" class="fieldError">{{ fieldErrors.name }}</span>
                </label>

                <label class="field">
                    <span>Email</span>
                    <input v-model="email" class="input" type="email" autocomplete="email" required />
                    <span v-if="fieldErrors.email" class="fieldError">{{ fieldErrors.email }}</span>
                </label>

                <label class="field">
                    <span>Password</span>
                    <input v-model="password" class="input" type="password" autocomplete="new-password" required />
                    <span v-if="fieldErrors.password" class="fieldError">{{ fieldErrors.password }}</span>
                </label>

                <label class="field">
                    <span>Confirm password</span>
                    <input v-model="passwordConfirmation" class="input" type="password" autocomplete="new-password" required />
                </label>

                <button class="btn btn--primary" type="submit" :disabled="pending">
                    {{ pending ? "Creating account…" : "Create account" }}
                </button>
            </form>

            <!-- ── VERIFY STEP ────────────────────────────────── -->
            <form v-else class="form" @submit.prevent="onVerify">
                <div v-if="verifyError" class="errorBanner">{{ verifyError }}</div>
                <div v-if="resendMsg" class="successBanner">{{ resendMsg }}</div>

                <div class="codeHint">
                    <svg viewBox="0 0 24 24" fill="none" class="codeHint__icon">
                        <rect x="3" y="3" width="18" height="18" rx="3" stroke="currentColor" stroke-width="1.8"/>
                        <path d="M8 12h8M12 8v8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                    </svg>
                    Check your inbox (and spam folder) for an email with a 6-digit code. It expires in 15 minutes.
                </div>

                <label class="field">
                    <span>Verification code</span>
                    <input
                        v-model="code"
                        class="input codeInput"
                        type="text"
                        inputmode="numeric"
                        maxlength="6"
                        placeholder="123456"
                        autocomplete="one-time-code"
                        required
                    />
                </label>

                <button class="btn btn--primary" type="submit" :disabled="pending || code.trim().length < 6">
                    {{ pending ? "Verifying…" : "Verify email" }}
                </button>

                <button
                    type="button"
                    class="btn btn--ghost"
                    :disabled="resending"
                    @click="onResend"
                >
                    {{ resending ? "Sending…" : "Resend code" }}
                </button>
            </form>

            <footer class="foot">
                <span class="muted">
                    Already have an account?
                    <router-link :to="{ name: 'login' }">Sign in</router-link>
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

.authHeader { margin-bottom: var(--space-5); }

.brandLine {
    display: flex; align-items: center;
    gap: var(--space-3); margin-bottom: var(--space-5);
}

.brandMark {
    width: 40px; height: 40px;
    border-radius: 12px;
    border: 1px solid var(--border);
    background: var(--surface-2);
    overflow: hidden;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
.brandMark--hasLogo {
    width: auto; height: 44px; min-width: 44px; max-width: 160px;
    background: transparent; border: none;
}
.brandLogo { height: 100%; width: auto; max-width: 160px; object-fit: contain; display: block; }
.brandName { font-weight: 800; }
.brandSub { opacity: 0.7; font-size: 0.95rem; }

.h1 { margin: 0; font-size: 1.7rem; line-height: 1.2; }

.form { display: grid; gap: var(--space-4); }

.field { display: grid; gap: 8px; font-weight: 600; }

.errorBanner {
    background: color-mix(in srgb, var(--error, #e53e3e) 15%, transparent);
    border: 1px solid var(--error, #e53e3e);
    border-radius: 8px;
    padding: var(--space-3) var(--space-4);
    font-size: 0.9rem;
    color: var(--error, #e53e3e);
}

.successBanner {
    background: color-mix(in srgb, #22c55e 12%, transparent);
    border: 1px solid #22c55e;
    border-radius: 8px;
    padding: var(--space-3) var(--space-4);
    font-size: 0.9rem;
    color: #15803d;
}

.fieldError { font-size: 0.82rem; color: var(--error, #e53e3e); font-weight: 400; }

.codeHint {
    display: flex; align-items: flex-start; gap: 10px;
    background: color-mix(in srgb, var(--color-primary, #2563eb) 8%, transparent);
    border: 1px solid color-mix(in srgb, var(--color-primary, #2563eb) 20%, transparent);
    border-radius: 8px;
    padding: var(--space-3) var(--space-4);
    font-size: 0.88rem;
    color: var(--color-muted, #6b7280);
    line-height: 1.5;
}
.codeHint__icon { width: 18px; height: 18px; flex-shrink: 0; color: var(--color-primary, #2563eb); margin-top: 1px; }

.codeInput {
    font-size: 1.8rem !important;
    letter-spacing: 0.3em;
    text-align: center;
    font-weight: 700;
}

.btn--ghost {
    background: transparent;
    border: 1px solid var(--border);
    color: var(--color-muted, #6b7280);
    border-radius: 8px;
    padding: 10px;
    font-size: 0.9rem;
    font-weight: 500;
    cursor: pointer;
    transition: background 150ms;
}
.btn--ghost:hover:not(:disabled) { background: var(--surface-2); }
.btn--ghost:disabled { opacity: .45; cursor: not-allowed; }

.foot { margin-top: var(--space-5); }
.muted { margin: 0; opacity: 0.75; }
</style>
