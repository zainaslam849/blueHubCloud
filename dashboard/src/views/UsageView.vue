<script setup lang="ts">
import { onMounted, ref } from "vue";
import Card from "../components/ui/Card.vue";
import PageHeader from "../components/ui/PageHeader.vue";
import { userApi } from "../api/user";

type PlanData = {
    plan: { id: number; name: string; minute_limit: number; price: string } | null;
    purchased_minutes: number;
    used_minutes: number;
    available_minutes: number;
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

function pct(used: number, purchased: number): number {
    if (purchased === 0) return 0;
    return Math.min(100, Math.round((used / purchased) * 100));
}
</script>

<template>
    <div>
        <PageHeader
            title="Usage"
            description="Your minute plan and usage summary."
        />

        <div v-if="loading" class="skeleton">
            <div class="sk-card"></div>
            <div class="sk-card"></div>
        </div>

        <div v-else-if="error" class="errorBanner">{{ error }}</div>

        <div v-else-if="!planData" class="infoBanner">
            No plan assigned yet. Contact your administrator to be assigned a plan.
        </div>

        <section v-else class="grid">
            <Card :title="planData.plan ? `Plan: ${planData.plan.name}` : 'Minute Balance'">
                <div class="kv">
                    <div class="k">Plan</div>
                    <div class="v">{{ planData.plan?.name ?? '—' }}</div>

                    <div class="k">Minute limit per top-up</div>
                    <div class="v">{{ planData.plan ? planData.plan.minute_limit.toLocaleString() + ' min' : '—' }}</div>

                    <div class="k">Price per top-up</div>
                    <div class="v">{{ planData.plan ? '$' + planData.plan.price : '—' }}</div>
                </div>

                <div class="divider"></div>

                <div class="kv">
                    <div class="k">Purchased minutes</div>
                    <div class="v">{{ planData.purchased_minutes.toLocaleString() }}</div>

                    <div class="k">Used minutes</div>
                    <div class="v">{{ planData.used_minutes.toLocaleString() }}</div>

                    <div class="k">Available minutes</div>
                    <div class="v" :class="planData.available_minutes < 60 ? 'warn' : 'ok'">
                        {{ planData.available_minutes.toLocaleString() }}
                    </div>
                </div>

                <div class="progressWrap">
                    <div class="progressBar">
                        <div
                            class="progressFill"
                            :style="{ width: pct(planData.used_minutes, planData.purchased_minutes) + '%' }"
                            :class="pct(planData.used_minutes, planData.purchased_minutes) > 85 ? 'fill--warn' : 'fill--ok'"
                        ></div>
                    </div>
                    <div class="progressLabel">
                        {{ pct(planData.used_minutes, planData.purchased_minutes) }}% used
                    </div>
                </div>
            </Card>

            <Card title="Need more minutes?" subtitle="Contact your administrator">
                <p class="muted">
                    When your available minutes run out, new weekly reports will be
                    paused until more minutes are purchased. Ask your administrator
                    to top up your plan.
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
