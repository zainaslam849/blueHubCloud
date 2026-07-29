<template>
    <div class="admin-container admin-page">
        <header class="admin-page__header">
            <div>
                <p class="admin-page__kicker">Settings</p>
                <h1 class="admin-page__title">Credit Settings</h1>
                <p class="admin-page__subtitle">
                    Set the price of one credit and how many credits one minute
                    of call time consumes.
                </p>
            </div>
        </header>

        <section class="admin-card admin-card--glass" style="max-width: 640px">
            <div v-if="error" class="admin-alert admin-alert--error">
                {{ error }}
            </div>
            <div v-if="notice" class="admin-alert admin-alert--success">
                {{ notice }}
            </div>

            <div v-if="loading" class="admin-loadingState">
                <p>Loading credit settings...</p>
            </div>

            <template v-else>
                <div class="admin-field">
                    <label for="credit-price" class="admin-field__label">
                        Credit Price (USD) *
                    </label>
                    <input
                        id="credit-price"
                        v-model.number="form.credit_price_usd"
                        type="number"
                        step="0.01"
                        min="0.01"
                        class="admin-input"
                    />
                    <p class="admin-field__hint">
                        What one credit costs when purchased. Default: 1 credit
                        = $1.00.
                    </p>
                </div>

                <div class="admin-field">
                    <label for="credits-per-minute" class="admin-field__label">
                        Credits per Minute of Call *
                    </label>
                    <input
                        id="credits-per-minute"
                        v-model.number="form.credits_per_minute"
                        type="number"
                        step="0.0001"
                        min="0.0001"
                        class="admin-input"
                    />
                    <p class="admin-field__hint">
                        Credits deducted for each minute of call time, prorated
                        by exact seconds (e.g. a 90-second call at 1
                        credit/minute costs 1.5 credits).
                    </p>
                </div>

                <div style="margin-top: 1rem">
                    <BaseButton
                        variant="primary"
                        size="md"
                        :loading="saving"
                        @click="save"
                    >
                        Save Settings
                    </BaseButton>
                </div>
            </template>
        </section>
    </div>
</template>

<script setup>
import { onMounted, ref } from "vue";
import adminApi from "../../router/admin/api";
import { BaseButton } from "../../components/admin/base";

const loading = ref(true);
const saving = ref(false);
const error = ref("");
const notice = ref("");

const form = ref({
    credit_price_usd: 1.0,
    credits_per_minute: 1.0,
});

async function fetchSettings() {
    loading.value = true;
    error.value = "";

    try {
        const res = await adminApi.get("/settings/credits");
        const data = res?.data?.data || {};
        form.value.credit_price_usd = data.credit_price_usd ?? 1.0;
        form.value.credits_per_minute = data.credits_per_minute ?? 1.0;
    } catch (e) {
        error.value = "Failed to load credit settings.";
    } finally {
        loading.value = false;
    }
}

async function save() {
    saving.value = true;
    error.value = "";
    notice.value = "";

    try {
        const res = await adminApi.post("/settings/credits", form.value);
        notice.value = res?.data?.message || "Saved.";
    } catch (e) {
        error.value =
            e?.response?.data?.message || "Failed to save credit settings.";
    } finally {
        saving.value = false;
    }
}

onMounted(fetchSettings);
</script>
