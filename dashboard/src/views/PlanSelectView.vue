<script setup lang="ts">
import { ref, computed, onMounted } from "vue";
import { useRouter } from "vue-router";
import PageHeader from "../components/ui/PageHeader.vue";
import { userApi } from "../api/user";
import { auth } from "../composables/useAuth";

const hasCompany = computed(() => !!auth.state.user?.company_id);

const router = useRouter();

type Plan = {
    id: number;
    name: string;
    credits: number;
    minute_limit: number;
    price: string;
    sale_price: string | null;
    has_sale: boolean;
    discount_percent: number;
    effective_price: string;
    is_active: boolean;
    is_current: boolean;
};

const plans      = ref<Plan[]>([]);
const loading    = ref(true);
const error      = ref<string | null>(null);
const selectedId = ref<number | null>(null);
const purchasing = ref(false);
const purchaseError = ref<string | null>(null);

const selectedPlan = computed(() =>
    plans.value.find(p => p.id === selectedId.value) ?? null
);

async function loadPlans() {
    loading.value = true;
    error.value   = null;
    try {
        const res = await userApi.get<{ data: Plan[] }>("/plans/available");
        plans.value = res.data.data ?? [];
        // Auto-select the user's current plan if any
        const current = plans.value.find(p => p.is_current);
        if (current) selectedId.value = current.id;
    } catch {
        error.value = "Unable to load plans. Please try again or contact support.";
    } finally {
        loading.value = false;
    }
}

function selectPlan(plan: Plan) {
    selectedId.value = plan.id;
    purchaseError.value = null;
}

async function purchase() {
    if (!selectedId.value) return;
    purchasing.value = true;
    purchaseError.value = null;
    try {
        const res = await userApi.post<{ checkout_url: string }>("/stripe/create-checkout", {
            plan_id: selectedId.value,
        });
        window.location.href = res.data.checkout_url;
    } catch (err: any) {
        purchaseError.value =
            err?.response?.data?.message ?? "Failed to start checkout. Please try again.";
    } finally {
        purchasing.value = false;
    }
}

function skip() {
    router.replace("/dashboard");
}

onMounted(loadPlans);
</script>

