<script setup lang="ts">
import { ref, computed, onMounted } from "vue";
import { useRoute, useRouter } from "vue-router";
import { auth } from "../composables/useAuth";
import { userApi } from "../api/user";
import { useToasts } from "../composables/useToasts";
import Breadcrumb from "../components/ui/Breadcrumb.vue";

const router = useRouter();
const route  = useRoute();
const toasts = useToasts();

type Plan = {
    id: number;
    name: string;
    credits: number;
    price: string;
    sale_price: string | null;
    description: string | null;
    has_sale: boolean;
    discount_percent: number;
    effective_price: string;
    is_active: boolean;
    is_featured: boolean;
};

type CreditHistoryEntry = {
    key: string;
    type: "purchase" | "auto_topup" | "adjustment" | "refund" | "deduction" | "usage";
    label: string;
    credits: number;
    balance_after: number | null;
    date: string | null;
    report_week: string | null;
};

type AutoTopup = {
    enabled: boolean;
    threshold: number | null;
    credits: number | null;
    has_payment_method: boolean;
    paused_at: string | null;
    failure_count: number;
};

const hasCompany = computed(() => !!auth.state.user?.company_id);

// ── Plan packs ───────────────────────────────────────────────────────────────
const plans        = ref<Plan[]>([]);
const plansLoading  = ref(true);
const plansError    = ref<string | null>(null);
const purchasingId  = ref<number | null>(null);
const purchaseError = ref<string | null>(null);

async function loadPlans() {
    plansLoading.value = true;
    plansError.value   = null;
    try {
        const res = await userApi.get<{ data: Plan[] }>("/plans/available");
        plans.value = res.data.data ?? [];
    } catch {
        plansError.value = "Unable to load credit packs. Please try again or contact support.";
    } finally {
        plansLoading.value = false;
    }
}

function perCreditPrice(p: Plan): string {
    const credits = Number(p.credits) || 0;
    if (credits <= 0) return "0.00";
    return (Number(p.effective_price) / credits).toFixed(2);
}

async function purchase(plan: Plan) {
    purchasingId.value  = plan.id;
    purchaseError.value = null;
    try {
        const res = await userApi.post<{ checkout_url: string }>("/stripe/create-checkout", {
            plan_id: plan.id,
        });
        window.location.href = res.data.checkout_url;
    } catch (err: any) {
        purchaseError.value =
            err?.response?.data?.message ?? "Failed to start checkout. Please try again.";
        purchasingId.value = null;
    }
}

// ── Credit economics + auto top-up ───────────────────────────────────────────
const creditPriceUsd   = ref(1);
const creditsPerMinute = ref(1);
const autoTopup   = ref<AutoTopup | null>(null);
const atForm      = ref({ enabled: false, threshold: 50, credits: 500 });
const atLoading   = ref(true);
const atSaving    = ref(false);
const atError     = ref<string | null>(null);
const atNotice    = ref<string | null>(null);
const cardSetupOpen = ref(false);
const cardSetupBusy = ref(false);
// eslint-disable-next-line @typescript-eslint/no-explicit-any
let stripe: any = null;
// eslint-disable-next-line @typescript-eslint/no-explicit-any
let cardElement: any = null;
let setupClientSecret: string | null = null;

const minutesPerCredit = computed(() =>
    creditsPerMinute.value > 0 ? 1 / creditsPerMinute.value : 1
);

const autoTopupEstimatedCost = computed(() => {
    const c = Number(atForm.value.credits) || 0;
    return c * creditPriceUsd.value;
});

function fmtUsd(n: number): string {
    return new Intl.NumberFormat("en-US", { style: "currency", currency: "USD" }).format(n);
}

async function loadPlanEconomics() {
    atLoading.value = true;
    try {
        const res = await userApi.get<{
            credit_price_usd: number;
            credits_per_minute: number;
            auto_topup: AutoTopup;
        }>("/plan");
        creditPriceUsd.value   = res.data.credit_price_usd ?? 1;
        creditsPerMinute.value = res.data.credits_per_minute ?? 1;
        autoTopup.value = res.data.auto_topup;
        atForm.value.enabled = res.data.auto_topup.enabled;
        if (res.data.auto_topup.threshold !== null) atForm.value.threshold = Number(res.data.auto_topup.threshold);
        if (res.data.auto_topup.credits !== null) atForm.value.credits = Number(res.data.auto_topup.credits);
    } catch {
        // Non-fatal — the rest of the page still works.
    } finally {
        atLoading.value = false;
    }
}

