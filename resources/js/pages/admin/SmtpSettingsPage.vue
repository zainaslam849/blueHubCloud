<template>
    <div class="admin-container admin-page smtp-page">

        <header class="smtp-header">
            <h1 class="smtp-header__title">Email / SMTP</h1>
            <p class="smtp-header__sub">Configure outgoing mail for email verification and admin notifications.</p>
        </header>

        <div class="smtp-body">

            <!-- ── Connection ─────────────────────────────── -->
            <section class="section">
                <div class="section__label">
                    <h2 class="section__title">Connection</h2>
                </div>

                <div class="section__fields">
                    <div class="row">
                        <div class="field field--grow">
                            <label class="label">SMTP Host</label>
                            <input v-model="smtpHost" class="input" type="text" placeholder="smtp.gmail.com" autocomplete="off" />
                        </div>
                        <div class="field field--port">
                            <label class="label">Port</label>
                            <input v-model="smtpPort" class="input" type="number" placeholder="587" min="1" max="65535" />
                        </div>
                    </div>

                    <div class="field">
                        <label class="label">Encryption</label>
                        <div class="enc-group">
                            <label v-for="opt in encOptions" :key="opt.value" class="enc-opt" :class="{ 'is-on': smtpEncryption === opt.value }">
                                <input type="radio" :value="opt.value" v-model="smtpEncryption" class="sr-only" />
                                <span class="enc-opt__dot"></span>
                                <span class="enc-opt__text">{{ opt.label }}</span>
                                <span v-if="opt.recommended" class="enc-opt__rec">Recommended</span>
                            </label>
                        </div>
                    </div>
                </div>
            </section>

            <div class="divider"></div>

            <!-- ── Authentication ─────────────────────────── -->
            <section class="section">
                <div class="section__label">
                    <h2 class="section__title">Authentication</h2>
                    <p class="section__sub">Login credentials for your SMTP server.</p>
                </div>

                <div class="section__fields">
                    <div class="row">
                        <div class="field field--grow">
                            <label class="label">Username</label>
                            <input v-model="smtpUsername" class="input" type="text" placeholder="your@email.com" autocomplete="off" />
                        </div>
                        <div class="field field--grow">
                            <label class="label">Password</label>
                            <div class="pw-wrap">
                                <input
                                    v-model="smtpPassword"
                                    class="input input--pr"
                                    :type="showPw ? 'text' : 'password'"
                                    placeholder="Leave blank to keep existing"
                                    autocomplete="new-password"
                                />
                                <button type="button" class="pw-eye" @click="showPw = !showPw" :title="showPw ? 'Hide' : 'Show'">
                                    <svg v-if="!showPw" viewBox="0 0 24 24" fill="none"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8S1 12 1 12Z" stroke="currentColor" stroke-width="1.7"/><circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="1.7"/></svg>
                                    <svg v-else viewBox="0 0 24 24" fill="none"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/><line x1="1" y1="1" x2="23" y2="23" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <div class="divider"></div>

            <!-- ── Sender Identity ────────────────────────── -->
            <section class="section">
                <div class="section__label">
                    <h2 class="section__title">Sender Identity</h2>
                    <p class="section__sub">The "From" name and address shown to email recipients.</p>
                </div>

                <div class="section__fields">
                    <div class="row">
                        <div class="field field--grow">
                            <label class="label">From Address</label>
                            <input v-model="smtpFromAddress" class="input" type="email" placeholder="noreply@yourdomain.com" />
                        </div>
                        <div class="field field--grow">
                            <label class="label">From Name</label>
                            <input v-model="smtpFromName" class="input" type="text" placeholder="BlueHub" />
                        </div>
                    </div>
                </div>
            </section>

            <div class="divider"></div>

            <!-- ── Admin Notifications ────────────────────── -->
            <section class="section">
                <div class="section__label">
                    <h2 class="section__title">Admin Notifications</h2>
                    <p class="section__sub">Receives an alert when a new user registers and verifies their email.</p>
                </div>

                <div class="section__fields">
                    <div class="field">
                        <label class="label">Notification recipient</label>
                        <input v-model="adminNotificationEmail" class="input" type="email" placeholder="admin@yourdomain.com" style="max-width: 380px" />
                    </div>
                </div>
            </section>

            <!-- ── Actions ────────────────────────────────── -->
            <div class="actions">
                <BaseButton variant="primary" :loading="saving" :disabled="saving" @click="save">
                    <template v-if="saving">Saving…</template>
                    <template v-else>Save settings</template>
                </BaseButton>
                <Transition name="fade">
                    <span v-if="success" class="status status--ok">
                        <svg viewBox="0 0 24 24" fill="none"><path d="M20 6L9 17l-5-5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        Saved
                    </span>
                    <span v-else-if="error" class="status status--err">{{ error }}</span>
                </Transition>
            </div>

        </div>
    </div>
