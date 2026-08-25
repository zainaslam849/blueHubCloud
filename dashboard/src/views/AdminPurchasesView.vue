<script setup lang="ts">
import { ref, onMounted, watch } from "vue";
import PageHeader from "../components/ui/PageHeader.vue";
import { http } from "../api/http";

type Purchase = {
    id: number;
    user: { id: number; name: string; email: string } | null;
    company: { id: number; name: string } | null;
    plan_name: string;
    minutes_added: number;
    amount_paid: string;
    currency: string;
    status: string;
    stripe_session_id: string | null;
    stripe_payment_intent_id: string | null;
    purchased_at: string | null;
    created_at: string;
};

type Meta = { currentPage: number; lastPage: number; total: number; perPage: number };
type Totals = { total_revenue: number; total_purchases: number };

const purchases  = ref<Purchase[]>([]);
const meta       = ref<Meta>({ currentPage: 1, lastPage: 1, total: 0, perPage: 25 });
const totals     = ref<Totals>({ total_revenue: 0, total_purchases: 0 });
const loading    = ref(true);
const error      = ref<string | null>(null);
const filterStatus = ref("");
const page       = ref(1);

async function load() {
    loading.value = true;
    error.value   = null;
    try {
        const params: Record<string, unknown> = { page: page.value };
        if (filterStatus.value) params.status = filterStatus.value;

        const res = await http.get<{ data: Purchase[]; meta: Meta; totals: Totals }>(
            "/api/v1/admin/purchases",
            { params }
        );
        purchases.value = res.data.data;
        meta.value      = res.data.meta;
        totals.value    = res.data.totals;
    } catch {
        error.value = "Failed to load purchases.";
    } finally {
        loading.value = false;
    }
}

watch(filterStatus, () => { page.value = 1; load(); });

function formatDate(iso: string | null): string {
    if (!iso) return "—";
    return new Date(iso).toLocaleDateString(undefined, { year: "numeric", month: "short", day: "numeric" });
}

function formatAmount(amount: string, currency: string): string {
    return new Intl.NumberFormat("en-US", { style: "currency", currency }).format(Number(amount));
}

onMounted(load);
</script>

<template>
    <div class="page">
        <PageHeader title="Purchase History" description="All plan purchases across all users." />

        <!-- Stats -->
        <div class="apStats">
            <div class="apStat">
                <div class="apStat__label">Total Revenue</div>
                <div class="apStat__value">${{ Number(totals.total_revenue).toLocaleString("en-US", { minimumFractionDigits: 2 }) }}</div>
            </div>
            <div class="apStat">
                <div class="apStat__label">Completed Purchases</div>
                <div class="apStat__value">{{ totals.total_purchases }}</div>
            </div>
        </div>

        <!-- Filters -->
        <div class="apFilters">
            <select v-model="filterStatus" class="apSelect">
                <option value="">All Statuses</option>
                <option value="pending">Pending</option>
                <option value="completed">Completed</option>
                <option value="failed">Failed</option>
                <option value="refunded">Refunded</option>
            </select>
        </div>

        <div v-if="error" class="apError">{{ error }}</div>

        <!-- Table -->
        <div class="apTable">
            <div class="apTable__head">
                <span>User / Company</span>
                <span>Plan</span>
                <span>Minutes</span>
                <span>Amount</span>
                <span>Status</span>
                <span>Date</span>
            </div>

            <!-- Skeleton -->
            <template v-if="loading">
                <div v-for="i in 6" :key="i" class="apRow apRow--sk">
                    <div class="apSk apSk--lg"></div>
                    <div class="apSk apSk--md"></div>
                    <div class="apSk apSk--sm"></div>
                    <div class="apSk apSk--sm"></div>
                    <div class="apSk apSk--pill"></div>
                    <div class="apSk apSk--md"></div>
                </div>
            </template>

            <!-- Empty -->
            <div v-else-if="purchases.length === 0" class="apEmpty">
                No purchases found.
            </div>

            <!-- Rows -->
            <template v-else>
                <div v-for="p in purchases" :key="p.id" class="apRow">
                    <div class="apRow__user">
                        <span class="apRow__name">{{ p.user?.name ?? '—' }}</span>
                        <span class="apRow__email">{{ p.user?.email ?? '' }}</span>
                        <span class="apRow__company" v-if="p.company">{{ p.company.name }}</span>
                    </div>
                    <span class="apRow__plan">{{ p.plan_name }}</span>
                    <span class="apRow__minutes">{{ p.minutes_added.toLocaleString() }}</span>
                    <span class="apRow__amount">{{ formatAmount(p.amount_paid, p.currency) }}</span>
                    <span
                        class="apStatus"
                        :class="{
                            'apStatus--completed': p.status === 'completed',
                            'apStatus--pending':   p.status === 'pending',
                            'apStatus--failed':    p.status === 'failed',
                            'apStatus--refunded':  p.status === 'refunded',
                            'apStatus--cancelled': p.status === 'cancelled',
                        }"
                    >{{ p.status }}</span>
                    <span class="apRow__date">{{ formatDate(p.purchased_at ?? p.created_at) }}</span>
                </div>
            </template>
        </div>

        <!-- Pagination -->
        <div v-if="meta.lastPage > 1" class="apPagination">
            <button
                class="apPage"
                :disabled="page <= 1"
                @click="page--; load()"
            >← Prev</button>
            <span class="apPage__info">Page {{ meta.currentPage }} of {{ meta.lastPage }} ({{ meta.total }} total)</span>
            <button
                class="apPage"
                :disabled="page >= meta.lastPage"
                @click="page++; load()"
            >Next →</button>
        </div>
    </div>