function loadStripeJs(): Promise<void> {
    return new Promise((resolve, reject) => {
        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        if ((window as any).Stripe) return resolve();
        const script = document.createElement("script");
        script.src = "https://js.stripe.com/v3";
        script.onload  = () => resolve();
        script.onerror = () => reject(new Error("Failed to load Stripe.js"));
        document.head.appendChild(script);
    });
}

async function startCardSetup() {
    atError.value  = null;
    atNotice.value = null;
    cardSetupBusy.value = true;
    try {
        const res = await userApi.post<{ client_secret: string; publishable_key: string }>(
            "/auto-topup/setup-intent",
            {},
        );
        setupClientSecret = res.data.client_secret;
        await loadStripeJs();
        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        stripe = (window as any).Stripe(res.data.publishable_key);
        cardSetupOpen.value = true;
        await new Promise((r) => setTimeout(r, 0));
        const elements = stripe.elements();
        cardElement = elements.create("card");
        cardElement.mount("#at-card-element");
    } catch (e: unknown) {
        atError.value =
            (e as { response?: { data?: { message?: string } } })?.response?.data?.message ??
            "Could not start card setup.";
    } finally {
        cardSetupBusy.value = false;
    }
}

async function confirmCardSetup() {
    if (!stripe || !cardElement || !setupClientSecret) return;
    atError.value = null;
    cardSetupBusy.value = true;
    try {
        const result = await stripe.confirmCardSetup(setupClientSecret, {
            payment_method: { card: cardElement },
        });
        if (result.error) {
            atError.value = result.error.message ?? "Card setup failed.";
            return;
        }
        await saveAutoTopup(result.setupIntent.payment_method as string);
        cardSetupOpen.value = false;
    } finally {
        cardSetupBusy.value = false;
    }
}

async function saveAutoTopup(paymentMethodId?: string) {
    atSaving.value = true;
    atError.value  = null;
    atNotice.value = null;
    try {
        const res = await userApi.post<{ message: string; data: AutoTopup }>("/auto-topup", {
            enabled: atForm.value.enabled,
            threshold: atForm.value.threshold,
            credits: atForm.value.credits,
            payment_method_id: paymentMethodId ?? null,
        });
        atNotice.value = res.data.message;
        await loadPlanEconomics();
    } catch (e: unknown) {
        atError.value =
            (e as { response?: { data?: { message?: string } } })?.response?.data?.message ??
            "Failed to save auto top-up settings.";
    } finally {
        atSaving.value = false;
    }
}

// ── Credit history ────────────────────────────────────────────────────────────
// Purchases, auto top-ups, manual adjustments and refunds each as their own
// row, plus per-call usage rolled up by the weekly report it paid for — a
// deduction-per-call ledger isn't useful to read one row at a time.
const historyEntries = ref<CreditHistoryEntry[]>([]);
const historyLoading = ref(true);
const historyError   = ref<string | null>(null);

async function loadHistory() {
    historyLoading.value = true;
    historyError.value   = null;
    try {
        const res = await userApi.get<{ data: CreditHistoryEntry[] }>("/credits/history");
        historyEntries.value = res.data.data ?? [];
    } catch {
        historyError.value = "Unable to load credit history.";
    } finally {
        historyLoading.value = false;
    }
}

function formatDate(iso: string | null): string {
    if (!iso) return "—";
    return new Date(iso).toLocaleDateString(undefined, { year: "numeric", month: "short", day: "numeric" });
}

function formatCredits(v: number): string {
    const sign = v > 0 ? "+" : "";
    return sign + v.toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 2 });
}

const HISTORY_TYPE_LABEL: Record<string, string> = {
    purchase: "Purchase",
    auto_topup: "Auto top-up",
    adjustment: "Adjustment",
    refund: "Refund",
    deduction: "Adjustment",
    usage: "Usage",
};

function historyTypeLabel(entry: CreditHistoryEntry): string {
    return HISTORY_TYPE_LABEL[entry.type] ?? entry.type;
}

function historyTypeClass(entry: CreditHistoryEntry): string {
    if (entry.credits > 0) return "badge--active";
    if (entry.type === "usage") return "badge--processing";
    return "badge--failed";
}