</template>

<script setup>
import { onMounted, ref } from "vue";
import { BaseButton } from "../../components/admin/base";
import adminApi from "../../router/admin/api";

const smtpHost               = ref("");
const smtpPort               = ref("");
const smtpEncryption         = ref("tls");
const smtpUsername           = ref("");
const smtpPassword           = ref("");
const smtpFromAddress        = ref("");
const smtpFromName           = ref("");
const adminNotificationEmail = ref("");

const saving  = ref(false);
const error   = ref("");
const success = ref(false);
const showPw  = ref(false);

const encOptions = [
    { value: "tls", label: "TLS", recommended: true },
    { value: "ssl", label: "SSL", recommended: false },
    { value: "",    label: "None", recommended: false },
];

async function loadSettings() {
    try {
        const res  = await adminApi.get("/settings");
        const data = res?.data?.data || {};
        smtpHost.value               = data.smtp_host ?? "";
        smtpPort.value               = data.smtp_port ?? "";
        smtpEncryption.value         = data.smtp_encryption ?? "tls";
        smtpUsername.value           = data.smtp_username ?? "";
        smtpFromAddress.value        = data.smtp_from_address ?? "";
        smtpFromName.value           = data.smtp_from_name ?? "";
        adminNotificationEmail.value = data.admin_notification_email ?? "";
    } catch {
        // ignore
    }
}

async function save() {
    if (saving.value) return;
    saving.value  = true;
    error.value   = "";
    success.value = false;
    try {
        await adminApi.post("/settings/smtp", {
            smtp_host:                smtpHost.value || null,
            smtp_port:                smtpPort.value ? Number(smtpPort.value) : null,
            smtp_encryption:          smtpEncryption.value || null,
            smtp_username:            smtpUsername.value || null,
            smtp_password:            smtpPassword.value || undefined,
            smtp_from_address:        smtpFromAddress.value || null,
            smtp_from_name:           smtpFromName.value || null,
            admin_notification_email: adminNotificationEmail.value || null,
        });
        success.value      = true;
        smtpPassword.value = "";
    } catch (e) {
        error.value = e?.response?.data?.message || "Failed to save email settings.";
    } finally {
        saving.value = false;
    }
}

onMounted(loadSettings);
</script>

<style scoped>

/* ── Page header ─────────────────────────────────────────── */
.smtp-header {
    margin-bottom: 32px;
}

.smtp-header__title {
    margin: 0 0 6px;
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--text-primary);
}

.smtp-header__sub {
    margin: 0;
    font-size: 0.875rem;
    color: var(--text-secondary);
}