<template>
    <div class="page">
        <PageHeader
            title="Choose a Plan"
            :description="`Welcome, ${auth.state.user?.name ?? 'there'}! Select the plan that fits your needs.`"
        />

        <!-- No company assigned alert -->
        <div v-if="!hasCompany" class="spNoCompany">
            <div class="spNoCompany__icon">
                <svg viewBox="0 0 24 24" fill="none">
                    <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="1.8"/>
                    <path d="M12 8v4m0 4h.01" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                </svg>
            </div>
            <div class="spNoCompany__body">
                <strong>Account not linked to a company</strong>
                <p>
                    You must be assigned to a company before you can purchase a plan.
                    Please contact your administrator to get assigned.
                </p>
            </div>
        </div>

        <!-- Error loading plans -->
        <div v-if="error" class="spError">
            <svg viewBox="0 0 20 20" fill="currentColor" class="spError__icon">
                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
            </svg>
            <span>{{ error }}</span>
        </div>

        <!-- Purchase error -->
        <div v-if="purchaseError" class="spError">
            <svg viewBox="0 0 20 20" fill="currentColor" class="spError__icon">
                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
            </svg>
            <span>{{ purchaseError }}</span>
        </div>

        <!-- Loading skeleton -->
        <div v-if="loading" class="spGrid">
            <div v-for="i in 3" :key="i" class="spCard spCard--sk">
                <div class="spSk spSk--tag"></div>
                <div class="spSk spSk--name"></div>
                <div class="spSk spSk--price"></div>
                <div class="spSk spSk--pill"></div>
                <div class="spSk spSk--btn"></div>
            </div>
        </div>

        <!-- Empty -->
        <div v-else-if="!loading && plans.length === 0" class="spEmpty">
            <div class="spEmpty__icon">
                <svg viewBox="0 0 48 48" fill="none">
                    <circle cx="24" cy="24" r="20" stroke="currentColor" stroke-width="2"/>
                    <path d="M24 14v2m0 8v2M24 18a4 4 0 0 0-4 4c0 2.2 1.8 4 4 4a4 4 0 0 1 4 4 4 4 0 0 1-4 4m0-16a4 4 0 0 1 4 4" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                </svg>
            </div>
            <p class="spEmpty__title">No plans available</p>
            <p class="spEmpty__sub">Contact your administrator to set up plans.</p>
            <button class="spBtn spBtn--outline" @click="skip">Go to Dashboard</button>
        </div>

        <!-- Plan cards -->
        <template v-else>
            <div class="spGrid">
                <div
                    v-for="plan in plans"
                    :key="plan.id"
                    class="spCard"
                    :class="{
                        'spCard--selected': selectedId === plan.id,
                        'spCard--current': plan.is_current,
                    }"
                    @click="selectPlan(plan)"
                    role="button"
                    :aria-pressed="selectedId === plan.id"
                >
                    <!-- Active Plan badge — sits on the top border, centered -->
                    <div v-if="plan.is_current" class="spCurrentBadge">
                        <svg viewBox="0 0 12 12" fill="currentColor" width="10" height="10"><path d="M6 1l1.2 3.6H11L8.4 6.8l1 3.2L6 8.2 2.6 10l1-3.2L1 4.6h3.8z"/></svg>
                        Active Plan
                    </div>

                    <!-- Sale ribbon -->
                    <div v-else-if="plan.has_sale" class="spRibbon">{{ plan.discount_percent }}% OFF</div>

                    <!-- Selected tick -->
                    <div v-if="selectedId === plan.id" class="spTick">
                        <svg viewBox="0 0 16 16" fill="none">
                            <circle cx="8" cy="8" r="8" fill="currentColor" opacity=".15"/>
                            <path d="M4.5 8l2.5 2.5 4-4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </div>

                    <!-- Card body -->
                    <div class="spCard__body">
                        <h3 class="spCard__name">{{ plan.name }}</h3>

                        <div class="spCard__pricing">
                            <div class="spCard__priceRow">
                                <template v-if="plan.has_sale">
                                    <span class="spCard__priceOld">${{ plan.price }}</span>
                                    <span class="spCard__priceSale">${{ plan.sale_price }}</span>
                                </template>
                                <span v-else class="spCard__price">${{ plan.price }}</span>
                            </div>
                            <span class="spCard__per">/purchase</span>
                        </div>

                        <div class="spCard__minutePill">
                            <svg viewBox="0 0 16 16" fill="none" class="spCard__minIcon">
                                <circle cx="8" cy="8" r="6.5" stroke="currentColor" stroke-width="1.5"/>
                                <path d="M8 5v3l2 2" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
                            </svg>
                            <strong>{{ Number(plan.credits ?? 0).toLocaleString() }}</strong>
                            <span>credits included</span>
                        </div>
                    </div>

                    <button
                        class="spCard__btn"
                        :class="{
                            'spCard__btn--selected': selectedId === plan.id,
                            'spCard__btn--current': plan.is_current,
                        }"
                        @click.stop="selectPlan(plan)"
                    >
                        <span v-if="plan.is_current">✓ Current Plan</span>
                        <span v-else-if="selectedId === plan.id">✓ Selected</span>
                        <span v-else>Select Plan</span>
                    </button>
                </div>
            </div>

            <!-- Selected plan summary + actions -->
            <div class="spFooter">
                <div class="spSummary" v-if="selectedPlan">
                    <div class="spSummary__badge">Selected</div>
                    <span class="spSummary__name">{{ selectedPlan.name }}</span>
                    <span class="spSummary__sep">·</span>
                    <span class="spSummary__minutes">{{ Number(selectedPlan.credits ?? 0).toLocaleString() }} credits</span>
                    <span class="spSummary__sep">·</span>
                    <span class="spSummary__price">
                        <template v-if="selectedPlan.has_sale">
                            <s>${{ selectedPlan.price }}</s> ${{ selectedPlan.sale_price }}
                        </template>
                        <template v-else>${{ selectedPlan.price }}</template>
                    </span>
                </div>
                <div v-else class="spSummary spSummary--empty">Select a plan above to continue.</div>

                <div class="spActions">
                    <button class="spBtn spBtn--outline" @click="skip">Skip for now</button>
                    <button
                        class="spBtn spBtn--primary"
                        :disabled="!selectedId || purchasing || !hasCompany"
                        :title="!hasCompany ? 'Contact your administrator to be assigned to a company first' : undefined"
                        @click="purchase"
                    >
                        <span v-if="purchasing" class="spBtn__spinner"></span>
                        <svg v-else viewBox="0 0 20 20" fill="currentColor" class="spBtn__icon">
                            <path d="M4 4a2 2 0 00-2 2v1h16V6a2 2 0 00-2-2H4zM18 9H2v5a2 2 0 002 2h12a2 2 0 002-2V9zM4 13a1 1 0 011-1h1a1 1 0 110 2H5a1 1 0 01-1-1zm5-1a1 1 0 100 2h1a1 1 0 100-2H9z"/>
                        </svg>
                        Purchase with Stripe
                    </button>
                </div>
            </div>

            <p class="spNote">
                You will be redirected to Stripe's secure checkout. Minutes are credited to your account immediately after payment confirmation.
            </p>
        </template>
    </div>