function openHistoryReport(entry: CreditHistoryEntry) {
    if (!entry.report_week || !auth.state.user?.company_slug) return;
    router.push({
        name: "report-detail",
        params: { companySlug: auth.state.user.company_slug, weekStart: entry.report_week },
    });
}

// If the customer backed out of Stripe Checkout, Stripe bounces them back
// here via cancel_url with ?cancelled=1&session_id=... — mark that specific
// pending purchase as cancelled right away instead of leaving it "Pending"
// forever, then strip the query string so a refresh doesn't repeat it.
async function handleCheckoutCancelled() {
    if (route.query.cancelled !== "1") return;

    const sessionId = route.query.session_id as string | undefined;
    if (sessionId) {
        try {
            await userApi.post(`/stripe/cancel/${sessionId}`);
        } catch {
            // Non-fatal — worst case it just stays pending until Stripe's
            // own session expiry reaches the webhook.
        }
    }

    toasts.push({ variant: "success", title: "Checkout cancelled", message: "No charge was made." });
    router.replace({ name: "select-plan" });
}

onMounted(() => {
    loadPlans();
    loadPlanEconomics();
    loadHistory();
    handleCheckoutCancelled();
});
</script>

<template>
    <div class="bcPage">
        <Breadcrumb :items="[{ label: 'Billing' }, { label: 'Buy Credits' }]" />

        <!-- Header -->
        <div class="bcHead">
            <div class="bcHead__copy">
                <h1 class="bcTitle">Buy credits</h1>
                <p class="bcSub">
                    Credits pay for call analysis. Your weekly report runs automatically as long as
                    there's a balance — top up here, or switch on auto top-up so it never pauses.
                </p>
            </div>

            <div class="bcInfoCard">
                <p class="bcInfoCard__title">How credits work</p>
                <ul class="bcInfoCard__list">
                    <li>
                        <svg viewBox="0 0 20 20" fill="none"><path d="M4.5 10.5l3.5 3.5 7.5-7.5" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        <span>1 credit covers {{ minutesPerCredit === 1 ? "1 minute" : `${minutesPerCredit.toFixed(minutesPerCredit % 1 === 0 ? 0 : 1)} minutes` }} of analysed call</span>
                    </li>
                    <li>
                        <svg viewBox="0 0 20 20" fill="none"><path d="M4.5 10.5l3.5 3.5 7.5-7.5" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        <span>Charged to the exact second — no rounding up</span>
                    </li>
                    <li>
                        <svg viewBox="0 0 20 20" fill="none"><path d="M4.5 10.5l3.5 3.5 7.5-7.5" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        <span>Credits never expire</span>
                    </li>
                </ul>
            </div>
        </div>

        <!-- No company assigned -->
        <div v-if="!hasCompany" class="bcAlert bcAlert--warn">
            <span>You must be assigned to a company before you can purchase credits. Please contact your administrator.</span>
        </div>

        <div v-if="plansError" class="bcAlert bcAlert--error">{{ plansError }}</div>
        <div v-if="purchaseError" class="bcAlert bcAlert--error">{{ purchaseError }}</div>

        <!-- Plan cards -->
        <div class="bcGrid">
            <template v-if="plansLoading">
                <div v-for="i in 3" :key="i" class="bcCard bcCard--sk">
                    <div class="bcSk bcSk--name"></div>
                    <div class="bcSk bcSk--price"></div>
                    <div class="bcSk bcSk--line"></div>
                    <div class="bcSk bcSk--pill"></div>
                    <div class="bcSk bcSk--btn"></div>
                </div>
            </template>

            <template v-else-if="plans.length === 0">
                <div class="bcEmpty">
                    <p class="bcEmpty__title">No credit packs available</p>
                    <p class="bcEmpty__sub">Contact your administrator to set up credit packs.</p>
                </div>
            </template>

            <template v-else>
                <div
                    v-for="plan in plans"
                    :key="plan.id"
                    class="bcCard"
                    :class="{ 'bcCard--featured': plan.is_featured }"
                >
                    <div v-if="plan.is_featured" class="bcCard__badge">Most Popular</div>

                    <p class="bcCard__name">{{ plan.name }}</p>

                    <div class="bcCard__price">
                        <template v-if="plan.has_sale">
                            <span class="bcCard__priceNow">${{ plan.sale_price }}</span>
                            <span class="bcCard__priceOld">${{ plan.price }}</span>
                        </template>
                        <span v-else class="bcCard__priceNow">${{ plan.price }}</span>
                    </div>
                    <p class="bcCard__perCredit">
                        ${{ perCreditPrice(plan) }} per credit
                        <template v-if="plan.has_sale"> · save {{ plan.discount_percent }}%</template>
                    </p>

                    <div class="bcCard__divider"></div>

                    <p class="bcCard__credits">
                        <strong>{{ Number(plan.credits ?? 0).toLocaleString() }}</strong> credits
                    </p>
                    <p v-if="plan.description" class="bcCard__desc">{{ plan.description }}</p>

                    <button
                        class="bcCard__btn"
                        :class="{ 'bcCard__btn--primary': plan.is_featured }"
                        :disabled="purchasingId === plan.id || !hasCompany"
                        @click="purchase(plan)"
                    >
                        <span v-if="purchasingId === plan.id" class="bcSpinner"></span>
                        <template v-else>
                            <svg v-if="plan.is_featured" viewBox="0 0 20 20" fill="currentColor" class="bcCard__btnIcon"><path d="M4 4a2 2 0 00-2 2v1h16V6a2 2 0 00-2-2H4zM18 9H2v5a2 2 0 002 2h12a2 2 0 002-2V9zM4 13a1 1 0 011-1h1a1 1 0 110 2H5a1 1 0 01-1-1zm5-1a1 1 0 100 2h1a1 1 0 100-2H9z"/></svg>
                            {{ plan.is_featured ? "Pay with card" : `Choose ${plan.name}` }}
                        </template>
                    </button>
                </div>
            </template>
        </div>

        <!-- Auto top-up + Purchase history -->
        <div class="bcLower">
            <!-- Auto top-up -->
            <div class="bcPanel">
                <div class="bcPanel__head">
                    <div>
                        <h2 class="bcPanel__title">Auto top-up</h2>
                        <p class="bcPanel__sub">Keep the weekly report running without thinking about it.</p>
                    </div>
                    <label class="bcToggle" :class="{ 'bcToggle--on': atForm.enabled }">
                        <input type="checkbox" v-model="atForm.enabled" />
                        <span class="bcToggle__track"><span class="bcToggle__thumb"></span></span>
                    </label>
                </div>

                <template v-if="!atLoading">
                    <div v-if="atError" class="bcAlert bcAlert--error">{{ atError }}</div>
                    <div v-if="atNotice" class="bcAlert bcAlert--success">{{ atNotice }}</div>
                    <div v-if="autoTopup?.paused_at" class="bcAlert bcAlert--error">
                        Auto top-up is paused after repeated payment failures. Update your card and save to re-enable it.
                    </div>

                    <div class="bcFieldRow">
                        <label class="bcField">
                            <span>When balance drops below</span>
                            <div class="bcField__inputWrap">
                                <input type="number" min="0" v-model.number="atForm.threshold" placeholder="50" />
                                <span class="bcField__unit">credits</span>
                            </div>
                        </label>
                        <label class="bcField">
                            <span>Automatically buy</span>
                            <div class="bcField__inputWrap">
                                <input type="number" min="1" v-model.number="atForm.credits" placeholder="500" />
                                <span class="bcField__unit">credits</span>
                            </div>
                        </label>
                    </div>

                    <p class="bcEstimate">
                        ≈ <strong>{{ fmtUsd(autoTopupEstimatedCost) }}</strong> will be charged at ${{ creditPriceUsd.toFixed(2) }} per credit
                    </p>

                    <div v-if="!autoTopup?.has_payment_method" class="bcAlert bcAlert--warn">
                        <svg viewBox="0 0 20 20" fill="currentColor" class="bcAlert__icon"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
                        <span>Add a card to switch this on. We'll only charge it when the balance runs low.</span>
                    </div>

                    <div class="bcPanel__actions">
                        <button class="bcBtn bcBtn--outline" :disabled="cardSetupBusy" @click="startCardSetup">
                            {{ autoTopup?.has_payment_method ? "Update Card" : "Add Card" }}
                        </button>
                        <button class="bcBtn" :disabled="atSaving" @click="saveAutoTopup()">
                            {{ atSaving ? "Saving…" : "Save Settings" }}
                        </button>
                    </div>

                    <div v-show="cardSetupOpen" class="bcCardSetup">
                        <div id="at-card-element" class="bcCardElement"></div>
                        <button class="bcBtn" :disabled="cardSetupBusy" @click="confirmCardSetup">
                            {{ cardSetupBusy ? "Saving…" : "Save Card" }}
                        </button>
                    </div>
                </template>
                <template v-else>
                    <div class="bcSk bcSk--line" style="width:100%;height:38px;margin-bottom:10px"></div>
                    <div class="bcSk bcSk--line" style="width:100%;height:38px"></div>
                </template>
            </div>

            <!-- Credit history -->
            <div class="bcPanel bcPanel--history">
                <h2 class="bcPanel__title">Credit history</h2>
                <p class="bcPanel__sub">Every purchase, top-up and report that spent your credits.</p>

                <div v-if="historyError" class="bcAlert bcAlert--error">{{ historyError }}</div>

                <div v-if="historyLoading" class="bcHistTableWrap">
                    <table class="bcHistTable">
                        <thead><tr><th>Date</th><th>Description</th><th>Type</th><th class="bcCol--right">Credits</th><th class="bcCol--right">Balance</th></tr></thead>
                        <tbody>
                            <tr v-for="n in 4" :key="n"><td colspan="5"><div class="bcSk bcSk--line" style="height:16px"></div></td></tr>
                        </tbody>
                    </table>
                </div>

                <div v-else-if="historyEntries.length === 0" class="bcEmpty bcEmpty--sm">
                    <p class="bcEmpty__title">No credit activity yet</p>
                    <p class="bcEmpty__sub">Purchases, top-ups and report usage will appear here.</p>
                </div>

                <template v-else>
                    <div class="bcHistTableWrap">
                        <table class="bcHistTable">
                            <thead>
                                <tr><th>Date</th><th>Description</th><th>Type</th><th class="bcCol--right">Credits</th><th class="bcCol--right">Balance</th></tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="e in historyEntries"
                                    :key="e.key"
                                    :class="{ 'bcHistRow--link': e.report_week }"
                                    @click="openHistoryReport(e)"
                                >
                                    <td class="bcMono">{{ formatDate(e.date) }}</td>
                                    <td>{{ e.label }}<svg v-if="e.report_week" viewBox="0 0 24 24" fill="none" class="bcHistRow__arrow"><path d="m9 6 6 6-6 6" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/></svg></td>
                                    <td><span class="bcBadge" :class="historyTypeClass(e)">{{ historyTypeLabel(e) }}</span></td>
                                    <td class="bcMono bcCol--right" :class="e.credits > 0 ? 'bcCredits' : 'bcCreditsNeg'">{{ formatCredits(e.credits) }}</td>
                                    <td class="bcMono bcCol--right">{{ e.balance_after !== null ? formatCredits(e.balance_after).replace('+', '') : '—' }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Mobile card list -->
                    <div class="bcHistCards">
                        <div
                            v-for="e in historyEntries"
                            :key="e.key"
                            class="bcHistCard"
                            :class="{ 'bcHistRow--link': e.report_week }"
                            @click="openHistoryReport(e)"
                        >
                            <div class="bcHistCard__top">
                                <span class="bcHistCard__pack">{{ e.label }}</span>
                                <span class="bcBadge" :class="historyTypeClass(e)">{{ historyTypeLabel(e) }}</span>
                            </div>
                            <div class="bcHistCard__meta">
                                <span class="bcMono">{{ formatDate(e.date) }}</span>
                                <span class="bcHistCard__dot"></span>
                                <span class="bcMono" :class="e.credits > 0 ? 'bcCredits' : 'bcCreditsNeg'">{{ formatCredits(e.credits) }} credits</span>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </div>
</template>

<style scoped>
/* ── Layout ──────────────────────────────────────────────────────────────── */
.bcPage { display: flex; flex-direction: column; gap: 20px; }

/* ── Breadcrumb ──────────────────────────────────────────────────────────── */

/* ── Header ──────────────────────────────────────────────────────────────── */
.bcHead { display: flex; align-items: flex-start; justify-content: space-between; gap: 28px; flex-wrap: wrap; }
.bcHead__copy { flex: 1 1 380px; min-width: 0; }
.bcTitle {
    margin: 0;
    font-family: var(--font-sans);
    font-size: 2.6rem;
    font-weight: 700;
    letter-spacing: var(--tracking-tight);
    color: var(--color-text);
    line-height: 1.1;
}
.bcSub { margin: 10px 0 0; color: var(--color-muted); font-size: 0.92rem; line-height: 1.55; max-width: 46em; }

.bcInfoCard {
    flex: 0 0 300px;
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: 14px;
    padding: 16px 18px;
}
.bcInfoCard__title { margin: 0 0 10px; font-size: 0.68rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: var(--color-muted); }
.bcInfoCard__list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 9px; }
.bcInfoCard__list li { display: flex; align-items: flex-start; gap: 9px; font-size: 0.84rem; line-height: 1.4; }
.bcInfoCard__list svg { width: 15px; height: 15px; flex-shrink: 0; margin-top: 1px; color: var(--color-success); }