/* ── Body card ───────────────────────────────────────────── */
.smtp-body {
    background: var(--bg-surface, #fff);
    border: 1px solid var(--border-soft, #e5e7eb);
    border-radius: 16px;
    overflow: hidden;
}

/* ── Section row ─────────────────────────────────────────── */
.section {
    display: grid;
    grid-template-columns: 220px 1fr;
    gap: 24px;
    padding: 28px 28px;
    align-items: start;
}

.section__title {
    margin: 0 0 4px;
    font-size: 0.9rem;
    font-weight: 700;
    color: var(--text-primary);
}

.section__sub {
    margin: 0;
    font-size: 0.8rem;
    color: var(--text-secondary);
    line-height: 1.55;
}

.section__fields {
    display: grid;
    gap: 18px;
}

/* ── Divider ─────────────────────────────────────────────── */
.divider {
    height: 1px;
    background: var(--border-soft, #e5e7eb);
    margin: 0;
}

/* ── Fields ──────────────────────────────────────────────── */
.row {
    display: flex;
    gap: 14px;
    align-items: flex-end;
}

.field {
    display: grid;
    gap: 6px;
}

.field--grow { flex: 1; min-width: 0; }
.field--port { width: 100px; flex-shrink: 0; }

.label {
    font-size: 0.8rem;
    font-weight: 600;
    color: var(--text-secondary);
}

.input {
    height: 40px;
    padding: 0 12px;
    border-radius: 8px;
    border: 1px solid var(--border-soft, #e5e7eb);
    background: var(--bg-surface-2, #f9fafb);
    font-size: 0.875rem;
    color: var(--text-primary);
    width: 100%;
    box-sizing: border-box;
    transition: border-color 0.15s, box-shadow 0.15s;
}

.input:focus {
    outline: none;
    border-color: var(--accent, #3b82f6);
    box-shadow: 0 0 0 3px color-mix(in srgb, var(--accent, #3b82f6) 15%, transparent);
    background: var(--bg-surface, #fff);
}

.input:-webkit-autofill,
.input:-webkit-autofill:hover,
.input:-webkit-autofill:focus {
    -webkit-box-shadow: 0 0 0 1000px var(--bg-surface-2, #f9fafb) inset;
    -webkit-text-fill-color: var(--text-primary);
    border-color: var(--border-soft, #e5e7eb);
    transition: background-color 5000s ease-in-out 0s;
}

.input--pr { padding-right: 40px; }

.input[type="number"]::-webkit-inner-spin-button,
.input[type="number"]::-webkit-outer-spin-button {
    opacity: 1;
    background: var(--bg-surface-2, #f9fafb);
    border-left: 1px solid var(--border-soft, #e5e7eb);
    cursor: pointer;
}

.input[type="number"] {
    -moz-appearance: textfield;
}

/* ── Password toggle ─────────────────────────────────────── */
.pw-wrap {
    position: relative;
}

.pw-wrap .input { width: 100%; }

.pw-eye {
    position: absolute;
    right: 10px;
    top: 50%;
    transform: translateY(-50%);
    width: 20px;
    height: 20px;
    background: none;
    border: none;
    cursor: pointer;
    color: var(--text-muted);
    padding: 0;
    display: grid;
    place-items: center;
    transition: color 0.15s;
}

.pw-eye:hover { color: var(--text-primary); }
.pw-eye svg { width: 16px; height: 16px; }

/* ── Encryption radio ────────────────────────────────────── */
.enc-group {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.enc-opt {
    display: flex;
    align-items: center;
    gap: 7px;
    padding: 8px 14px;
    border-radius: 8px;
    border: 1px solid var(--border-soft, #e5e7eb);
    background: var(--bg-surface-2, #f9fafb);
    cursor: pointer;
    user-select: none;
    transition: border-color 0.15s, background 0.15s;
}

.enc-opt.is-on {
    border-color: var(--accent, #3b82f6);
    background: color-mix(in srgb, var(--accent, #3b82f6) 7%, transparent);
}

.sr-only {
    position: absolute;
    width: 1px; height: 1px;
    overflow: hidden;
    clip: rect(0,0,0,0);
}

.enc-opt__dot {
    width: 14px;
    height: 14px;
    border-radius: 50%;
    border: 1.5px solid var(--border-soft, #d1d5db);
    flex-shrink: 0;
    position: relative;
    transition: border-color 0.15s;
}

.enc-opt.is-on .enc-opt__dot {
    border-color: var(--accent, #3b82f6);
}

.enc-opt.is-on .enc-opt__dot::after {
    content: "";
    position: absolute;
    inset: 2px;
    border-radius: 50%;
    background: var(--accent, #3b82f6);
}

.enc-opt__text {
    font-size: 0.85rem;
    font-weight: 600;
    color: var(--text-primary);
}

.enc-opt__rec {
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    padding: 2px 7px;
    border-radius: 999px;
    background: color-mix(in srgb, #10b981 13%, transparent);
    color: #059669;
    border: 1px solid color-mix(in srgb, #10b981 25%, transparent);
}

/* ── Actions ─────────────────────────────────────────────── */
.actions {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 20px 28px;
    border-top: 1px solid var(--border-soft, #e5e7eb);
    background: var(--bg-surface-2, #f9fafb);
}

.status {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 0.85rem;
    font-weight: 500;
}

.status svg { width: 15px; height: 15px; flex-shrink: 0; }
.status--ok  { color: #16a34a; }
.status--err { color: var(--error, #dc2626); }

/* ── Transition ──────────────────────────────────────────── */
.fade-enter-active, .fade-leave-active { transition: opacity 0.2s; }
.fade-enter-from, .fade-leave-to { opacity: 0; }

/* ── Responsive ──────────────────────────────────────────── */
@media (max-width: 640px) {
    .section { grid-template-columns: 1fr; gap: 14px; padding: 20px; }
    .row { flex-direction: column; }
    .field--port { width: 100%; }
}
</style>
