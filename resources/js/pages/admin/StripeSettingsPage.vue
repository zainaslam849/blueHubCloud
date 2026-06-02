<template>
    <div class="admin-container admin-page stripe-page">

        <header class="stripe-header">
            <h1 class="stripe-header__title">Stripe</h1>
            <p class="stripe-header__sub">Configure your Stripe API keys to enable payment processing and plan purchases.</p>
        </header>

        <div class="stripe-body">

            <!-- ── Mode toggle ────────────────────────────── -->
            <section class="section">
                <div class="section__label">
                    <h2 class="section__title">Environment</h2>
                    <p class="section__sub">Choose whether to use Stripe test keys or live production keys.</p>
                </div>

                <div class="section__fields">
                    <div class="mode-group">
                        <label class="mode-opt" :class="{ 'is-on': testMode === true }">
                            <input type="radio" :value="true" v-model="testMode" class="sr-only" />
                            <span class="mode-opt__dot"></span>
                            <div>
                                <span class="mode-opt__label">Test mode</span>
                                <span class="mode-opt__hint">Use sandbox keys — no real charges</span>
                            </div>
                            <span class="mode-opt__chip mode-opt__chip--test">Safe</span>
                        </label>
                        <label class="mode-opt" :class="{ 'is-on': testMode === false }">
                            <input type="radio" :value="false" v-model="testMode" class="sr-only" />
                            <span class="mode-opt__dot"></span>
                            <div>
                                <span class="mode-opt__label">Live mode</span>
                                <span class="mode-opt__hint">Real payments — charges customers</span>
                            </div>
                            <span class="mode-opt__chip mode-opt__chip--live">Production</span>
                        </label>
                    </div>
                </div>
            </section>

            <div class="divider"></div>

            <!-- ── API Keys ───────────────────────────────── -->
            <section class="section">
                <div class="section__label">
                    <h2 class="section__title">API Keys</h2>
                    <p class="section__sub">
                        Found in your <a :href="dashboardUrl" target="_blank" rel="noopener" class="link">Stripe Dashboard</a> under Developers → API keys.
                    </p>
                </div>

                <div class="section__fields">
                    <div class="field">
                        <label class="label">Publishable key</label>
                        <div class="input-wrap">
                            <span class="key-prefix">{{ testMode ? 'pk_test_' : 'pk_live_' }}</span>
                            <input
                                v-model="publicKey"
                                class="input input--pl"
                                type="text"
                                :placeholder="testMode ? 'xxxxxxxxxxxxxxxxxxxx' : 'xxxxxxxxxxxxxxxxxxxx'"
                                autocomplete="off"
                                spellcheck="false"
                            />
                        </div>
                        <p class="field__hint">Safe to expose in the browser. Used by Stripe.js on the frontend.</p>
                    </div>

                    <div class="field">
                        <label class="label">Secret key</label>
                        <div class="input-wrap">
                            <span class="key-prefix">{{ testMode ? 'sk_test_' : 'sk_live_' }}</span>
                            <input
                                v-model="secretKey"
                                class="input input--pl input--pr"
                                :type="showSecret ? 'text' : 'password'"
                                placeholder="Leave blank to keep existing"
                                autocomplete="new-password"
                                spellcheck="false"
                            />
                            <button type="button" class="eye-btn" @click="showSecret = !showSecret">
                                <svg v-if="!showSecret" viewBox="0 0 24 24" fill="none"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8S1 12 1 12Z" stroke="currentColor" stroke-width="1.7"/><circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="1.7"/></svg>
                                <svg v-else viewBox="0 0 24 24" fill="none"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/><line x1="1" y1="1" x2="23" y2="23" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>
                            </button>
                        </div>
                        <p class="field__hint">Keep this private — never expose in the browser or commit to version control.</p>
                    </div>
                </div>
            </section>

            <div class="divider"></div>

            <!-- ── Webhook ────────────────────────────────── -->
            <section class="section">
                <div class="section__label">
                    <h2 class="section__title">Webhook</h2>
                    <p class="section__sub">Used to verify incoming events from Stripe are genuine.</p>
                </div>

                <div class="section__fields">
                    <div class="field">
                        <label class="label">Webhook endpoint URL</label>
                        <div class="copy-field">
                            <span class="copy-field__url">{{ webhookUrl }}</span>
                            <button type="button" class="copy-btn" @click="copyWebhook" :title="copied ? 'Copied!' : 'Copy URL'">
                                <svg v-if="!copied" viewBox="0 0 24 24" fill="none"><rect x="9" y="9" width="13" height="13" rx="2" stroke="currentColor" stroke-width="1.7"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>
                                <svg v-else viewBox="0 0 24 24" fill="none"><path d="M20 6L9 17l-5-5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </button>
                        </div>
                        <p class="field__hint">Add this URL in your Stripe Dashboard under Developers → Webhooks.</p>
                    </div>

                    <div class="field">
                        <label class="label">Webhook signing secret</label>
                        <div class="input-wrap">
                            <span class="key-prefix">whsec_</span>
                            <input
                                v-model="webhookSecret"
                                class="input input--pl input--pr"
                                :type="showWebhook ? 'text' : 'password'"
                                placeholder="Leave blank to keep existing"
                                autocomplete="new-password"
                                spellcheck="false"
                            />
                            <button type="button" class="eye-btn" @click="showWebhook = !showWebhook">
                                <svg v-if="!showWebhook" viewBox="0 0 24 24" fill="none"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8S1 12 1 12Z" stroke="currentColor" stroke-width="1.7"/><circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="1.7"/></svg>
                                <svg v-else viewBox="0 0 24 24" fill="none"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/><line x1="1" y1="1" x2="23" y2="23" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>
                            </button>
                        </div>
                        <p class="field__hint">Provided by Stripe when you create a webhook endpoint. Starts with <code>whsec_</code>.</p>
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
import { computed, onMounted, ref } from "vue";
import { BaseButton } from "../../components/admin/base";
import adminApi from "../../router/admin/api";

