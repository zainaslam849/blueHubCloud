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
            </div>

            <!-- ── Summary stat cards ───────────────────────── -->
            <div class="ud-statGrid">
                <div class="ud-statCard">
                    <div class="ud-statCard__icon ud-statCard__icon--blue">
                        <svg viewBox="0 0 20 20" fill="none"><path d="M3 17V5a2 2 0 0 1 2-2h4v14" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M9 17V7a2 2 0 0 1 2-2h6v12" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg>
                    </div>
                    <div class="ud-statCard__label">Company</div>
                    <div class="ud-statCard__value ud-statCard__value--sm">{{ data.company?.name ?? '—' }}</div>
                    <div class="ud-statCard__hint" v-if="data.company">{{ statusLabel(data.company.status) }}</div>
                </div>

                <div class="ud-statCard">
                    <div class="ud-statCard__icon ud-statCard__icon--green">
                        <svg viewBox="0 0 20 20" fill="none"><circle cx="10" cy="10" r="7.5" stroke="currentColor" stroke-width="1.5"/><path d="M10 6v4l2.5 2.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
                    </div>
                    <div class="ud-statCard__label">Credit Balance</div>
                    <div class="ud-statCard__value">{{ data.credit_balance ? fmtNum(data.credit_balance.balance) : '—' }}</div>
                    <div class="ud-statCard__hint" :class="data.credit_balance?.auto_topup_enabled ? 'ud-statCard__hint--ok' : ''">
                        {{ data.credit_balance?.auto_topup_enabled ? 'Auto top-up is on' : 'Auto top-up is off' }}
                    </div>
                </div>

                <div class="ud-statCard">
                    <div class="ud-statCard__icon ud-statCard__icon--purple">
                        <svg viewBox="0 0 20 20" fill="none"><path d="M4 4h12v12H4z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M4 8h12" stroke="currentColor" stroke-width="1.5"/></svg>
                    </div>
                    <div class="ud-statCard__label">Current Package</div>
                    <div class="ud-statCard__value ud-statCard__value--sm">{{ data.current_plan_name ?? 'None yet' }}</div>
                    <div class="ud-statCard__hint">{{ data.purchases.length }} purchase{{ data.purchases.length === 1 ? '' : 's' }}</div>
                </div>

                <div class="ud-statCard">
                    <div class="ud-statCard__icon ud-statCard__icon--amber">
                        <svg viewBox="0 0 20 20" fill="none"><path d="M4 17V9h3v8M8.5 17V4h3v13M13 17v-6h3v6" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg>
                    </div>
                    <div class="ud-statCard__label">Reports Generated</div>
                    <div class="ud-statCard__value">{{ data.reports_count }}</div>
                    <div class="ud-statCard__hint">All time</div>
                </div>
            </div>

            <div class="ud-grid">

                <!-- ── Left column ───────────────────────────── -->
                <div class="ud-col">

                    <!-- Company & server card -->
                    <section class="ud-card">
                        <div class="ud-card__head">
                            <svg viewBox="0 0 20 20" fill="none"><path d="M3 17V5a2 2 0 0 1 2-2h4v14" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M9 17V7a2 2 0 0 1 2-2h6v12" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg>
                            Company &amp; Server
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
                                <div class="ud-kv">
                                    <span class="ud-kv__k">Status</span>
                                    <span class="ud-kv__v">
                                        <span class="ud-badge ud-badge--sm" :class="data.company.status === 'active' ? 'ud-badge--ok' : 'ud-badge--gray'">
                                            {{ statusLabel(data.company.status) }}
                                        </span>
                                    </span>
                                </div>
                                <div class="ud-kv">
                                    <span class="ud-kv__k">Timezone</span>
                                    <span class="ud-kv__v">{{ data.company.timezone || '—' }}</span>
                                </div>
                                <div class="ud-kv">
                                    <span class="ud-kv__k">Company since</span>
                                    <span class="ud-kv__v">{{ fmtDate(data.company.created_at) }}</span>
                                </div>

                                <div class="ud-divider"></div>

                                <template v-if="data.pbx_accounts.length">
                                    <div v-for="acc in data.pbx_accounts" :key="acc.id" class="ud-pbxAccount">
                                        <div class="ud-kv">
                                            <span class="ud-kv__k">Server</span>
                                            <span class="ud-kv__v">{{ acc.server_name || '—' }}</span>
                                        </div>
                                        <div class="ud-kv">
                                            <span class="ud-kv__k">Tenant code</span>
                                            <span class="ud-kv__v ud-mono">{{ acc.tenant_code || '—' }}</span>
                                        </div>
                                        <div class="ud-kv">
                                            <span class="ud-kv__k">Package</span>
                                            <span class="ud-kv__v">{{ acc.package_name || '—' }}</span>
                                        </div>
                                        <div class="ud-kv">
                                            <span class="ud-kv__k">Account status</span>
                                            <span class="ud-kv__v">
                                                <span class="ud-badge ud-badge--sm" :class="acc.status === 'active' ? 'ud-badge--ok' : 'ud-badge--gray'">
                                                    {{ statusLabel(acc.status) }}
                                                </span>
                                            </span>
                                        </div>
                                    </div>
                                </template>
                                <div v-else class="ud-empty-mini">No PBX server linked to this company yet.</div>
                            </template>
                            <div v-else class="ud-empty-mini">No company assigned</div>
                        </div>
                    </section>

                    <!-- Account info card -->
                    <section class="ud-card">
                        <div class="ud-card__head">
                            <svg viewBox="0 0 20 20" fill="none"><circle cx="10" cy="7" r="4" stroke="currentColor" stroke-width="1.5"/><path d="M2 18a8 8 0 0 1 16 0" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
                            Account
                        </div>
                        <div class="ud-card__body">
                            <div class="ud-kv">
                                <span class="ud-kv__k">Status</span>
                                <span class="ud-kv__v">
                                    <span class="ud-badge ud-badge--sm" :class="data.user.account_status === 'suspended' ? 'ud-badge--warn' : 'ud-badge--ok'">
                                        {{ data.user.account_status === 'suspended' ? 'Suspended' : 'Active' }}
                                    </span>
                                </span>
                            </div>
                            <div class="ud-kv">
                                <span class="ud-kv__k">Email verified</span>
                                <span class="ud-kv__v">{{ data.user.email_verified_at ? fmtDate(data.user.email_verified_at) : 'Not verified' }}</span>
                            </div>
                            <div class="ud-kv">
                                <span class="ud-kv__k">Joined</span>
                                <span class="ud-kv__v">{{ fmtDate(data.user.created_at) }}</span>
                            </div>
                        </div>
                    </section>

                    <!-- Credit balance card -->
                    <section class="ud-card" v-if="data.credit_balance">
                        <div class="ud-card__head">
                            <svg viewBox="0 0 20 20" fill="none"><circle cx="10" cy="10" r="7.5" stroke="currentColor" stroke-width="1.5"/><path d="M10 6v4l2.5 2.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
                            Credits
                        </div>
                        <div class="ud-card__body">
                            <div class="ud-kv">
                                <span class="ud-kv__k">Balance</span>
                                <span class="ud-kv__v ud-mono">{{ fmtNum(data.credit_balance.balance) }}</span>
                            </div>
                            <div class="ud-kv">
                                <span class="ud-kv__k">Auto top-up</span>
                                <span class="ud-kv__v">
                                    <span class="ud-badge ud-badge--sm" :class="data.credit_balance.auto_topup_enabled ? 'ud-badge--ok' : 'ud-badge--gray'">
                                        {{ data.credit_balance.auto_topup_enabled ? 'On' : 'Off' }}
                                    </span>
                                </span>
                            </div>
                            <template v-if="data.credit_balance.auto_topup_enabled">
                                <div class="ud-kv">
                                    <span class="ud-kv__k">Trigger below</span>
                                    <span class="ud-kv__v ud-mono">{{ fmtNum(data.credit_balance.auto_topup_threshold) }}</span>
                                </div>
                                <div class="ud-kv">
                                    <span class="ud-kv__k">Tops up by</span>
                                    <span class="ud-kv__v ud-mono">{{ fmtNum(data.credit_balance.auto_topup_credits) }}</span>
                                </div>
                            </template>
                        </div>
                    </section>

                </div>

                <!-- ── Right column ──────────────────────────── -->
                <div class="ud-col ud-col--wide">

                    <!-- Report history -->
                    <section class="ud-panel">
                        <div class="ud-panel__head">
                            <div>
                                <div class="ud-panel__title">Report History</div>
                                <div class="ud-panel__sub">Weekly call reports generated for this company</div>
                            </div>
                            <span class="ud-panel__count">{{ data.reports_count }} total</span>
                        </div>
                        <div class="ud-tableWrap" v-if="data.recent_reports.length">
                            <table class="admin-table">
                                <thead>
                                    <tr>
                                        <th class="admin-table__th">Week</th>
                                        <th class="admin-table__th">Status</th>
                                        <th class="admin-table__th">Calls</th>
                                        <th class="admin-table__th">Minutes</th>
                                        <th class="admin-table__th"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr class="admin-table__tr" v-for="r in data.recent_reports" :key="r.id">
                                        <td class="admin-table__td" data-label="Week">{{ fmtDate(r.week_start_date) }} – {{ fmtDate(r.week_end_date) }}</td>
                                        <td class="admin-table__td" data-label="Status">
                                            <span class="ud-badge ud-badge--sm" :class="reportStatusBadge(r.status)">{{ statusLabel(r.status) }}</span>
                                        </td>
                                        <td class="admin-table__td ud-mono" data-label="Calls">{{ r.total_calls ?? 0 }}</td>
                                        <td class="admin-table__td ud-mono" data-label="Minutes">{{ r.minutes_consumed ?? 0 }}</td>
                                        <td class="admin-table__td" data-label="">
                                            <RouterLink
                                                v-if="data.company && r.status === 'completed'"
                                                :to="{ name: 'admin.weeklyReports.detail', params: { companySlug: data.company.slug, weekStart: r.week_start_date } }"
                                                class="ud-tableLink"
                                            >View →</RouterLink>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div v-else class="ud-empty-mini">No reports generated yet for this company.</div>
                    </section>

                    <!-- Purchase history -->
                    <section class="ud-panel">
                        <div class="ud-panel__head">
                            <div>
                                <div class="ud-panel__title">Purchase History</div>
                                <div class="ud-panel__sub">Plan / credit purchases made for this company</div>
                            </div>
                        </div>
                        <div class="ud-tableWrap" v-if="data.purchases.length">
                            <table class="admin-table">
                                <thead>
                                    <tr>
                                        <th class="admin-table__th">Date</th>
                                        <th class="admin-table__th">Plan</th>
                                        <th class="admin-table__th">Amount</th>
                                        <th class="admin-table__th">Credits Added</th>
                                        <th class="admin-table__th">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr class="admin-table__tr" v-for="p in data.purchases" :key="p.id">
                                        <td class="admin-table__td" data-label="Date">{{ fmtDate(p.purchased_at) }}</td>
                                        <td class="admin-table__td" data-label="Plan">{{ p.plan_name || '—' }}</td>
                                        <td class="admin-table__td ud-mono" data-label="Amount">{{ fmtCurrency(p.amount_paid, p.currency) }}</td>
                                        <td class="admin-table__td ud-mono" data-label="Credits Added">+{{ fmtNum(p.credits_added) }}</td>
                                        <td class="admin-table__td" data-label="Status">
                                            <span class="ud-badge ud-badge--sm" :class="purchaseStatusBadge(p.status)">{{ statusLabel(p.status) }}</span>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div v-else class="ud-empty-mini">No purchases yet for this company.</div>
                    </section>

                    <!-- Credit history -->
                    <section class="ud-panel">
                        <div class="ud-panel__head">
                            <div>
                                <div class="ud-panel__title">Credit History</div>
                                <div class="ud-panel__sub">Full credit ledger — purchases, deductions, refunds &amp; adjustments</div>
                            </div>
                        </div>
                        <div class="ud-tableWrap" v-if="data.credit_transactions.length">
                            <table class="admin-table">
                                <thead>
                                    <tr>
                                        <th class="admin-table__th">Date</th>
                                        <th class="admin-table__th">Type</th>
                                        <th class="admin-table__th">Credits</th>
                                        <th class="admin-table__th">Balance After</th>
                                        <th class="admin-table__th">Note</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr class="admin-table__tr" v-for="t in data.credit_transactions" :key="t.id">
                                        <td class="admin-table__td" data-label="Date">{{ fmtDateTime(t.created_at) }}</td>
                                        <td class="admin-table__td" data-label="Type">
                                            <span class="ud-badge ud-badge--sm" :class="creditTypeBadge(t.type)">{{ creditTypeLabel(t.type) }}</span>
                                        </td>
                                        <td class="admin-table__td ud-mono" data-label="Credits" :class="t.credits >= 0 ? 'ud-positive' : 'ud-negative'">
                                            {{ t.credits >= 0 ? '+' : '' }}{{ fmtNum(t.credits) }}
                                        </td>
                                        <td class="admin-table__td ud-mono" data-label="Balance After">{{ fmtNum(t.balance_after) }}</td>
                                        <td class="admin-table__td ud-muted" data-label="Note">{{ t.note || '—' }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div v-else class="ud-empty-mini">No credit activity yet for this company.</div>
                    </section>

                </div>
            </div>
        </template>

        <div v-else class="ud-loading">
            <span>User not found.</span>
        </div>

    </div>
</template>

<script setup>
import { onMounted, ref } from "vue";
import { useRoute, RouterLink } from "vue-router";
import adminApi from "../../router/admin/api";

const route   = useRoute();
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
function fmtDateTime(iso) {
    if (!iso) return "—";
    return new Date(iso).toLocaleString("en-US", { year: "numeric", month: "short", day: "numeric", hour: "numeric", minute: "2-digit" });
}
function fmtNum(v) {
    if (v === null || v === undefined) return "—";
    return Number(v).toLocaleString(undefined, { maximumFractionDigits: 2 });
}
function fmtCurrency(amount, currency) {
    if (amount === null || amount === undefined) return "—";
    try {
        return new Intl.NumberFormat(undefined, { style: "currency", currency: (currency || "USD").toUpperCase() }).format(amount);
    } catch {
        return `${amount} ${currency || ""}`.trim();
    }
}
function statusLabel(status) {
    if (!status) return "—";
    return status.replace(/_/g, " ").replace(/\b\w/g, (c) => c.toUpperCase());
}
function reportStatusBadge(status) {
    if (status === "completed") return "ud-badge--ok";
    if (status === "processing" || status === "queued") return "ud-badge--purple";
    if (status === "failed") return "ud-badge--warn";
    return "ud-badge--gray";
}
function purchaseStatusBadge(status) {
    if (status === "completed") return "ud-badge--ok";
    if (status === "pending") return "ud-badge--purple";
    if (status === "failed") return "ud-badge--warn";
    return "ud-badge--gray";
}
function creditTypeLabel(type) {
    if (type === "auto_topup") return "Auto Top-up";
    return statusLabel(type);
}
function creditTypeBadge(type) {
    if (type === "purchase" || type === "auto_topup") return "ud-badge--ok";
    if (type === "refund") return "ud-badge--purple";
    if (type === "deduction") return "ud-badge--gray";
    if (type === "adjustment") return "ud-badge--warn";
    return "ud-badge--gray";
}

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
    font-size: 0.875rem; font-weight: 600; color: var(--text-secondary);
    text-decoration: none; transition: color 0.15s;
}
.ud-backLink:hover { color: var(--accent-primary, #2563eb); }
.ud-backLink svg { width: 16px; height: 16px; }

/* ── Loading ─────────────────────────────────────────── */
.ud-loading { display: flex; align-items: center; justify-content: center; gap: 12px; padding: 60px 0; color: var(--text-secondary); font-size: 0.9rem; }
.ud-spinner { width: 24px; height: 24px; border: 2.5px solid var(--border-soft, #e5e7eb); border-top-color: var(--accent-primary, #2563eb); border-radius: 50%; animation: spin .7s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }

/* ── Hero ────────────────────────────────────────────── */
.ud-hero {
    display: flex; align-items: center; gap: 20px;
    padding: 24px 28px;
    background: var(--bg-surface, #fff); border: 1px solid var(--border-soft, #e5e7eb);
    border-radius: 16px; margin-bottom: 20px; flex-wrap: wrap;
}
.ud-hero__avatar {
    width: 64px; height: 64px; border-radius: 50%; flex-shrink: 0;
    display: grid; place-items: center;
    font-size: 1.3rem; font-weight: 800; color: #fff;
    box-shadow: 0 4px 14px rgba(0,0,0,.15);
}
.ud-hero__info { flex: 1; min-width: 0; }
.ud-hero__name { margin: 0 0 4px; font-size: 1.35rem; font-weight: 800; color: var(--text-primary); }
.ud-hero__email { font-size: 0.875rem; color: var(--text-secondary); margin-bottom: 8px; }
.ud-hero__meta { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }

/* Badges */
.ud-badge {
    padding: 3px 10px; border-radius: 999px;
    font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em;
    white-space: nowrap;
}
.ud-badge--sm { font-size: 10px; padding: 2px 8px; }
.ud-badge--ok     { background: color-mix(in srgb, #10b981 13%, transparent); color: #059669; border: 1px solid color-mix(in srgb, #10b981 25%, transparent); }
.ud-badge--warn   { background: color-mix(in srgb, #f59e0b 13%, transparent); color: #b45309; border: 1px solid color-mix(in srgb, #f59e0b 25%, transparent); }
.ud-badge--purple { background: color-mix(in srgb, #8b5cf6 13%, transparent); color: #7c3aed; border: 1px solid color-mix(in srgb, #8b5cf6 25%, transparent); }
.ud-badge--gray   { background: var(--bg-surface-2, #f3f4f6); color: var(--text-secondary); border: 1px solid var(--border-soft, #e5e7eb); }

.ud-dot { color: var(--text-secondary); }
.ud-meta__val { font-size: 0.82rem; color: var(--text-secondary); }

/* ── Summary stat cards ──────────────────────────────── */
.ud-statGrid {
    display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 14px;
    margin-bottom: 20px;
}
.ud-statCard {
    background: var(--bg-surface, #fff); border: 1px solid var(--border-soft, #e5e7eb);
    border-radius: 14px; padding: 16px 18px;
}
.ud-statCard__icon {
    width: 32px; height: 32px; border-radius: 9px; margin-bottom: 10px;
    display: grid; place-items: center;
}
.ud-statCard__icon svg { width: 17px; height: 17px; }
.ud-statCard__icon--blue   { background: color-mix(in srgb, #2563eb 12%, transparent); color: #2563eb; }
.ud-statCard__icon--green  { background: color-mix(in srgb, #10b981 12%, transparent); color: #059669; }
.ud-statCard__icon--purple { background: color-mix(in srgb, #8b5cf6 12%, transparent); color: #7c3aed; }
.ud-statCard__icon--amber  { background: color-mix(in srgb, #f59e0b 12%, transparent); color: #b45309; }
.ud-statCard__label { font-size: 0.75rem; color: var(--text-secondary); font-weight: 600; margin-bottom: 4px; }
.ud-statCard__value { font-size: 1.4rem; font-weight: 800; color: var(--text-primary); line-height: 1.2; }
.ud-statCard__value--sm { font-size: 1.05rem; }
.ud-statCard__hint { font-size: 0.75rem; color: var(--text-secondary); margin-top: 4px; }
.ud-statCard__hint--ok { color: #059669; }

/* ── Grid ────────────────────────────────────────────── */
.ud-grid { display: grid; grid-template-columns: minmax(0, 380px) minmax(0, 1fr); gap: 20px; align-items: start; }
.ud-col { display: flex; flex-direction: column; gap: 20px; }

/* ── Card ────────────────────────────────────────────── */
.ud-card { background: var(--bg-surface, #fff); border: 1px solid var(--border-soft, #e5e7eb); border-radius: 14px; overflow: hidden; }
.ud-card__head {
    display: flex; align-items: center; gap: 8px;
    padding: 14px 20px; font-size: 0.85rem; font-weight: 700; color: var(--text-primary);
    background: var(--bg-surface-2, #f9fafb); border-bottom: 1px solid var(--border-soft, #e5e7eb);
}
.ud-card__head svg { width: 16px; height: 16px; color: var(--text-secondary); flex-shrink: 0; }
.ud-card__body { padding: 16px 20px; display: flex; flex-direction: column; gap: 10px; }

/* ── Key-value ───────────────────────────────────────── */
.ud-kv { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.ud-kv__k { font-size: 0.8rem; color: var(--text-secondary); }
.ud-kv__v { font-size: 0.85rem; font-weight: 600; color: var(--text-primary); text-align: right; }
.ud-mono { font-family: monospace; font-size: 0.8rem; }
.ud-empty-mini { font-size: 0.85rem; color: var(--text-secondary); padding: 8px 0; }
.ud-muted { color: var(--text-secondary); }
.ud-positive { color: #059669; }
.ud-negative { color: #b45309; }
.ud-divider { height: 1px; background: var(--border-soft, #e5e7eb); margin: 4px 0; }
.ud-pbxAccount + .ud-pbxAccount { margin-top: 10px; padding-top: 10px; border-top: 1px dashed var(--border-soft, #e5e7eb); }

/* ── Panels (report / purchase / credit history) ──────── */
.ud-panel { background: var(--bg-surface, #fff); border: 1px solid var(--border-soft, #e5e7eb); border-radius: 14px; overflow: hidden; }
.ud-panel__head {
    display: flex; align-items: flex-start; justify-content: space-between; gap: 12px;
    padding: 16px 20px; border-bottom: 1px solid var(--border-soft, #e5e7eb);
    background: var(--bg-surface-2, #f9fafb);
}
.ud-panel__title { font-size: 0.9rem; font-weight: 700; color: var(--text-primary); }
.ud-panel__sub { font-size: 0.78rem; color: var(--text-secondary); margin-top: 2px; }
.ud-panel__count { font-size: 0.75rem; font-weight: 600; color: var(--text-secondary); white-space: nowrap; }
.ud-tableWrap { overflow-x: auto; }
.ud-tableLink { font-size: 0.8rem; font-weight: 600; color: var(--accent-primary, #2563eb); text-decoration: none; white-space: nowrap; }
.ud-tableLink:hover { text-decoration: underline; }

/* ── Responsive ──────────────────────────────────────── */
@media (max-width: 960px) {
    .ud-statGrid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
@media (max-width: 860px) {
    .ud-grid { grid-template-columns: 1fr; }
    .ud-hero { flex-direction: column; align-items: flex-start; }
}
@media (max-width: 520px) {
    .ud-statGrid { grid-template-columns: 1fr; }
}
</style>
