<script setup lang="ts">
import { computed, reactive, ref } from "vue";
import { useRouter } from "vue-router";
import { auth } from "../composables/useAuth";
import { logout as apiLogout } from "../api/auth";
import { userApi } from "../api/user";

const router = useRouter();
const user = auth.state;

const userInitials = computed(() => {
    const name = user.user?.name?.trim();
    if (!name) return "?";
    const parts = name.split(/\s+/).filter(Boolean);
    if (parts.length === 0) return "?";
    const first = parts[0] ?? "";
    const last = parts.length > 1 ? (parts[parts.length - 1] ?? "") : "";
    const initials = last ? first.charAt(0) + last.charAt(0) : first.slice(0, 2);
    return initials.toUpperCase();
});

const roleLabel = computed(() => {
    const role = user.user?.role;
    if (!role) return "—";
    return role.charAt(0).toUpperCase() + role.slice(1);
});

function fmtDate(iso: string | null | undefined): string {
    if (!iso) return "—";
    const d = new Date(iso);
    return Number.isFinite(d.getTime())
        ? d.toLocaleDateString(undefined, { year: "numeric", month: "long", day: "numeric" })
        : "—";
}

// ── Change password ──────────────────────────────────────────────────────────
const pwForm = reactive({ current_password: "", new_password: "", new_password_confirmation: "" });
const pwSaving = ref(false);
const pwError  = ref<string | null>(null);
const pwNotice = ref<string | null>(null);

const pwValid = computed(() =>
    pwForm.current_password.length > 0 &&
    pwForm.new_password.length >= 8 &&
    pwForm.new_password === pwForm.new_password_confirmation
);

async function changePassword() {
    pwError.value  = null;
    pwNotice.value = null;

    if (pwForm.new_password.length < 8) {
        pwError.value = "New password must be at least 8 characters.";
        return;
    }
    if (pwForm.new_password !== pwForm.new_password_confirmation) {
        pwError.value = "New password and confirmation do not match.";
        return;
    }

    pwSaving.value = true;
    try {
        await userApi.post("/account/password", {
            current_password: pwForm.current_password,
            new_password: pwForm.new_password,
            new_password_confirmation: pwForm.new_password_confirmation,
        });
        pwNotice.value = "Password updated successfully.";
        pwForm.current_password = "";
        pwForm.new_password = "";
        pwForm.new_password_confirmation = "";
    } catch (e: any) {
        pwError.value = e?.response?.data?.message ?? "Failed to update password. Please try again.";
    } finally {
        pwSaving.value = false;
    }
}

// ── Sign out ──────────────────────────────────────────────────────────────────
const signingOut = ref(false);

async function handleLogout() {
    signingOut.value = true;
    try {
        await apiLogout();
    } finally {
        auth.logout();
        await router.push({ name: "login" });
    }
}
</script>

<template>
    <div class="acPage">
        <div class="acPageHead">
            <h1 class="acPageHead__title">Account</h1>
            <p class="acPageHead__sub">Manage your profile, security, and sign-in.</p>
        </div>

        <div class="acGrid">
            <!-- Profile -->
            <section class="acCard acCard--profile">
                <div class="acProfile">
                    <span class="acAvatar" aria-hidden="true">{{ userInitials }}</span>
                    <div class="acProfile__body">
                        <p class="acProfile__name">{{ user.user?.name ?? "—" }}</p>
                        <p class="acProfile__email">{{ user.user?.email ?? "—" }}</p>
                        <span class="acBadge">{{ roleLabel }}</span>
                    </div>
                </div>

                <div class="acDivider"></div>

                <dl class="acKv">
                    <div class="acKv__row">
                        <dt>Company</dt>
                        <dd>{{ user.user?.company_name ?? "Not assigned yet" }}</dd>
                    </div>
                    <div class="acKv__row">
                        <dt>Role</dt>
                        <dd>{{ roleLabel }}</dd>
                    </div>
                    <div class="acKv__row">
                        <dt>Member since</dt>
                        <dd>{{ fmtDate(user.user?.created_at) }}</dd>
                    </div>
                </dl>
            </section>

            <div class="acStack">
                <!-- Change password -->
                <section class="acCard">
                    <h2 class="acCard__title">Change password</h2>
                    <p class="acCard__sub">Use a strong password you don't use anywhere else.</p>

                    <div v-if="pwError" class="acAlert acAlert--error">{{ pwError }}</div>
                    <div v-if="pwNotice" class="acAlert acAlert--success">{{ pwNotice }}</div>

                    <form class="acForm" @submit.prevent="changePassword">
                        <label class="acField">
                            <span>Current password</span>
                            <input v-model="pwForm.current_password" type="password" autocomplete="current-password" required />
                        </label>

                        <div class="acFieldRow">
                            <label class="acField">
                                <span>New password</span>
                                <input v-model="pwForm.new_password" type="password" autocomplete="new-password" minlength="8" required />
                            </label>
                            <label class="acField">
                                <span>Confirm new password</span>
                                <input v-model="pwForm.new_password_confirmation" type="password" autocomplete="new-password" minlength="8" required />
                            </label>
                        </div>

                        <div class="acForm__actions">
                            <button class="acBtn acBtn--primary" type="submit" :disabled="pwSaving || !pwValid">
                                <span v-if="pwSaving" class="acSpinner"></span>
                                {{ pwSaving ? "Updating…" : "Update password" }}
                            </button>
                        </div>
                    </form>
                </section>

                <!-- Session -->
                <section class="acCard">
                    <h2 class="acCard__title">Session</h2>
                    <p class="acCard__sub">You're signed in on this device. Signing out will end your current session.</p>
                    <div class="acForm__actions">
                        <button class="acBtn acBtn--outline" type="button" :disabled="signingOut" @click="handleLogout">
                            <span v-if="signingOut" class="acSpinner acSpinner--dark"></span>
                            {{ signingOut ? "Signing out…" : "Sign out" }}
                        </button>
                    </div>
                </section>
            </div>
        </div>
    </div>
