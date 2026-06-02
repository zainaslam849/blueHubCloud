<template>
    <div class="admin-container admin-page ud-page">

        <!-- Back -->
        <div class="ud-back">
            <RouterLink :to="{ name: 'admin.users' }" class="ud-backLink">
                <svg viewBox="0 0 20 20" fill="none"><path d="M12 4l-6 6 6 6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                Back to Users
            </RouterLink>
        </div>

        <!-- Loading -->
        <div v-if="loading" class="ud-loading">
            <div class="ud-spinner"></div>
            <span>Loading user details…</span>
        </div>

        <template v-else-if="data">

            <!-- ── Profile hero ──────────────────────────────── -->
            <div class="ud-hero">
                <div class="ud-hero__avatar" :style="{ background: avatarColor(data.user.name) }">
                    {{ initials(data.user.name) }}
                </div>
                <div class="ud-hero__info">
                    <h1 class="ud-hero__name">{{ data.user.name }}</h1>
                    <div class="ud-hero__email">{{ data.user.email }}</div>
                    <div class="ud-hero__meta">
                        <span class="ud-badge" :class="data.user.account_status === 'suspended' ? 'ud-badge--warn' : 'ud-badge--ok'">
                            {{ data.user.account_status === 'suspended' ? 'Suspended' : 'Active' }}
                        </span>
                        <span class="ud-badge ud-badge--purple" v-if="data.user.email_verified_at">Verified</span>
                        <span class="ud-badge ud-badge--gray" v-else>Unverified</span>
                        <span class="ud-dot">·</span>
                        <span class="ud-meta__val">Joined {{ fmtDate(data.user.created_at) }}</span>
                    </div>
                </div>

                <!-- Quick stats -->
                <div class="ud-stats">
                    <div class="ud-stat">
                        <div class="ud-stat__val">${{ Number(data.total_spent).toFixed(2) }}</div>
                        <div class="ud-stat__label">Total Spent</div>
                    </div>
                    <div class="ud-stat">
                        <div class="ud-stat__val">{{ data.total_purchases }}</div>
                        <div class="ud-stat__label">Purchases</div>
                    </div>
                    <div class="ud-stat">
                        <div class="ud-stat__val">{{ data.minute_balance?.available_minutes?.toLocaleString() ?? '—' }}</div>
                        <div class="ud-stat__label">Minutes Left</div>
                    </div>
                </div>
            </div>

            <div class="ud-grid">

                <!-- ── Left column ───────────────────────────── -->
                <div class="ud-col">

                    <!-- Company card -->
                    <section class="ud-card">
                        <div class="ud-card__head">
                            <svg viewBox="0 0 20 20" fill="none"><path d="M3 17V5a2 2 0 0 1 2-2h4v14" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M9 17V7a2 2 0 0 1 2-2h6v12" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg>
                            Company
                        </div>
                        <div class="ud-card__body">
                            <template v-if="data.company">
                                <div class="ud-kv">
                                    <span class="ud-kv__k">Name</span>
                                    <span class="ud-kv__v">{{ data.company.name }}</span>
                                </div>
                                <div class="ud-kv">
                                    <span class="ud-kv__k">Company ID</span>
                                    <span class="ud-kv__v ud-mono">#{{ data.company.id }}</span>
                                </div>
                            </template>
                            <div v-else class="ud-empty-mini">No company assigned</div>
                        </div>
                    </section>

                    <!-- Minute balance card -->
                    <section class="ud-card">
                        <div class="ud-card__head">
                            <svg viewBox="0 0 20 20" fill="none"><circle cx="10" cy="10" r="8" stroke="currentColor" stroke-width="1.5"/><path d="M10 6v4l2.5 2.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
                            Minute Balance
                        </div>
                        <div class="ud-card__body">
                            <template v-if="data.minute_balance">
                                <div class="ud-kv">
                                    <span class="ud-kv__k">Plan</span>
                                    <span class="ud-kv__v">{{ data.minute_balance.plan_name ?? '—' }}</span>
                                </div>
                                <div class="ud-kv">
                                    <span class="ud-kv__k">Purchased</span>
                                    <span class="ud-kv__v">{{ data.minute_balance.purchased_minutes.toLocaleString() }} min</span>
                                </div>
                                <div class="ud-kv">
                                    <span class="ud-kv__k">Used</span>
                                    <span class="ud-kv__v">{{ data.minute_balance.used_minutes.toLocaleString() }} min</span>
                                </div>
                                <div class="ud-kv">
                                    <span class="ud-kv__k">Available</span>
                                    <span class="ud-kv__v ud-green">{{ data.minute_balance.available_minutes.toLocaleString() }} min</span>
                                </div>
                                <div class="ud-bar-wrap">
                                    <div class="ud-bar">
                                        <div class="ud-bar__fill" :style="{ width: minutesPct + '%' }" :class="minutesPct < 20 ? 'ud-bar__fill--low' : ''"></div>
                                    </div>
                                    <span class="ud-bar__pct">{{ minutesPct }}% remaining</span>
                                </div>
                            </template>
                            <div v-else class="ud-empty-mini">No minutes purchased yet</div>
                        </div>
                    </section>

                </div>

                <!-- ── Right column — billing history ────────── -->
                <div class="ud-col ud-col--wide">
                    <section class="ud-card">
                        <div class="ud-card__head">
                            <svg viewBox="0 0 20 20" fill="none"><rect x="2" y="5" width="16" height="12" rx="2" stroke="currentColor" stroke-width="1.5"/><path d="M2 9h16" stroke="currentColor" stroke-width="1.5"/><path d="M6 13h2M10 13h4" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>
                            Billing History
                            <span class="ud-card__count">{{ data.purchases.length }}</span>
                        </div>

                        <div v-if="data.purchases.length === 0" class="ud-empty-mini ud-empty-mini--pad">
                            No purchases found for this user.
                        </div>

                        <div v-else class="ud-billing">
                            <div class="ud-billing__head">
                                <span>Plan</span>
                                <span>Minutes</span>
                                <span>Amount</span>
                                <span>Status</span>
                                <span>Date</span>
                                <span>Stripe ID</span>
                            </div>

                            <div v-for="p in data.purchases" :key="p.id" class="ud-billing__row">
                                <!-- Plan name -->
                                <div class="ud-billing__cell">
                                    <span class="ud-billing__plan">{{ p.plan_name }}</span>
                                </div>

                                <!-- Minutes -->
                                <div class="ud-billing__cell">
                                    <span class="ud-billing__min">{{ p.minutes_added?.toLocaleString() ?? '—' }} min</span>
                                </div>

                                <!-- Amount -->
                                <div class="ud-billing__cell">
                                    <span class="ud-billing__amount">${{ Number(p.amount_paid).toFixed(2) }}</span>
                                    <span class="ud-billing__cur">{{ p.currency }}</span>
                                </div>

                                <!-- Status -->
                                <div class="ud-billing__cell">
                                    <span class="ud-status" :class="`ud-status--${p.status}`">{{ p.status }}</span>
                                </div>

                                <!-- Date -->
                                <div class="ud-billing__cell">
                                    <span class="ud-billing__date">{{ fmtDate(p.purchased_at ?? p.created_at) }}</span>
                                </div>

                                <!-- Stripe IDs -->
                                <div class="ud-billing__cell ud-billing__cell--ids">
                                    <div v-if="p.stripe_session_id" class="ud-id-row">
                                        <span class="ud-id-label">Session</span>
                                        <code class="ud-id-val" :title="p.stripe_session_id">{{ truncate(p.stripe_session_id) }}</code>
                                        <button class="ud-copy" @click="copy(p.stripe_session_id)" title="Copy session ID">
                                            <svg viewBox="0 0 16 16" fill="none"><rect x="5" y="5" width="9" height="9" rx="1.5" stroke="currentColor" stroke-width="1.4"/><path d="M11 5V3.5A1.5 1.5 0 0 0 9.5 2h-6A1.5 1.5 0 0 0 2 3.5v6A1.5 1.5 0 0 0 3.5 11H5" stroke="currentColor" stroke-width="1.4"/></svg>
                                        </button>
                                    </div>
                                    <div v-if="p.stripe_payment_intent_id" class="ud-id-row">
                                        <span class="ud-id-label">Payment</span>
                                        <code class="ud-id-val" :title="p.stripe_payment_intent_id">{{ truncate(p.stripe_payment_intent_id) }}</code>
                                        <button class="ud-copy" @click="copy(p.stripe_payment_intent_id)" title="Copy payment intent ID">
                                            <svg viewBox="0 0 16 16" fill="none"><rect x="5" y="5" width="9" height="9" rx="1.5" stroke="currentColor" stroke-width="1.4"/><path d="M11 5V3.5A1.5 1.5 0 0 0 9.5 2h-6A1.5 1.5 0 0 0 2 3.5v6A1.5 1.5 0 0 0 3.5 11H5" stroke="currentColor" stroke-width="1.4"/></svg>
                                        </button>
                                    </div>
                                    <span v-if="!p.stripe_session_id && !p.stripe_payment_intent_id" class="ud-muted">—</span>
                                </div>
                            </div>
                        </div>
                    </section>
                </div>

            </div>
        </template>

        <div v-else class="ud-loading">
            <span>User not found.</span>
        </div>

    </div>

    <!-- Copy toast -->
    <Transition name="ud-toast">
        <div v-if="toastVisible" class="ud-toast">
            <svg viewBox="0 0 16 16" fill="none"><path d="M3 8l3 3 7-7" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
            {{ toast }}
        </div>
    </Transition>