/* ── Alerts ──────────────────────────────────────────────────────────────── */
.bcAlert {
    display: flex; align-items: center; gap: 10px;
    padding: 11px 14px; border-radius: 10px; font-size: 0.85rem; line-height: 1.4;
}
.bcAlert__icon { width: 17px; height: 17px; flex-shrink: 0; }
.bcAlert--error   { background: var(--color-error-soft);   border: 1px solid var(--color-error-soft-border);   color: var(--color-error); }
.bcAlert--warn    { background: var(--color-warning-soft); border: 1px solid var(--color-warning-soft-border); color: var(--color-warning); }
.bcAlert--success { background: var(--color-success-soft); border: 1px solid var(--color-success-soft-border); color: var(--color-success); }

/* ── Plan grid / cards ───────────────────────────────────────────────────── */
.bcGrid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 18px; align-items: stretch; }

.bcCard {
    position: relative;
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: 16px;
    padding: 24px;
    display: flex;
    flex-direction: column;
    box-shadow: var(--shadow-xs);
    transition: border-color 0.15s, box-shadow 0.15s;
}
.bcCard--featured {
    border: 2px solid var(--color-primary);
    box-shadow: 0 0 0 1px var(--color-primary), var(--shadow-md);
    padding: 23px;
}
.bcCard__badge {
    position: absolute; top: -13px; left: 50%; transform: translateX(-50%);
    background: var(--color-primary); color: #fff; font-size: 10.5px; font-weight: 700;
    letter-spacing: 0.05em; text-transform: uppercase; padding: 5px 14px; border-radius: 999px;
    white-space: nowrap; box-shadow: var(--shadow-sm);
}

