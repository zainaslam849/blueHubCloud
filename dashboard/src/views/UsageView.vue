<script setup lang="ts">
import { onMounted, ref } from "vue";
import Card from "../components/ui/Card.vue";
import PageHeader from "../components/ui/PageHeader.vue";
import { userApi } from "../api/user";

type PlanData = {
    credits: number;
    credit_price_usd: number;
    credits_per_minute: number;
    minutes_available: number | null;
    auto_topup: {
        enabled: boolean;
        threshold: number | null;
        credits: number | null;
        has_payment_method: boolean;
        paused_at: string | null;
        failure_count: number;
    };
};

const loading = ref(true);
const planData = ref<PlanData | null>(null);
const error = ref<string | null>(null);

async function load() {
    loading.value = true;
    error.value = null;
    try {
        const res = await userApi.get<PlanData>("/plan");
        planData.value = res.data;
    } catch (e: unknown) {
        const status = (e as { response?: { status?: number } })?.response?.status;
        if (status === 404) {
            planData.value = null;
            error.value = null;
        } else {
            error.value = e instanceof Error ? e.message : "Failed to load usage.";
        }
    } finally {
        loading.value = false;
    }
}

onMounted(load);
</script>

<template>
    <div>
        <PageHeader
            title="Usage"
            description="Your credit balance and usage summary."
        />

        <div v-if="loading" class="skeleton">
            <div class="sk-card"></div>
            <div class="sk-card"></div>
        </div>

        <div v-else-if="error" class="errorBanner">{{ error }}</div>

        <div v-else-if="!planData" class="infoBanner">
            No billing information available yet. Contact your administrator.
        </div>

        <section v-else class="grid">
            <Card title="Credit Balance">
                <div class="kv">
                    <div class="k">Available credits</div>
                    <div class="v" :class="planData.credits < 5 ? 'warn' : 'ok'">
                        {{ planData.credits.toLocaleString() }}
                    </div>

                    <div class="k">Approx. call minutes covered</div>
                    <div class="v">
                        {{ planData.minutes_available !== null ? planData.minutes_available.toLocaleString() + ' min' : '—' }}
                    </div>

                    <div class="k">Credit price</div>
                    <div class="v">${{ planData.credit_price_usd.toFixed(2) }} / credit</div>

                    <div class="k">Rate</div>
                    <div class="v">{{ planData.credits_per_minute }} credits / minute of call</div>
                </div>

                <div class="divider"></div>

                <div class="kv">
                    <div class="k">Auto top-up</div>
                    <div class="v">
                        <template v-if="planData.auto_topup.paused_at">Paused (payment failures)</template>
                        <template v-else-if="planData.auto_topup.enabled">
                            On — below {{ planData.auto_topup.threshold }} credits, buy {{ planData.auto_topup.credits }}
                        </template>
                        <template v-else>Off</template>
                    </div>
                </div>
            </Card>

            <Card title="Need more credits?" subtitle="Buy credits or enable auto top-up">
                <p class="muted">
                    When your credits run out, new weekly reports pause until
                    credits are purchased. You can buy credit bundles from the
                    Plans page, or enable auto top-up on the Billing page so the
                    system purchases credits automatically when your balance
                    drops below your chosen threshold.
                </p>
            </Card>
        </section>
    </div>
</template>

<style scoped>
.grid {
    display: grid;
    grid-template-columns: 1.2fr 1fr;
    gap: var(--space-4);
}

.kv {
    display: grid;
    grid-template-columns: 220px 1fr;
    gap: 10px;
}

.k { opacity: 0.75; }
.v { font-weight: 750; }
.ok { color: #1f9d55; }
.warn { color: #b7791f; }

.divider {
    height: 1px;
    background: var(--border);
    margin: var(--space-4) 0;
}

.progressWrap {
    margin-top: var(--space-4);
    display: grid;
    gap: 6px;
}

.progressBar {
    height: 8px;
    border-radius: 999px;
    background: color-mix(in srgb, var(--color-text) 10%, transparent);
    overflow: hidden;
}

.progressFill {
    height: 100%;
    border-radius: 999px;
    transition: width 0.4s ease;
}

.fill--ok { background: #1f9d55; }
.fill--warn { background: #b7791f; }

.progressLabel {
    font-size: 0.82rem;
    opacity: 0.7;
}

.muted {
    margin: 0;
    opacity: 0.75;
    line-height: 1.6;
}

.skeleton {
    display: grid;
    grid-template-columns: 1.2fr 1fr;
    gap: var(--space-4);
}

.sk-card {
    height: 220px;
    border-radius: 12px;
    background: color-mix(in srgb, var(--color-text) 8%, transparent);
}

.errorBanner, .infoBanner {
    border-radius: 10px;
    padding: var(--space-4);
    margin-bottom: var(--space-4);
}

.errorBanner {
    background: color-mix(in srgb, #e53e3e 12%, transparent);
    border: 1px solid #e53e3e;
    color: #e53e3e;
}

.infoBanner {
    background: color-mix(in srgb, var(--color-primary) 10%, transparent);
    border: 1px solid var(--border);
}

@media (max-width: 960px) {
    .grid, .skeleton { grid-template-columns: 1fr; }
    .kv { grid-template-columns: 1fr; }
}
</style>