</template>

<style scoped>
/* ── No Company Alert ─────────────────────────────────────────────────── */
.spNoCompany {
    display: flex; align-items: flex-start; gap: var(--space-4);
    padding: var(--space-5);
    background: color-mix(in srgb, #f59e0b 10%, transparent);
    border: 1px solid color-mix(in srgb, #f59e0b 40%, transparent);
    border-radius: var(--radius-md);
    margin-bottom: var(--space-6);
}
.spNoCompany__icon {
    width: 24px; height: 24px; flex-shrink: 0;
    color: #b45309; margin-top: 1px;
}
.spNoCompany__icon svg { width: 100%; height: 100%; }
.spNoCompany__body { display: flex; flex-direction: column; gap: 4px; }
.spNoCompany__body strong { color: #92400e; font-size: var(--text-sm); font-weight: 700; }
.spNoCompany__body p { margin: 0; color: #a16207; font-size: var(--text-sm); line-height: var(--leading-relaxed); }

/* ── Error ───────────────────────────────────────────────────────────────── */
.spError {
    display: flex; align-items: center; gap: 10px;
    padding: var(--space-4);
    background: color-mix(in srgb, var(--color-error) 10%, transparent);
    border: 1px solid color-mix(in srgb, var(--color-error) 30%, transparent);
    border-radius: var(--radius-md);
    color: var(--color-error);
    font-size: var(--text-sm);
    margin-bottom: var(--space-6);
}
.spError__icon { width: 18px; height: 18px; flex-shrink: 0; }

/* ── Grid ────────────────────────────────────────────────────────────────── */
.spGrid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
    gap: var(--space-5);
    margin-bottom: var(--space-6);
}

/* ── Card ────────────────────────────────────────────────────────────────── */
.spCard {
    position: relative;
    background: var(--color-surface);
    border: 2px solid var(--color-border);
    border-radius: var(--radius-lg);
    padding: var(--space-6);
    display: flex;
    flex-direction: column;
    gap: var(--space-5);
    cursor: pointer;
    transition: border-color 150ms var(--ease-standard),
                box-shadow 150ms var(--ease-standard),
                transform 150ms var(--ease-standard);
    overflow: visible;
    box-shadow: var(--shadow-sm);
    margin-top: 14px; /* room for the badge above the top border */
}
.spCard:hover {
    border-color: var(--color-primary);
    box-shadow: 0 0 0 1px var(--color-primary), var(--shadow-md);
    transform: translateY(-2px);
}
.spCard--selected {
    border-color: var(--color-primary) !important;
    box-shadow: 0 0 0 2px var(--color-primary),
                0 0 24px color-mix(in srgb, var(--color-primary) 20%, transparent) !important;
    transform: translateY(-2px);
}
.spCard--current {
    border-color: var(--color-success) !important;
    box-shadow: 0 0 0 2px var(--color-success),
                0 0 24px color-mix(in srgb, var(--color-success) 15%, transparent) !important;
}

/* Current badge — centered on the top border */
.spCurrentBadge {
    position: absolute;
    top: -14px;
    left: 50%;
    transform: translateX(-50%);
    background: var(--color-success);
    color: #fff;
    font-size: 10px;
    font-weight: 800;
    letter-spacing: .07em;
    padding: 4px 12px;
    border-radius: var(--radius-pill);
    box-shadow: 0 2px 8px rgba(34,197,94,.45);
    white-space: nowrap;
    display: flex;
    align-items: center;
    gap: 4px;
    z-index: 1;
}

/* Tick */
.spTick {
    position: absolute; top: 14px; right: 14px;
    color: var(--color-primary); width: 22px; height: 22px;
}
.spTick svg { width: 100%; height: 100%; }

/* Ribbon */
.spRibbon {
    position: absolute; top: 16px; right: -22px;
    background: linear-gradient(135deg, var(--color-warning), #d97706);
    color: #fff; font-size: 10px; font-weight: 800;
    letter-spacing: .07em; padding: 4px 28px;
    transform: rotate(35deg);
    box-shadow: 0 2px 6px rgba(245,158,11,.4);
    line-height: 1.4;
}

/* Body */
.spCard__body { display: flex; flex-direction: column; gap: var(--space-4); flex: 1; }

.spCard__name {
    font-size: var(--text-lg);
    font-weight: var(--weight-bold);
    color: var(--color-text);
    margin: 0;
    line-height: var(--leading-snug);
}

/* Pricing */
.spCard__pricing {
    display: flex;
    align-items: flex-end;
    gap: 6px;
}
/* Inner row: old + sale (or just price) */
.spCard__priceRow {
    display: flex;
    flex-direction: column;
    gap: 2px;
    line-height: 1;
}
.spCard__price {
    font-size: clamp(1.6rem, 4vw, 2.1rem);
    font-weight: 800;
    color: var(--color-text);
    line-height: 1;
    letter-spacing: var(--tracking-tight);
}
.spCard__priceOld {
    font-size: 0.82rem;
    font-weight: 500;
    color: var(--color-muted);
    text-decoration: line-through;
    line-height: 1;
}
.spCard__priceSale {
    font-size: clamp(1.6rem, 4vw, 2.1rem);
    font-weight: 800;
    color: var(--color-success);
    line-height: 1;
    letter-spacing: var(--tracking-tight);
}
.spCard__per {
    font-size: 0.78rem;
    color: var(--color-muted);
    font-weight: 500;
    padding-bottom: 3px;
}

/* Minutes pill */
.spCard__minutePill {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 7px 12px;
    background: color-mix(in srgb, var(--color-primary) 8%, transparent);
    border: 1px solid color-mix(in srgb, var(--color-primary) 18%, transparent);
    border-radius: var(--radius-sm);
    font-size: var(--text-sm);
    color: var(--color-text);
    width: fit-content;
}
.spCard__minIcon { width: 14px; height: 14px; color: var(--color-primary); flex-shrink: 0; }
.spCard__minutePill strong { font-weight: 700; color: var(--color-primary); }
.spCard__minutePill span { color: var(--color-muted); }

/* CTA button */
.spCard__btn {
    width: 100%;
    height: 42px;
    border-radius: var(--radius-md);
    border: 2px solid var(--color-primary);
    background: transparent;
    color: var(--color-primary);
    font-size: var(--text-sm);
    font-weight: var(--weight-semibold);
    cursor: pointer;
    transition: background 150ms, color 150ms, border-color 150ms;
    letter-spacing: .01em;
}
.spCard__btn:hover,
.spCard__btn--selected {
    background: var(--color-primary);
    color: #fff;
}
.spCard__btn--current {
    border-color: var(--color-success);
    color: var(--color-success);
}
.spCard__btn--current:hover {
    background: var(--color-success);
    color: #fff;
}

/* ── Skeleton ────────────────────────────────────────────────────────────── */
.spCard--sk { cursor: default; pointer-events: none; }
.spCard--sk:hover { transform: none; box-shadow: var(--shadow-sm); border-color: var(--color-border); }

.spSk {
    border-radius: var(--radius-sm);
    background-image: linear-gradient(
        90deg,
        color-mix(in srgb, var(--color-text) 5%, transparent) 0%,
        color-mix(in srgb, var(--color-text) 10%, transparent) 50%,
        color-mix(in srgb, var(--color-text) 5%, transparent) 100%
    );
    background-size: 300% 100%;
    animation: spShimmer 1.5s ease-in-out infinite;
}
@keyframes spShimmer { 0% { background-position: 100% 0; } 100% { background-position: -100% 0; } }
.spSk--tag   { height: 14px; width: 50px; }
.spSk--name  { height: 22px; width: 55%; }
.spSk--price { height: 42px; width: 40%; border-radius: 6px; }
.spSk--pill  { height: 32px; width: 75%; border-radius: var(--radius-sm); }
.spSk--btn   { height: 42px; width: 100%; border-radius: var(--radius-md); margin-top: 4px; }

/* ── Empty ───────────────────────────────────────────────────────────────── */
.spEmpty {
    display: flex; flex-direction: column; align-items: center;
    gap: var(--space-4); padding: var(--space-12) var(--space-6);
    text-align: center;
    border: 1px dashed var(--color-border-strong);
    border-radius: var(--radius-lg);
    margin-bottom: var(--space-6);
}
.spEmpty__icon { width: 56px; height: 56px; color: var(--color-muted); opacity: .4; }
.spEmpty__icon svg { width: 100%; height: 100%; }
.spEmpty__title { font-size: var(--text-xl); font-weight: 700; margin: 0; }
.spEmpty__sub { margin: 0; color: var(--color-muted); font-size: var(--text-sm); }

/* ── Footer ──────────────────────────────────────────────────────────────── */
.spFooter {
    display: flex; align-items: center; justify-content: space-between;
    gap: var(--space-4); flex-wrap: wrap;
    padding: var(--space-4) var(--space-5);
    background: var(--color-surface-2);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    margin-bottom: var(--space-4);
}

.spSummary {
    display: flex; align-items: center; gap: var(--space-3); flex-wrap: wrap;
    font-size: var(--text-sm);
}
.spSummary--empty { color: var(--color-muted); font-style: italic; }
.spSummary__badge {
    background: color-mix(in srgb, var(--color-primary) 12%, transparent);
    color: var(--color-primary);
    border: 1px solid color-mix(in srgb, var(--color-primary) 25%, transparent);
    border-radius: var(--radius-pill);
    padding: 2px 10px; font-size: 11px; font-weight: 700; letter-spacing: .04em;
}
.spSummary__name { font-weight: 700; color: var(--color-text); }
.spSummary__sep { color: var(--color-muted); }
.spSummary__minutes { color: var(--color-muted); }
.spSummary__price { font-weight: 600; color: var(--color-text); }
.spSummary__price s { opacity: .45; margin-right: 4px; font-weight: 400; }

.spActions { display: flex; gap: var(--space-3); flex-shrink: 0; }

/* ── Buttons ─────────────────────────────────────────────────────────────── */
.spBtn {
    display: inline-flex; align-items: center; gap: var(--space-2);
    height: 40px; padding: 0 var(--space-5);
    border-radius: var(--radius-md);
    font-size: var(--text-sm); font-weight: var(--weight-semibold);
    cursor: pointer; border: none; white-space: nowrap;
    transition: background 150ms, box-shadow 150ms, transform 150ms, opacity 150ms;
}
.spBtn--primary {
    background: linear-gradient(135deg, var(--color-primary), #1d4ed8);
    color: #fff;
    box-shadow: 0 2px 10px color-mix(in srgb, var(--color-primary) 35%, transparent);
}
.spBtn--primary:hover:not(:disabled) {
    box-shadow: 0 4px 16px color-mix(in srgb, var(--color-primary) 45%, transparent);
    transform: translateY(-1px);
}
.spBtn--primary:disabled { opacity: .45; cursor: not-allowed; transform: none; }
.spBtn--outline {
    background: transparent;
    border: 1.5px solid var(--color-border-strong);
    color: var(--color-muted);
}
.spBtn--outline:hover { background: var(--color-surface-2); color: var(--color-text); }
.spBtn__spinner {
    width: 14px; height: 14px;
    border: 2px solid rgba(255,255,255,.35);
    border-top-color: #fff; border-radius: 50%;
    animation: spSpin .7s linear infinite;
}
.spBtn__icon { width: 16px; height: 16px; flex-shrink: 0; }
@keyframes spSpin { to { transform: rotate(360deg); } }

/* ── Note ────────────────────────────────────────────────────────────────── */
.spNote {
    font-size: var(--text-sm);
    color: var(--color-muted);
    text-align: center;
    margin: 0;
    line-height: var(--leading-relaxed);
}

/* ── Responsive ──────────────────────────────────────────────────────────── */
@media (max-width: 640px) {
    .spGrid { grid-template-columns: 1fr; }
    .spFooter { flex-direction: column; align-items: stretch; }
    .spActions { width: 100%; }
    .spBtn { flex: 1; justify-content: center; }
}

@media (prefers-reduced-motion: reduce) {
    .spCard, .spCard:hover, .spCard--selected { transform: none; transition: none; }
    .spSk, .spBtn__spinner { animation: none; }
}
</style>