const publicKey     = ref("");
const secretKey     = ref("");
const webhookSecret = ref("");
const testMode      = ref(true);

const saving     = ref(false);
const error      = ref("");
const success    = ref(false);
const showSecret  = ref(false);
const showWebhook = ref(false);
const copied      = ref(false);

const dashboardUrl = computed(() =>
    testMode.value
        ? "https://dashboard.stripe.com/test/apikeys"
        : "https://dashboard.stripe.com/apikeys"
);

const webhookUrl = computed(() => `${window.location.origin}/stripe/webhook`);

function copyWebhook() {
    navigator.clipboard.writeText(webhookUrl.value).then(() => {
        copied.value = true;
        setTimeout(() => { copied.value = false; }, 2000);
    });
}

async function loadSettings() {
    try {
        const res  = await adminApi.get("/settings/stripe");
        const data = res?.data?.data || {};
        publicKey.value = data.stripe_public_key ?? "";
        testMode.value  = data.stripe_test_mode ?? true;
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
        await adminApi.post("/settings/stripe", {
            stripe_public_key:     publicKey.value || null,
            stripe_secret_key:     secretKey.value || undefined,
            stripe_webhook_secret: webhookSecret.value || undefined,
            stripe_test_mode:      testMode.value,
        });
        success.value      = true;
        secretKey.value    = "";
        webhookSecret.value = "";
    } catch (e) {
        error.value = e?.response?.data?.message || "Failed to save Stripe settings.";
    } finally {
        saving.value = false;
    }
}

onMounted(loadSettings);
</script>

<style scoped>
/* ── Header ──────────────────────────────────────────── */
.stripe-header {
    margin-bottom: 32px;
}
.stripe-header__title {
    margin: 0 0 6px;
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--text-primary);
}
.stripe-header__sub {
    margin: 0;
    font-size: 0.875rem;
    color: var(--text-secondary);
}