.bcCard__name { margin: 0 0 10px; font-size: 0.95rem; font-weight: 700; color: var(--color-text); }

.bcCard__price { display: flex; align-items: baseline; gap: 9px; flex-wrap: wrap; }
.bcCard__priceNow { font-size: 2.1rem; font-weight: 700; color: var(--color-text); line-height: 1; letter-spacing: -0.02em; }
.bcCard__priceOld { font-size: 1.05rem; font-weight: 500; color: var(--color-muted); text-decoration: line-through; }
.bcCard__perCredit { margin: 6px 0 0; font-size: 0.8rem; color: var(--color-muted); }

.bcCard__divider { height: 1px; background: var(--color-border); margin: 16px 0; }

.bcCard__credits { margin: 0; font-size: 0.95rem; color: var(--color-text); }
.bcCard__credits strong { font-size: 1.15rem; font-weight: 700; }
.bcCard__desc { margin: 8px 0 0; font-size: 0.83rem; color: var(--color-muted); line-height: 1.5; flex: 1; }

.bcCard__btn {
    margin-top: 20px;
    height: 44px;
    border-radius: 10px;
    border: 1.5px solid var(--color-border-strong);
    background: transparent;
    color: var(--color-text);
    font-size: 0.88rem;
    font-weight: 600;
    cursor: pointer;
    display: flex; align-items: center; justify-content: center; gap: 8px;
    transition: background 0.15s, border-color 0.15s, opacity 0.15s;
}
.bcCard__btn:hover:not(:disabled) { background: var(--color-surface-2); border-color: var(--color-muted); }
.bcCard__btn:disabled { opacity: 0.55; cursor: not-allowed; }
.bcCard__btn--primary {
    background: var(--color-primary); border-color: var(--color-primary); color: #fff;
}
.bcCard__btn--primary:hover:not(:disabled) { background: var(--color-primary-hover); border-color: var(--color-primary-hover); }
.bcCard__btnIcon { width: 16px; height: 16px; flex-shrink: 0; }

