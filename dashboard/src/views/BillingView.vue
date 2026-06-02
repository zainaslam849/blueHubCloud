<script setup lang="ts">
import { ref, onMounted } from "vue";
import PageHeader from "../components/ui/PageHeader.vue";
import { userApi } from "../api/user";

type Purchase = {
    id: number;
    plan_name: string;
    minutes_added: number;
    amount_paid: string;
    currency: string;
    status: string;
    purchased_at: string | null;
    created_at: string;
};

const purchases = ref<Purchase[]>([]);
const loading   = ref(true);
const error     = ref<string | null>(null);

async function load() {
    loading.value = true;
    error.value   = null;
    try {
        const res = await userApi.get<{ data: Purchase[] }>("/purchases");
        purchases.value = res.data.data ?? [];
    } catch {
        error.value = "Unable to load purchase history. Please try again.";
    } finally {
        loading.value = false;
    }
}

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
        <PageHeader
            title="Billing History"
            description="All your plan purchases and payment history."
        />

        <div v-if="error" class="blError">
            <svg viewBox="0 0 20 20" fill="currentColor" class="blError__icon">
                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
            </svg>
            <span>{{ error }}</span>
        </div>

        <!-- Skeleton -->
        <div v-if="loading" class="blTable">
            <div class="blTable__head">
                <span>Plan</span><span>Minutes</span><span>Amount</span><span>Status</span><span>Date</span>
            </div>
            <div v-for="i in 4" :key="i" class="blRow blRow--sk">
                <div class="blSk blSk--lg"></div>
                <div class="blSk blSk--sm"></div>
                <div class="blSk blSk--sm"></div>
                <div class="blSk blSk--pill"></div>
                <div class="blSk blSk--md"></div>
            </div>
        </div>

        <!-- Empty -->
        <div v-else-if="purchases.length === 0" class="blEmpty">
            <div class="blEmpty__icon">
                <svg viewBox="0 0 48 48" fill="none">
                    <rect x="8" y="12" width="32" height="24" rx="4" stroke="currentColor" stroke-width="2"/>
                    <path d="M8 20h32" stroke="currentColor" stroke-width="2"/>
                    <rect x="14" y="28" width="8" height="3" rx="1.5" fill="currentColor" opacity=".4"/>
                </svg>
            </div>
            <p class="blEmpty__title">No purchases yet</p>
            <p class="blEmpty__sub">Your billing history will appear here after you purchase a plan.</p>
            <router-link class="blBtn" :to="{ name: 'select-plan' }">Browse Plans</router-link>
        </div>

        <!-- Table -->
        <div v-else class="blTable">
            <div class="blTable__head">
                <span>Plan</span>
                <span>Minutes</span>
                <span>Amount</span>
                <span>Status</span>
                <span>Date</span>
            </div>

            <div v-for="p in purchases" :key="p.id" class="blRow">
                <span class="blRow__plan">{{ p.plan_name }}</span>
                <span class="blRow__minutes">{{ p.minutes_added.toLocaleString() }} min</span>
                <span class="blRow__amount">{{ formatAmount(p.amount_paid, p.currency) }}</span>
                <span
                    class="blStatus"
                    :class="{
                        'blStatus--completed': p.status === 'completed',
                        'blStatus--pending':   p.status === 'pending',
                        'blStatus--failed':    p.status === 'failed',
                        'blStatus--refunded':  p.status === 'refunded',
                    }"
                >{{ p.status }}</span>
                <span class="blRow__date">{{ formatDate(p.purchased_at ?? p.created_at) }}</span>
            </div>
        </div>
    </div>
</template>

<style scoped>
.blError {
    display: flex; align-items: center; gap: 10px;
    padding: var(--space-4);
    background: color-mix(in srgb, var(--color-error) 10%, transparent);
    border: 1px solid color-mix(in srgb, var(--color-error) 30%, transparent);
    border-radius: var(--radius-md);
    color: var(--color-error); font-size: var(--text-sm);
    margin-bottom: var(--space-6);
}
.blError__icon { width: 18px; height: 18px; flex-shrink: 0; }

.blTable {
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-lg);
    overflow: hidden;
}

