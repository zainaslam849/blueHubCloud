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

                <!-- Quick stat: call limit -->
                <div class="ud-stats">
                    <div class="ud-stat">
                        <div class="ud-stat__val">
                            {{ data.call_limit && data.call_limit.monthly_call_limit != null ? Number(data.call_limit.monthly_call_limit).toLocaleString() : '∞' }}
                        </div>
                        <div class="ud-stat__label">Calls / Month</div>
                    </div>
                    <div class="ud-stat" v-if="data.company">
                        <div class="ud-stat__val ud-stat__val--sm">{{ data.company.name }}</div>
                        <div class="ud-stat__label">Company</div>
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

                </div>

                <!-- ── Right column — call limit (read-only) ──── -->
                <div class="ud-col ud-col--wide">

                    <!-- Call Limit card -->
                    <section class="ud-card">
                        <div class="ud-card__head">
                            <svg viewBox="0 0 20 20" fill="none"><path d="M3 10h14M10 3v14" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><circle cx="10" cy="10" r="8" stroke="currentColor" stroke-width="1.5"/></svg>
                            Call Analysis Limit
                            <span class="ud-card__hint">Set on the Companies page</span>
                        </div>
                        <div class="ud-card__body ud-card__body--pad">

                            <div v-if="!data.company" class="ud-empty-mini">
                                Assign a company to this user to manage call limits.
                            </div>

                            <template v-else>
                                <div class="ud-limitInfo">
                                    <div class="ud-limitIcon">
                                        <svg viewBox="0 0 24 24" fill="none">
                                            <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12 19.79 19.79 0 0 1 1.61 3.41 2 2 0 0 1 3.6 1.22h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L7.91 8.78a16 16 0 0 0 6 6l.85-.85a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 21.22 16a1.59 1.59 0 0 1 .78.92Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                                        </svg>
                                    </div>
                                    <div>
                                        <div class="ud-limitVal">
                                            {{ data.call_limit && data.call_limit.monthly_call_limit != null ? Number(data.call_limit.monthly_call_limit).toLocaleString() : 'Unlimited' }}
                                        </div>
                                        <div class="ud-limitSub">analysed calls per period</div>
                                    </div>
                                </div>

                                <template v-if="data.call_limit && data.call_limit.monthly_call_limit != null">
                                    <div class="ud-kv">
                                        <span class="ud-kv__k">Used this period</span>
                                        <span class="ud-kv__v">{{ Number(data.call_limit.call_limit_used).toLocaleString() }}</span>
                                    </div>
                                    <div class="ud-kv">
                                        <span class="ud-kv__k">Remaining</span>
                                        <span class="ud-kv__v ud-green">{{ Number(data.call_limit.call_limit_remaining).toLocaleString() }}</span>
                                    </div>
                                    <div class="ud-bar-wrap">
                                        <div class="ud-bar">
                                            <div class="ud-bar__fill" :style="{ width: usagePct + '%' }" :class="usagePct >= 100 ? 'ud-bar__fill--low' : ''"></div>
                                        </div>
                                        <span class="ud-bar__pct">{{ usagePct }}% used</span>
                                    </div>
                                    <div class="ud-kv">
                                        <span class="ud-kv__k">Expires</span>
                                        <span class="ud-kv__v">
                                            {{ data.call_limit.call_limit_expires_at ? fmtDate(data.call_limit.call_limit_expires_at) : '—' }}
                                            <span v-if="data.call_limit.period_completed" class="ud-badge ud-badge--sm ud-badge--warn" style="margin-left:6px">Period ended</span>
                                        </span>
                                    </div>
                                </template>
                            </template>
                        </div>
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
import { computed, onMounted, ref } from "vue";
import { useRoute, RouterLink } from "vue-router";
import adminApi from "../../router/admin/api";

const route   = useRoute();
const loading = ref(true);
const data    = ref(null);