</template>

<style scoped>
.apStats {
    display: flex; gap: var(--space-4); margin-bottom: var(--space-6); flex-wrap: wrap;
}
.apStat {
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-lg);
    padding: var(--space-5) var(--space-6);
    flex: 1; min-width: 180px;
}
.apStat__label { font-size: var(--text-sm); color: var(--color-muted); margin-bottom: var(--space-1); }
.apStat__value { font-size: var(--text-2xl); font-weight: 800; color: var(--color-text); }

.apFilters { display: flex; gap: var(--space-3); margin-bottom: var(--space-5); flex-wrap: wrap; }
.apSelect {
    height: 38px; padding: 0 var(--space-4);
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    color: var(--color-text); font-size: var(--text-sm);
    cursor: pointer;
}

.apError {
    padding: var(--space-4);
    background: color-mix(in srgb, var(--color-error) 10%, transparent);
    border: 1px solid color-mix(in srgb, var(--color-error) 30%, transparent);
    border-radius: var(--radius-md);
    color: var(--color-error); font-size: var(--text-sm);
    margin-bottom: var(--space-5);
}

.apTable {
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-lg);
    overflow: hidden;
    margin-bottom: var(--space-5);
}

.apTable__head {
    display: grid;
    grid-template-columns: 2fr 1.5fr 1fr 1fr 1fr 1fr;
    gap: var(--space-3);
    padding: var(--space-3) var(--space-5);
    background: var(--color-surface-2);
    border-bottom: 1px solid var(--color-border);
    font-size: var(--text-xs); font-weight: var(--weight-semibold);
    color: var(--color-muted); text-transform: uppercase; letter-spacing: var(--tracking-wide);
}

.apRow {
    display: grid;
    grid-template-columns: 2fr 1.5fr 1fr 1fr 1fr 1fr;
    gap: var(--space-3); align-items: center;
    padding: var(--space-4) var(--space-5);
    border-bottom: 1px solid var(--color-border);
    font-size: var(--text-sm);
}
.apRow:last-child { border-bottom: none; }
.apRow:hover { background: var(--color-surface-2); }

.apRow__user { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
.apRow__name { font-weight: var(--weight-semibold); color: var(--color-text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.apRow__email { font-size: 11px; color: var(--color-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.apRow__company { font-size: 11px; color: var(--color-primary); }
.apRow__plan { font-weight: var(--weight-medium); color: var(--color-text); }
.apRow__minutes { color: var(--color-muted); }
.apRow__amount { font-weight: var(--weight-medium); color: var(--color-text); }
.apRow__date { color: var(--color-muted); font-size: 12px; }

.apStatus {
    display: inline-flex; align-items: center;
    padding: 3px 10px; border-radius: var(--radius-pill);
    font-size: 11px; font-weight: 700;
    text-transform: capitalize; letter-spacing: .03em; width: fit-content;
}
.apStatus--completed { background: color-mix(in srgb, var(--color-success) 12%, transparent); color: var(--color-success); border: 1px solid color-mix(in srgb, var(--color-success) 25%, transparent); }
.apStatus--pending   { background: color-mix(in srgb, var(--color-warning) 12%, transparent); color: var(--color-warning); border: 1px solid color-mix(in srgb, var(--color-warning) 25%, transparent); }
.apStatus--failed    { background: color-mix(in srgb, var(--color-error)   12%, transparent); color: var(--color-error);   border: 1px solid color-mix(in srgb, var(--color-error)   25%, transparent); }
.apStatus--refunded  { background: color-mix(in srgb, var(--color-muted)   12%, transparent); color: var(--color-muted);   border: 1px solid color-mix(in srgb, var(--color-muted)   25%, transparent); }
.apStatus--cancelled { background: color-mix(in srgb, var(--color-muted)   12%, transparent); color: var(--color-muted);   border: 1px solid color-mix(in srgb, var(--color-muted)   25%, transparent); }

/* Skeleton */
.apRow--sk { pointer-events: none; }
.apSk {
    border-radius: var(--radius-sm);
    background-image: linear-gradient(90deg, color-mix(in srgb, var(--color-text) 5%, transparent) 0%, color-mix(in srgb, var(--color-text) 10%, transparent) 50%, color-mix(in srgb, var(--color-text) 5%, transparent) 100%);
    background-size: 300% 100%;
    animation: apShimmer 1.5s ease-in-out infinite;
}
@keyframes apShimmer { 0% { background-position: 100% 0; } 100% { background-position: -100% 0; } }
.apSk--lg   { height: 16px; width: 70%; }
.apSk--md   { height: 14px; width: 60%; }
.apSk--sm   { height: 14px; width: 50%; }
.apSk--pill { height: 20px; width: 70px; border-radius: var(--radius-pill); }

.apEmpty {
    padding: var(--space-10); text-align: center;
    color: var(--color-muted); font-size: var(--text-sm);
}

.apPagination {
    display: flex; align-items: center; justify-content: center; gap: var(--space-4);
}
.apPage {
    height: 36px; padding: 0 var(--space-4);
    background: var(--color-surface); border: 1px solid var(--color-border);
    border-radius: var(--radius-md); cursor: pointer;
    font-size: var(--text-sm); color: var(--color-text);
    transition: background 150ms;
}
.apPage:hover:not(:disabled) { background: var(--color-surface-2); }
.apPage:disabled { opacity: .4; cursor: not-allowed; }
.apPage__info { font-size: var(--text-sm); color: var(--color-muted); }
</style>