.blTable__head {
    display: grid;
    grid-template-columns: 2fr 1fr 1fr 1fr 1fr;
    gap: var(--space-4);
    padding: var(--space-3) var(--space-5);
    background: var(--color-surface-2);
    border-bottom: 1px solid var(--color-border);
    font-size: var(--text-xs);
    font-weight: var(--weight-semibold);
    color: var(--color-muted);
    text-transform: uppercase;
    letter-spacing: var(--tracking-wide);
}

.blRow {
    display: grid;
    grid-template-columns: 2fr 1fr 1fr 1fr 1fr;
    gap: var(--space-4);
    align-items: center;
    padding: var(--space-4) var(--space-5);
    border-bottom: 1px solid var(--color-border);
    font-size: var(--text-sm);
}
.blRow:last-child { border-bottom: none; }
.blRow:hover { background: var(--color-surface-2); }

.blRow__plan { font-weight: var(--weight-semibold); color: var(--color-text); }
.blRow__minutes { color: var(--color-muted); }
.blRow__amount { font-weight: var(--weight-medium); color: var(--color-text); }
.blRow__date { color: var(--color-muted); }

.blStatus {
    display: inline-flex; align-items: center;
    padding: 3px 10px;
    border-radius: var(--radius-pill);
    font-size: 11px; font-weight: 700;
    text-transform: capitalize;
    letter-spacing: .03em;
    width: fit-content;
}
.blStatus--completed {
    background: color-mix(in srgb, var(--color-success) 12%, transparent);
    color: var(--color-success);
    border: 1px solid color-mix(in srgb, var(--color-success) 25%, transparent);
}
.blStatus--pending {
    background: color-mix(in srgb, var(--color-warning) 12%, transparent);
    color: var(--color-warning);
    border: 1px solid color-mix(in srgb, var(--color-warning) 25%, transparent);
}
.blStatus--failed {
    background: color-mix(in srgb, var(--color-error) 12%, transparent);
    color: var(--color-error);
    border: 1px solid color-mix(in srgb, var(--color-error) 25%, transparent);
}
.blStatus--refunded {
    background: color-mix(in srgb, var(--color-muted) 12%, transparent);
    color: var(--color-muted);
    border: 1px solid color-mix(in srgb, var(--color-muted) 25%, transparent);
}

/* Skeleton */
.blRow--sk { pointer-events: none; }
.blSk {
    border-radius: var(--radius-sm);
    background-image: linear-gradient(
        90deg,
        color-mix(in srgb, var(--color-text) 5%, transparent) 0%,
        color-mix(in srgb, var(--color-text) 10%, transparent) 50%,
        color-mix(in srgb, var(--color-text) 5%, transparent) 100%
    );
    background-size: 300% 100%;
    animation: blShimmer 1.5s ease-in-out infinite;
}
@keyframes blShimmer { 0% { background-position: 100% 0; } 100% { background-position: -100% 0; } }
.blSk--lg   { height: 16px; width: 70%; }
.blSk--md   { height: 14px; width: 60%; }
.blSk--sm   { height: 14px; width: 50%; }
.blSk--pill { height: 20px; width: 70px; border-radius: var(--radius-pill); }

/* Empty */
.blEmpty {
    display: flex; flex-direction: column; align-items: center;
    gap: var(--space-4); padding: var(--space-12) var(--space-6);
    text-align: center;
    border: 1px dashed var(--color-border-strong);
    border-radius: var(--radius-lg);
}
.blEmpty__icon { width: 56px; height: 56px; color: var(--color-muted); opacity: .4; }
.blEmpty__icon svg { width: 100%; height: 100%; }
.blEmpty__title { font-size: var(--text-xl); font-weight: 700; margin: 0; }
.blEmpty__sub { margin: 0; color: var(--color-muted); font-size: var(--text-sm); }
.blBtn {
    display: inline-flex; align-items: center;
    height: 38px; padding: 0 var(--space-5);
    border-radius: var(--radius-md);
    background: var(--color-primary); color: #fff;
    font-size: var(--text-sm); font-weight: var(--weight-semibold);
    text-decoration: none;
    transition: opacity 150ms;
}
.blBtn:hover { opacity: .88; }

@media (max-width: 640px) {
    .blTable__head { display: none; }
    .blRow {
        grid-template-columns: 1fr 1fr;
        grid-template-rows: auto auto;
        gap: var(--space-2);
    }
}
</style>