/* ── Body card ───────────────────────────────────────── */
.stripe-body {
    background: var(--bg-surface, #fff);
    border: 1px solid var(--border-soft, #e5e7eb);
    border-radius: 16px;
    overflow: hidden;
}

/* ── Section row ─────────────────────────────────────── */
.section {
    display: grid;
    grid-template-columns: 220px 1fr;
    gap: 24px;
    padding: 28px;
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
    gap: 20px;
}

.divider {
    height: 1px;
    background: var(--border-soft, #e5e7eb);
}

/* ── Mode toggle ─────────────────────────────────────── */
.mode-group {
    display: flex;
    flex-direction: column;
    gap: 10px;
}
.mode-opt {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 14px 16px;
    border-radius: 10px;
    border: 1.5px solid var(--border-soft, #e5e7eb);
    background: var(--bg-surface-2, #f9fafb);
    cursor: pointer;
    user-select: none;
    transition: border-color 0.15s, background 0.15s;
}
.mode-opt.is-on {
    border-color: var(--accent, #3b82f6);
    background: color-mix(in srgb, var(--accent, #3b82f6) 7%, transparent);
}
.sr-only { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0,0,0,0); }
.mode-opt__dot {
    width: 16px;
    height: 16px;
    border-radius: 50%;
    border: 1.5px solid var(--border-soft, #d1d5db);
    flex-shrink: 0;
    position: relative;
    transition: border-color 0.15s;
}
.mode-opt.is-on .mode-opt__dot { border-color: var(--accent, #3b82f6); }
.mode-opt.is-on .mode-opt__dot::after {
    content: "";
    position: absolute;
    inset: 2px;
    border-radius: 50%;
    background: var(--accent, #3b82f6);
}
.mode-opt__label {
    display: block;
    font-size: 0.88rem;
    font-weight: 600;
    color: var(--text-primary);
}
.mode-opt__hint {
    display: block;
    font-size: 0.78rem;
    color: var(--text-secondary);
    margin-top: 2px;
}
.mode-opt__chip {
    margin-left: auto;
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    padding: 3px 9px;
    border-radius: 999px;
}
.mode-opt__chip--test {
    background: color-mix(in srgb, #10b981 13%, transparent);
    color: #059669;
    border: 1px solid color-mix(in srgb, #10b981 25%, transparent);
}
.mode-opt__chip--live {
    background: color-mix(in srgb, #f59e0b 13%, transparent);
    color: #b45309;
    border: 1px solid color-mix(in srgb, #f59e0b 25%, transparent);
}

/* ── Fields ──────────────────────────────────────────── */
.field { display: grid; gap: 6px; }
.label {
    font-size: 0.8rem;
    font-weight: 600;
    color: var(--text-secondary);
}
.field__hint {
    margin: 0;
    font-size: 0.78rem;
    color: var(--text-muted, #9ca3af);
    line-height: 1.5;
}
.field__hint code {
    font-family: monospace;
    background: var(--bg-surface-2, #f3f4f6);
    padding: 1px 5px;
    border-radius: 4px;
    font-size: 0.85em;
}

.input-wrap { position: relative; display: flex; align-items: center; }
.input-wrap .input { width: 100%; }

.input {
    height: 40px;
    padding: 0 12px;
    border-radius: 8px;
    border: 1px solid var(--border-soft, #e5e7eb);
    background: var(--bg-surface-2, #f9fafb);
    font-size: 0.875rem;
    font-family: monospace;
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
.input--pl { padding-left: 80px; }
.input--pr { padding-right: 40px; }

.key-prefix {
    position: absolute;
    left: 12px;
    font-size: 0.78rem;
    font-family: monospace;
    color: var(--text-muted, #9ca3af);
    pointer-events: none;
    white-space: nowrap;
}

.eye-btn {
    position: absolute;
    right: 10px;
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
.eye-btn:hover { color: var(--text-primary); }
.eye-btn svg { width: 15px; height: 15px; }

/* ── Webhook copy field ───────────────────────────────── */
.copy-field {
    display: flex;
    align-items: center;
    gap: 0;
    border: 1px solid var(--border-soft, #e5e7eb);
    border-radius: 8px;
    overflow: hidden;
    background: var(--bg-surface-2, #f9fafb);
}
.copy-field__url {
    flex: 1;
    padding: 0 14px;
    font-size: 0.8rem;
    font-family: monospace;
    color: var(--text-secondary);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    line-height: 40px;
}
.copy-btn {
    flex-shrink: 0;
    width: 42px;
    height: 40px;
    display: grid;
    place-items: center;
    background: var(--bg-surface-2, #f3f4f6);
    border: none;
    border-left: 1px solid var(--border-soft, #e5e7eb);
    cursor: pointer;
    color: var(--text-secondary);
    transition: background 0.15s, color 0.15s;
}
.copy-btn:hover { background: var(--accent-hover-bg); color: var(--accent, #3b82f6); }
.copy-btn svg { width: 15px; height: 15px; }

.link {
    color: var(--accent, #3b82f6);
    text-decoration: none;
}
.link:hover { text-decoration: underline; }

/* ── Actions ─────────────────────────────────────────── */
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

.fade-enter-active, .fade-leave-active { transition: opacity 0.2s; }
.fade-enter-from, .fade-leave-to { opacity: 0; }

@media (max-width: 640px) {
    .section { grid-template-columns: 1fr; gap: 14px; padding: 20px; }
}
</style>