.bcSpinner {
    width: 15px; height: 15px; border: 2px solid color-mix(in srgb, currentColor 30%, transparent);
    border-top-color: currentColor; border-radius: 50%; animation: bcSpin 0.7s linear infinite;
}
@keyframes bcSpin { to { transform: rotate(360deg); } }

/* Skeleton */
.bcCard--sk { pointer-events: none; gap: 14px; }
.bcSk {
    border-radius: 8px;
    background-image: linear-gradient(90deg, color-mix(in srgb, var(--color-text) 5%, transparent) 0%, color-mix(in srgb, var(--color-text) 10%, transparent) 50%, color-mix(in srgb, var(--color-text) 5%, transparent) 100%);
    background-size: 300% 100%;
    animation: bcShimmer 1.5s ease-in-out infinite;
}
@keyframes bcShimmer { 0% { background-position: 100% 0; } 100% { background-position: -100% 0; } }
.bcSk--name  { height: 16px; width: 40%; }
.bcSk--price { height: 40px; width: 55%; }
.bcSk--line  { height: 14px; width: 75%; }
.bcSk--pill  { height: 26px; width: 60%; margin-top: 10px; }
.bcSk--btn   { height: 44px; width: 100%; margin-top: 8px; border-radius: 10px; }

/* Empty */
.bcEmpty { grid-column: 1 / -1; text-align: center; padding: 48px 20px; border: 1px dashed var(--color-border-strong); border-radius: 16px; }
.bcEmpty--sm { padding: 32px 16px; }
.bcEmpty__title { margin: 0; font-size: 1rem; font-weight: 700; }
.bcEmpty__sub { margin: 4px 0 0; font-size: 0.85rem; color: var(--color-muted); }