</template>

<script setup>
import { computed, onMounted, ref } from "vue";
import { useRoute, RouterLink } from "vue-router";
import adminApi from "../../router/admin/api";

const route  = useRoute();
const loading = ref(true);
const data    = ref(null);

const COLORS = ["#6366f1","#3b82f6","#10b981","#f59e0b","#ef4444","#8b5cf6","#06b6d4","#f97316"];
function avatarColor(name) {
    let h = 0;
    for (let i = 0; i < name.length; i++) h = name.charCodeAt(i) + ((h << 5) - h);
    return COLORS[Math.abs(h) % COLORS.length];
}
function initials(name) {
    return name.split(" ").slice(0, 2).map(p => p[0]?.toUpperCase()).join("");
}
function fmtDate(iso) {
    if (!iso) return "—";
    return new Date(iso).toLocaleDateString("en-US", { year: "numeric", month: "short", day: "numeric" });
}
function truncate(str) {
    if (!str) return "";
    return str.length > 20 ? str.slice(0, 10) + "…" + str.slice(-8) : str;
}
// ── Copy toast ─────────────────────────────────────────
const toast        = ref("");
const toastVisible = ref(false);
let   toastTimer   = null;

function copy(str) {
    navigator.clipboard.writeText(str).then(() => {
        toast.value = "Copied!";
        toastVisible.value = true;
        if (toastTimer) clearTimeout(toastTimer);
        toastTimer = setTimeout(() => { toastVisible.value = false; }, 2000);
    }).catch(() => {});
}

