<script setup lang="ts">
import { ref, onMounted } from "vue";
import { useRoute, useRouter } from "vue-router";
import { userApi } from "../api/user";

type VerifyResult = {
    status: string;
    plan_name: string;
    credits_added: number;
    minutes_added: number;
    amount_paid: string;
};

const route  = useRoute();
const router = useRouter();

const loading = ref(true);
const result  = ref<VerifyResult | null>(null);
const failed  = ref(false);

async function verify() {
    const sessionId = route.query.session_id as string;
    if (!sessionId) {
        failed.value  = true;
        loading.value = false;
        return;
    }

    // Poll up to 5 times (webhook may not have fired yet)
    for (let i = 0; i < 5; i++) {
        try {
            const res = await userApi.get<VerifyResult>(`/stripe/verify/${sessionId}`);
            if (res.data.status === "completed") {
                result.value  = res.data;
                loading.value = false;
                return;
            }
        } catch {
            // ignore and retry
        }
        if (i < 4) await new Promise(r => setTimeout(r, 2000));
    }

    // Still not completed — show a pending message
    result.value  = { status: "pending", plan_name: "", credits_added: 0, minutes_added: 0, amount_paid: "0" };
    loading.value = false;
}

onMounted(verify);
</script>

<template>
    <div class="psPage">
        <!-- Loading -->
        <div v-if="loading" class="psCard">
            <div class="psSpinner"></div>
            <p class="psTitle">Confirming your payment…</p>
            <p class="psSub">Please wait while we verify your purchase with Stripe.</p>
        </div>

        <!-- Failed / no session -->
        <div v-else-if="failed" class="psCard psCard--error">
            <div class="psIcon psIcon--error">
                <svg viewBox="0 0 24 24" fill="none">
                    <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/>
                    <path d="M15 9l-6 6M9 9l6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                </svg>
            </div>
            <p class="psTitle">Something went wrong</p>
            <p class="psSub">We could not find a valid payment session. Please contact support if you were charged.</p>
            <div class="psActions">
                <button class="psBtn psBtn--outline" @click="router.replace('/billing')">View Billing</button>
                <button class="psBtn psBtn--primary" @click="router.replace('/select-plan')">Browse Plans</button>
            </div>
        </div>

        <!-- Pending (webhook slow) -->
        <div v-else-if="result?.status === 'pending'" class="psCard psCard--pending">
            <div class="psIcon psIcon--pending">
                <svg viewBox="0 0 24 24" fill="none">
                    <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/>
                    <path d="M12 7v5l3 3" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                </svg>
            </div>
            <p class="psTitle">Payment received — processing…</p>
            <p class="psSub">Your payment was successful. Credits will be added to your account within a few minutes. Check your billing history for status updates.</p>
            <div class="psActions">
                <button class="psBtn psBtn--primary" @click="router.replace('/billing')">View Billing History</button>
            </div>
        </div>

        <!-- Success -->
        <div v-else class="psCard psCard--success">
            <div class="psIcon psIcon--success">
                <svg viewBox="0 0 24 24" fill="none">
                    <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/>
                    <path d="M7.5 12l3 3 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </div>
            <p class="psTitle">Payment successful!</p>
            <p class="psSub">Your credits have been added to your account.</p>

            <div class="psDetails">
                <div class="psDetail">
                    <span class="psDetail__label">Plan</span>
                    <span class="psDetail__value">{{ result?.plan_name }}</span>
                </div>
                <div class="psDetail">
                    <span class="psDetail__label">Credits Added</span>
                    <span class="psDetail__value">{{ Number(result?.credits_added ?? 0).toLocaleString() }}</span>
                </div>
                <div class="psDetail">
                    <span class="psDetail__label">Amount Paid</span>
                    <span class="psDetail__value">${{ result?.amount_paid }}</span>
                </div>
            </div>

            <div class="psActions">
                <button class="psBtn psBtn--outline" @click="router.replace('/billing')">View Billing History</button>
                <button class="psBtn psBtn--primary" @click="router.replace('/dashboard')">Go to Dashboard</button>
            </div>
        </div>
    </div>
</template>

<style scoped>
.psPage {
    min-height: 80vh;
    display: flex; align-items: center; justify-content: center;
    padding: var(--space-8);
}

.psCard {
    width: 100%; max-width: 480px;
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-xl);
    padding: var(--space-10) var(--space-8);
    display: flex; flex-direction: column; align-items: center;
    gap: var(--space-5); text-align: center;
    box-shadow: var(--shadow-lg);
}
.psCard--success { border-color: var(--color-success); }
.psCard--error   { border-color: var(--color-error); }
.psCard--pending { border-color: var(--color-warning); }

.psSpinner {
    width: 52px; height: 52px;
    border: 4px solid color-mix(in srgb, var(--color-primary) 20%, transparent);
    border-top-color: var(--color-primary);
    border-radius: 50%;
    animation: psSpin 0.9s linear infinite;
}
@keyframes psSpin { to { transform: rotate(360deg); } }

.psIcon { width: 64px; height: 64px; }
.psIcon svg { width: 100%; height: 100%; }
.psIcon--success { color: var(--color-success); }
.psIcon--error   { color: var(--color-error); }
.psIcon--pending { color: var(--color-warning); }

.psTitle {
    font-size: var(--text-2xl); font-weight: 800;
    color: var(--color-text); margin: 0;
}

.psSub {
    font-size: var(--text-sm); color: var(--color-muted);
    margin: 0; line-height: var(--leading-relaxed);
}

.psDetails {
    width: 100%;
    background: var(--color-surface-2);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    padding: var(--space-4) var(--space-5);
    display: flex; flex-direction: column; gap: var(--space-3);
}
.psDetail { display: flex; justify-content: space-between; font-size: var(--text-sm); }
.psDetail__label { color: var(--color-muted); }
.psDetail__value { font-weight: var(--weight-semibold); color: var(--color-text); }

.psActions { display: flex; gap: var(--space-3); flex-wrap: wrap; justify-content: center; }

.psBtn {
    display: inline-flex; align-items: center;
    height: 40px; padding: 0 var(--space-5);
    border-radius: var(--radius-md);
    font-size: var(--text-sm); font-weight: var(--weight-semibold);
    cursor: pointer; border: none; transition: opacity 150ms, transform 150ms;
}
.psBtn--primary {
    background: linear-gradient(135deg, var(--color-primary), #1d4ed8);
    color: #fff;
}
.psBtn--primary:hover { opacity: .9; transform: translateY(-1px); }
.psBtn--outline {
    background: transparent;
    border: 1.5px solid var(--color-border-strong);
    color: var(--color-muted);
}
.psBtn--outline:hover { background: var(--color-surface-2); color: var(--color-text); }
</style>