/* ── Lower section (auto top-up + history) ──────────────────────────────── */
.bcLower { display: grid; grid-template-columns: 1fr 1.3fr; gap: 18px; align-items: start; }

.bcPanel {
    background: var(--color-surface); border: 1px solid var(--color-border);
    border-radius: 16px; padding: 22px;
}
.bcPanel__head { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; margin-bottom: 16px; }
.bcPanel__title { margin: 0; font-size: 1.1rem; font-weight: 700; color: var(--color-text); }
.bcPanel__sub { margin: 4px 0 0; font-size: 0.83rem; color: var(--color-muted); line-height: 1.5; max-width: 32em; }
.bcPanel--history .bcPanel__sub { margin-bottom: 14px; }

/* Toggle */
.bcToggle { position: relative; flex-shrink: 0; cursor: pointer; }
.bcToggle input { position: absolute; opacity: 0; width: 0; height: 0; }
.bcToggle__track {
    display: block; width: 42px; height: 24px; border-radius: 999px;
    background: var(--color-surface-2); border: 1px solid var(--color-border-strong);
    transition: background 0.15s, border-color 0.15s;
}
.bcToggle__thumb {
    position: absolute; top: 2px; left: 2px; width: 18px; height: 18px; border-radius: 50%;
    background: #fff; box-shadow: var(--shadow-xs); transition: transform 0.15s;
}
.bcToggle--on .bcToggle__track { background: var(--color-primary); border-color: var(--color-primary); }
.bcToggle--on .bcToggle__thumb { transform: translateX(18px); }

.bcFieldRow { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 12px; }
.bcField { display: flex; flex-direction: column; gap: 6px; font-size: 0.8rem; color: var(--color-muted); font-weight: 600; }
.bcField__inputWrap { position: relative; display: flex; align-items: center; }
.bcField__inputWrap input {
    width: 100%; height: 40px; padding: 0 62px 0 12px; box-sizing: border-box;
    border: 1px solid var(--color-border); border-radius: 9px;
    background: var(--color-surface-2); color: var(--color-text);
    font-family: var(--font-mono); font-size: 0.9rem;
}
.bcField__inputWrap input:focus {
    outline: none; border-color: color-mix(in srgb, var(--color-primary) 60%, var(--color-border));
    background: var(--color-surface); box-shadow: 0 0 0 3px var(--ring);
}
.bcField__inputWrap input::-webkit-inner-spin-button,
.bcField__inputWrap input::-webkit-outer-spin-button { -webkit-appearance: none; margin: 0; }
.bcField__unit { position: absolute; right: 12px; font-size: 0.72rem; color: var(--color-muted); pointer-events: none; font-weight: 500; }

.bcEstimate { margin: 0 0 14px; font-size: 0.82rem; color: var(--color-muted); }
.bcEstimate strong { color: var(--color-text); font-weight: 700; }