</template>

<style scoped>
/* ── Layout ──────────────────────────────────────────────────────────────── */
.acPage { display: flex; flex-direction: column; gap: 20px; }

.acPageHead__title { margin: 0; font-size: 1.9rem; font-weight: 700; letter-spacing: -0.015em; }
.acPageHead__sub { margin: 6px 0 0; color: var(--color-muted); font-size: 0.88rem; }

.acGrid { display: grid; grid-template-columns: 340px 1fr; gap: 18px; align-items: start; }
.acStack { display: flex; flex-direction: column; gap: 18px; }

/* ── Card ────────────────────────────────────────────────────────────────── */
.acCard { background: var(--color-surface); border: 1px solid var(--color-border); border-radius: 16px; padding: 22px; }
.acCard--profile { display: flex; flex-direction: column; gap: 18px; }
.acCard__title { margin: 0; font-size: 1.05rem; font-weight: 700; color: var(--color-text); }
.acCard__sub { margin: 4px 0 16px; font-size: 0.83rem; color: var(--color-muted); line-height: 1.5; }

/* ── Profile block ───────────────────────────────────────────────────────── */
.acProfile { display: flex; align-items: center; gap: 14px; }
.acAvatar {
    width: 58px; height: 58px; border-radius: 999px; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center;
    background: var(--color-primary); color: #fff;
    font-size: 1.15rem; font-weight: 700; letter-spacing: 0.02em;
    box-shadow: var(--shadow-sm);
}
.acProfile__body { min-width: 0; display: flex; flex-direction: column; gap: 4px; }
.acProfile__name { margin: 0; font-size: 1.05rem; font-weight: 700; color: var(--color-text); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.acProfile__email { margin: 0; font-size: 0.83rem; color: var(--color-muted); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

.acBadge {
    align-self: flex-start;
    display: inline-flex; align-items: center;
    margin-top: 2px; padding: 3px 10px; border-radius: 999px;
    background: var(--color-primary-soft); color: var(--color-primary);
    font-size: 0.68rem; font-weight: 700; text-transform: capitalize; letter-spacing: 0.02em;
}

.acDivider { height: 1px; background: var(--color-border); }

.acKv { margin: 0; display: flex; flex-direction: column; gap: 12px; }
.acKv__row { display: flex; align-items: baseline; justify-content: space-between; gap: 12px; }
.acKv__row dt { margin: 0; font-size: 0.82rem; color: var(--color-muted); }
.acKv__row dd { margin: 0; font-size: 0.86rem; font-weight: 600; color: var(--color-text); text-align: right; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

/* ── Alerts ──────────────────────────────────────────────────────────────── */
.acAlert { padding: 10px 14px; border-radius: 10px; font-size: 0.85rem; margin-bottom: 14px; line-height: 1.4; }
.acAlert--error   { background: var(--color-error-soft);   border: 1px solid var(--color-error-soft-border);   color: var(--color-error); }
.acAlert--success { background: var(--color-success-soft); border: 1px solid var(--color-success-soft-border); color: var(--color-success); }

/* ── Form ────────────────────────────────────────────────────────────────── */
.acForm { display: flex; flex-direction: column; gap: 14px; }
.acFieldRow { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.acField { display: flex; flex-direction: column; gap: 6px; font-size: 0.8rem; color: var(--color-muted); font-weight: 600; }
.acField input {
    height: 40px; padding: 0 12px; box-sizing: border-box;
    border: 1px solid var(--color-border); border-radius: 9px;
    background: var(--color-surface-2); color: var(--color-text);
    font-size: 0.87rem; font-family: inherit;
}
.acField input:focus {
    outline: none; border-color: color-mix(in srgb, var(--color-primary) 60%, var(--color-border));
    background: var(--color-surface); box-shadow: 0 0 0 3px var(--ring);
}
.acForm__actions { display: flex; }

/* ── Buttons ─────────────────────────────────────────────────────────────── */
.acBtn {
    display: inline-flex; align-items: center; gap: 8px;
    height: 40px; padding: 0 18px; border-radius: 9px;
    font-size: 0.85rem; font-weight: 600; cursor: pointer; border: none;
    transition: opacity 0.15s, background 0.15s;
}
.acBtn--primary { background: var(--color-primary); color: #fff; }
.acBtn--primary:hover:not(:disabled) { background: var(--color-primary-hover); }
.acBtn--primary:disabled { opacity: 0.5; cursor: not-allowed; }
.acBtn--outline { background: transparent; border: 1px solid var(--color-border-strong); color: var(--color-text); }
.acBtn--outline:hover:not(:disabled) { background: var(--color-surface-2); }
.acBtn--outline:disabled { opacity: 0.6; cursor: not-allowed; }

.acSpinner {
    width: 14px; height: 14px; border-radius: 50%;
    border: 2px solid rgba(255,255,255,0.35); border-top-color: #fff;
    animation: acSpin 0.7s linear infinite;
}
.acSpinner--dark { border-color: color-mix(in srgb, var(--color-text) 25%, transparent); border-top-color: var(--color-text); }
@keyframes acSpin { to { transform: rotate(360deg); } }

/* ── Responsive ──────────────────────────────────────────────────────────── */
@media (max-width: 860px) {
    .acGrid { grid-template-columns: 1fr; }
}
@media (max-width: 520px) {
    .acCard { padding: 18px; }
    .acFieldRow { grid-template-columns: 1fr; }
    .acKv__row dd { white-space: normal; text-align: right; }
}
</style>