const minutesPct = computed(() => {
    const b = data.value?.minute_balance;
    if (!b || b.purchased_minutes === 0) return 0;
    return Math.round((b.available_minutes / b.purchased_minutes) * 100);
});

async function load() {
    loading.value = true;
    try {
        const res = await adminApi.get(`/users/${route.params.id}`);
        data.value = res.data.data;
    } catch {
        data.value = null;
    } finally {
        loading.value = false;
    }
}

onMounted(load);
</script>

<style scoped>
/* ── Back link ───────────────────────────────────────── */
.ud-back { margin-bottom: 20px; }
.ud-backLink {
    display: inline-flex; align-items: center; gap: 6px;
    font-size: 0.875rem; font-weight: 600;
    color: var(--text-secondary);
    text-decoration: none;
    transition: color 0.15s;
}
.ud-backLink:hover { color: var(--accent, #3b82f6); }
.ud-backLink svg { width: 16px; height: 16px; }

/* ── Loading ─────────────────────────────────────────── */
.ud-loading {
    display: flex; align-items: center; justify-content: center;
    gap: 12px; padding: 60px 0;
    color: var(--text-secondary); font-size: 0.9rem;
}
.ud-spinner {
    width: 24px; height: 24px;
    border: 2.5px solid var(--border-soft, #e5e7eb);
    border-top-color: var(--accent, #3b82f6);
    border-radius: 50%;
    animation: spin .7s linear infinite;
}
@keyframes spin { to { transform: rotate(360deg); } }

/* ── Hero ────────────────────────────────────────────── */
.ud-hero {
    display: flex;
    align-items: center;
    gap: 20px;
    padding: 24px 28px;
    background: var(--bg-surface, #fff);
    border: 1px solid var(--border-soft, #e5e7eb);
    border-radius: 16px;
    margin-bottom: 20px;
    flex-wrap: wrap;
}

.ud-hero__avatar {
    width: 64px; height: 64px; border-radius: 50%;
    display: grid; place-items: center;
    font-size: 1.3rem; font-weight: 800; color: #fff;
    flex-shrink: 0;
    box-shadow: 0 4px 14px rgba(0,0,0,.15);
}

.ud-hero__info { flex: 1; min-width: 0; }
.ud-hero__name {
    margin: 0 0 4px;
    font-size: 1.35rem; font-weight: 800;
    color: var(--text-primary);
}
.ud-hero__email {
    font-size: 0.875rem; color: var(--text-secondary);
    margin-bottom: 8px;
}
.ud-hero__meta {
    display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
}

/* Badges */
.ud-badge {
    padding: 3px 10px; border-radius: 999px;
    font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em;
}
.ud-badge--ok     { background: color-mix(in srgb, #10b981 13%, transparent); color: #059669; border: 1px solid color-mix(in srgb, #10b981 25%, transparent); }
.ud-badge--warn   { background: color-mix(in srgb, #f59e0b 13%, transparent); color: #b45309; border: 1px solid color-mix(in srgb, #f59e0b 25%, transparent); }
.ud-badge--purple { background: color-mix(in srgb, #8b5cf6 13%, transparent); color: #7c3aed; border: 1px solid color-mix(in srgb, #8b5cf6 25%, transparent); }
.ud-badge--gray   { background: var(--bg-surface-2, #f3f4f6); color: var(--text-secondary); border: 1px solid var(--border-soft, #e5e7eb); }

.ud-dot { color: var(--text-secondary); }
.ud-meta__val { font-size: 0.82rem; color: var(--text-secondary); }

/* Quick stats */
.ud-stats {
    display: flex; gap: 0;
    border: 1px solid var(--border-soft, #e5e7eb);
    border-radius: 12px;
    overflow: hidden;
    flex-shrink: 0;
}
.ud-stat {
    padding: 14px 22px;
    text-align: center;
    border-right: 1px solid var(--border-soft, #e5e7eb);
}
.ud-stat:last-child { border-right: none; }
.ud-stat__val { font-size: 1.25rem; font-weight: 800; color: var(--text-primary); line-height: 1; }
.ud-stat__label { font-size: 0.72rem; color: var(--text-secondary); margin-top: 4px; text-transform: uppercase; letter-spacing: .05em; }

/* ── Grid ────────────────────────────────────────────── */
.ud-grid {
    display: grid;
    grid-template-columns: 280px 1fr;
    gap: 20px;
    align-items: start;
}

.ud-col { display: flex; flex-direction: column; gap: 20px; }
.ud-col--wide { min-width: 0; }

/* ── Card ────────────────────────────────────────────── */
.ud-card {
    background: var(--bg-surface, #fff);
    border: 1px solid var(--border-soft, #e5e7eb);
    border-radius: 14px;
    overflow: hidden;
}

.ud-card__head {
    display: flex; align-items: center; gap: 8px;
    padding: 14px 20px;
    font-size: 0.85rem; font-weight: 700;
    color: var(--text-primary);
    background: var(--bg-surface-2, #f9fafb);
    border-bottom: 1px solid var(--border-soft, #e5e7eb);
}
.ud-card__head svg { width: 16px; height: 16px; color: var(--text-secondary); flex-shrink: 0; }
.ud-card__count {
    margin-left: auto;
    background: var(--bg-surface-2, #f3f4f6);
    border: 1px solid var(--border-soft, #e5e7eb);
    border-radius: 999px;
    padding: 1px 8px; font-size: 11px; font-weight: 700;
    color: var(--text-secondary);
}

.ud-card__body { padding: 16px 20px; display: flex; flex-direction: column; gap: 10px; }

/* ── Key-value rows ──────────────────────────────────── */
.ud-kv { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.ud-kv__k { font-size: 0.8rem; color: var(--text-secondary); }
.ud-kv__v { font-size: 0.85rem; font-weight: 600; color: var(--text-primary); text-align: right; }
.ud-mono { font-family: monospace; font-size: 0.8rem; }
.ud-green { color: #059669; }

/* Minute bar */
.ud-bar-wrap { margin-top: 4px; }
.ud-bar {
    height: 6px; border-radius: 999px;
    background: var(--bg-surface-2, #f3f4f6);
    overflow: hidden;
}
.ud-bar__fill { height: 100%; border-radius: 999px; background: #10b981; transition: width .3s; }
.ud-bar__fill--low { background: #ef4444; }
.ud-bar__pct { font-size: 0.72rem; color: var(--text-secondary); margin-top: 4px; display: block; }

/* ── Billing table ───────────────────────────────────── */
.ud-billing { overflow-x: auto; }

.ud-billing__head {
    display: grid;
    grid-template-columns: 1.4fr 0.8fr 0.9fr 0.85fr 1fr 1.8fr;
    gap: 12px;
    padding: 10px 20px;
    font-size: 0.72rem; font-weight: 700; text-transform: uppercase;
    letter-spacing: .06em; color: var(--text-secondary);
    background: var(--bg-surface-2, #f9fafb);
    border-bottom: 1px solid var(--border-soft, #e5e7eb);
    min-width: 680px;
}

.ud-billing__row {
    display: grid;
    grid-template-columns: 1.4fr 0.8fr 0.9fr 0.85fr 1fr 1.8fr;
    gap: 12px;
    padding: 14px 20px;
    border-bottom: 1px solid var(--border-soft, #e5e7eb);
    align-items: center;
    transition: background .12s;
    min-width: 680px;
}
.ud-billing__row:last-child { border-bottom: none; }
.ud-billing__row:hover { background: var(--bg-surface-2, #f9fafb); }

.ud-billing__cell { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
.ud-billing__cell--ids { gap: 4px; }

.ud-billing__plan { font-size: 0.85rem; font-weight: 600; color: var(--text-primary); }
.ud-billing__min  { font-size: 0.82rem; color: var(--text-secondary); }
.ud-billing__amount { font-size: 0.88rem; font-weight: 700; color: var(--text-primary); }
.ud-billing__cur  { font-size: 0.7rem; color: var(--text-secondary); letter-spacing: .04em; }
.ud-billing__date { font-size: 0.8rem; color: var(--text-secondary); }

/* Status pill — width fits text only */
.ud-status {
    display: inline-flex;
    align-items: center;
    width: fit-content;
    white-space: nowrap;
    padding: 3px 10px; border-radius: 999px;
    font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: .05em;
}
.ud-status--completed { background: color-mix(in srgb,#10b981 13%,transparent); color:#059669; border:1px solid color-mix(in srgb,#10b981 25%,transparent); }
.ud-status--pending   { background: color-mix(in srgb,#f59e0b 13%,transparent); color:#b45309; border:1px solid color-mix(in srgb,#f59e0b 25%,transparent); }
.ud-status--failed    { background: color-mix(in srgb,#ef4444 13%,transparent); color:#dc2626; border:1px solid color-mix(in srgb,#ef4444 25%,transparent); }
.ud-status--refunded  { background: color-mix(in srgb,#8b5cf6 13%,transparent); color:#7c3aed; border:1px solid color-mix(in srgb,#8b5cf6 25%,transparent); }

/* ── Copy toast ──────────────────────────────────────── */
.ud-toast {
    position: fixed;
    bottom: 28px;
    left: 50%;
    transform: translateX(-50%);
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 18px;
    background: #1a1f2e;
    color: #fff;
    font-size: 0.85rem;
    font-weight: 600;
    border-radius: 10px;
    box-shadow: 0 8px 24px rgba(0,0,0,.25);
    z-index: 9999;
    pointer-events: none;
}
.ud-toast svg { width: 15px; height: 15px; color: #10b981; flex-shrink: 0; }

.ud-toast-enter-active, .ud-toast-leave-active { transition: opacity .2s, transform .2s; }
.ud-toast-enter-from { opacity: 0; transform: translateX(-50%) translateY(10px); }
.ud-toast-leave-to   { opacity: 0; transform: translateX(-50%) translateY(10px); }

/* Stripe ID rows */
.ud-id-row {
    display: flex; align-items: center; gap: 5px;
}
.ud-id-label {
    font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: .06em;
    color: var(--text-secondary); width: 46px; flex-shrink: 0;
}
.ud-id-val {
    font-family: monospace; font-size: 0.72rem;
    color: var(--text-primary);
    background: var(--bg-surface-2, #f3f4f6);
    padding: 2px 6px; border-radius: 4px;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    max-width: 110px;
}
.ud-copy {
    background: none; border: none; padding: 2px; cursor: pointer;
    color: var(--text-secondary); border-radius: 4px;
    display: grid; place-items: center;
    transition: color .15s, background .15s;
    flex-shrink: 0;
}
.ud-copy:hover { color: var(--accent,#3b82f6); background: var(--bg-surface-2,#f3f4f6); }
.ud-copy svg { width: 12px; height: 12px; }

/* Empty */
.ud-empty-mini { font-size: 0.85rem; color: var(--text-secondary); padding: 16px 20px; }
.ud-empty-mini--pad { padding: 32px 20px; text-align: center; }

.ud-muted { color: var(--text-secondary); font-size: 0.82rem; }

/* ── Responsive ──────────────────────────────────────── */
@media (max-width: 860px) {
    .ud-grid { grid-template-columns: 1fr; }
    .ud-stats { width: 100%; }
    .ud-hero { flex-direction: column; align-items: flex-start; }
}
</style>