.bcPanel__actions { display: flex; gap: 10px; flex-wrap: wrap; }

.bcBtn {
    height: 38px; padding: 0 16px; border-radius: 9px; border: none;
    background: var(--color-primary); color: #fff; font-size: 0.83rem; font-weight: 600;
    cursor: pointer; transition: opacity 0.15s;
}
.bcBtn:hover:not(:disabled) { opacity: 0.9; }
.bcBtn:disabled { opacity: 0.5; cursor: not-allowed; }
.bcBtn--outline { background: transparent; border: 1px solid var(--color-border-strong); color: var(--color-text); }
.bcBtn--outline:hover:not(:disabled) { background: var(--color-surface-2); opacity: 1; }

.bcCardSetup { margin-top: 14px; display: flex; flex-direction: column; gap: 10px; }
.bcCardElement { padding: 12px; border: 1px solid var(--color-border); border-radius: 9px; background: #fff; }

/* ── Purchase history table ──────────────────────────────────────────────── */
.bcHistTableWrap { overflow-x: auto; }
.bcHistTable { width: 100%; border-collapse: collapse; font-size: 0.84rem; }
.bcHistTable th {
    text-align: left; padding: 9px 10px; font-size: 0.66rem; text-transform: uppercase;
    letter-spacing: 0.05em; color: var(--color-muted); font-weight: 700;
    border-bottom: 1px solid var(--color-border); white-space: nowrap;
}
.bcHistTable th.bcCol--right { text-align: right; }
.bcHistTable td { padding: 11px 10px; border-bottom: 1px solid var(--color-border); }
.bcHistTable tbody tr:last-child td { border-bottom: none; }
.bcHistTable tbody tr:hover td { background: var(--color-surface-2); }
.bcHistRow--link { cursor: pointer; }
.bcHistRow__arrow { width: 12px; height: 12px; margin-left: 4px; vertical-align: middle; color: var(--color-muted); display: inline-block; }
.bcCol--right { text-align: right; }
.bcMono { font-family: var(--font-mono); font-size: 0.82rem; }
.bcCredits { color: var(--color-success); font-weight: 600; }
.bcCreditsNeg { color: var(--color-error); font-weight: 600; }

.bcBadge {
    display: inline-flex; align-items: center; padding: 3px 10px; border-radius: 999px;
    font-size: 0.7rem; font-weight: 700; white-space: nowrap;
}
.bcBadge.badge--active     { background: var(--color-primary-soft); color: var(--color-primary); }
.bcBadge.badge--failed     { background: var(--color-error-soft); color: var(--color-error); }
.bcBadge.badge--processing { background: var(--color-warning-soft); color: var(--color-warning); }

/* Mobile history cards */
.bcHistCards { display: none; }
.bcHistCard { padding: 12px 4px; border-bottom: 1px solid var(--color-border); display: flex; flex-direction: column; gap: 6px; }
.bcHistCard:last-child { border-bottom: none; }
.bcHistCard__top { display: flex; align-items: center; justify-content: space-between; gap: 10px; }
.bcHistCard__pack { font-weight: 600; font-size: 0.88rem; }
.bcHistCard__meta { display: flex; flex-wrap: wrap; align-items: center; gap: 7px; font-size: 0.78rem; color: var(--color-muted); }
.bcHistCard__dot { width: 3px; height: 3px; border-radius: 999px; background: var(--color-muted); flex-shrink: 0; }

/* ── Responsive ──────────────────────────────────────────────────────────── */
@media (max-width: 1180px) {
    .bcGrid { grid-template-columns: repeat(3, 1fr); gap: 14px; }
    .bcCard { padding: 20px; }
}

@media (max-width: 960px) {
    .bcLower { grid-template-columns: 1fr; }
}

@media (max-width: 860px) {
    .bcGrid { grid-template-columns: 1fr 1fr; }
    .bcInfoCard { flex: 1 1 260px; }
}

@media (max-width: 640px) {
    .bcTitle { font-size: 2.1rem; }
    .bcGrid { grid-template-columns: 1fr; }
    .bcCard--featured { order: -1; }
    .bcFieldRow { grid-template-columns: 1fr; }
    .bcPanel { padding: 18px; }
    .bcPanel__actions .bcBtn { flex: 1; }
}

@media (max-width: 560px) {
    .bcHistTableWrap { display: none; }
    .bcHistCards { display: block; }
}
</style>