const usagePct = computed(() => {
    const cl = data.value?.call_limit;
    if (!cl || cl.monthly_call_limit == null || cl.monthly_call_limit === 0) return 0;
    return Math.min(100, Math.round((cl.call_limit_used / cl.monthly_call_limit) * 100));
});

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
.ud-backLink:hover { color: var(--accent, #3b82f6); }
.ud-backLink svg { width: 16px; height: 16px; }

/* ── Loading ─────────────────────────────────────────── */
.ud-loading { display: flex; align-items: center; justify-content: center; gap: 12px; padding: 60px 0; color: var(--text-secondary); font-size: 0.9rem; }
.ud-spinner { width: 24px; height: 24px; border: 2.5px solid var(--border-soft, #e5e7eb); border-top-color: var(--accent, #3b82f6); border-radius: 50%; animation: spin .7s linear infinite; }
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
}
.ud-badge--sm { font-size: 10px; padding: 2px 8px; }
.ud-badge--ok     { background: color-mix(in srgb, #10b981 13%, transparent); color: #059669; border: 1px solid color-mix(in srgb, #10b981 25%, transparent); }
.ud-badge--warn   { background: color-mix(in srgb, #f59e0b 13%, transparent); color: #b45309; border: 1px solid color-mix(in srgb, #f59e0b 25%, transparent); }
.ud-badge--purple { background: color-mix(in srgb, #8b5cf6 13%, transparent); color: #7c3aed; border: 1px solid color-mix(in srgb, #8b5cf6 25%, transparent); }
.ud-badge--gray   { background: var(--bg-surface-2, #f3f4f6); color: var(--text-secondary); border: 1px solid var(--border-soft, #e5e7eb); }

.ud-dot { color: var(--text-secondary); }
.ud-meta__val { font-size: 0.82rem; color: var(--text-secondary); }

/* Quick stats */
.ud-stats { display: flex; gap: 0; border: 1px solid var(--border-soft, #e5e7eb); border-radius: 12px; overflow: hidden; flex-shrink: 0; }
.ud-stat { padding: 14px 22px; text-align: center; border-right: 1px solid var(--border-soft, #e5e7eb); }
.ud-stat:last-child { border-right: none; }
.ud-stat__val { font-size: 1.25rem; font-weight: 800; color: var(--text-primary); line-height: 1; }
.ud-stat__val--sm { font-size: 0.9rem; }
.ud-stat__label { font-size: 0.72rem; color: var(--text-secondary); margin-top: 4px; text-transform: uppercase; letter-spacing: .05em; }

/* ── Grid ────────────────────────────────────────────── */
.ud-grid { display: grid; grid-template-columns: 280px 1fr; gap: 20px; align-items: start; }
.ud-col { display: flex; flex-direction: column; gap: 20px; }
.ud-col--wide { min-width: 0; }

/* ── Card ────────────────────────────────────────────── */
.ud-card { background: var(--bg-surface, #fff); border: 1px solid var(--border-soft, #e5e7eb); border-radius: 14px; overflow: hidden; }
.ud-card__head {
    display: flex; align-items: center; gap: 8px;
    padding: 14px 20px; font-size: 0.85rem; font-weight: 700; color: var(--text-primary);
    background: var(--bg-surface-2, #f9fafb); border-bottom: 1px solid var(--border-soft, #e5e7eb);
}
.ud-card__head svg { width: 16px; height: 16px; color: var(--text-secondary); flex-shrink: 0; }
.ud-card__body { padding: 16px 20px; display: flex; flex-direction: column; gap: 10px; }
.ud-card__body--pad { gap: 20px; }

/* ── Key-value ───────────────────────────────────────── */
.ud-kv { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.ud-kv__k { font-size: 0.8rem; color: var(--text-secondary); }
.ud-kv__v { font-size: 0.85rem; font-weight: 600; color: var(--text-primary); text-align: right; }
.ud-mono { font-family: monospace; font-size: 0.8rem; }
.ud-empty-mini { font-size: 0.85rem; color: var(--text-secondary); padding: 8px 0; }

/* ── Call limit card ─────────────────────────────────── */
.ud-limitInfo {
    display: flex; align-items: center; gap: 16px;
    padding: 16px;
    background: color-mix(in srgb, var(--accent, #3b82f6) 6%, transparent);
    border: 1px solid color-mix(in srgb, var(--accent, #3b82f6) 18%, transparent);
    border-radius: 12px;
}
.ud-limitIcon {
    width: 44px; height: 44px; border-radius: 12px; flex-shrink: 0;
    background: color-mix(in srgb, var(--accent, #3b82f6) 14%, transparent);
    display: grid; place-items: center;
    color: var(--accent, #3b82f6);
}
.ud-limitIcon svg { width: 22px; height: 22px; }
.ud-limitVal { font-size: 1.6rem; font-weight: 800; color: var(--text-primary); line-height: 1.1; }
.ud-limitSub { font-size: 0.78rem; color: var(--text-secondary); margin-top: 3px; }

.ud-card__hint {
    margin-left: auto; font-size: 0.7rem; font-weight: 600;
    color: var(--text-secondary); text-transform: none; letter-spacing: 0;
    background: var(--bg-surface-2, #f3f4f6); border: 1px solid var(--border-soft, #e5e7eb);
    padding: 2px 8px; border-radius: 999px;
}
.ud-green { color: #059669; }

/* Usage bar */
.ud-bar-wrap { margin-top: 2px; }
.ud-bar { height: 6px; border-radius: 999px; background: var(--bg-surface-2, #f3f4f6); overflow: hidden; }
.ud-bar__fill { height: 100%; border-radius: 999px; background: #3b82f6; transition: width .3s; }
.ud-bar__fill--low { background: #ef4444; }
.ud-bar__pct { font-size: 0.72rem; color: var(--text-secondary); margin-top: 4px; display: block; }

/* ── Responsive ──────────────────────────────────────── */
@media (max-width: 860px) {
    .ud-grid { grid-template-columns: 1fr; }
    .ud-stats { width: 100%; }
    .ud-hero { flex-direction: column; align-items: flex-start; }
}
</style>
