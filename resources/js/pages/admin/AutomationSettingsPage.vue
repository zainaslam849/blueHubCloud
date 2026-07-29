<template>
    <div class="admin-container admin-page auto-page">

        <header class="auto-header">
            <h1 class="auto-header__title">Automation</h1>
            <p class="auto-header__sub">
                Configure when the weekly AI pipeline runs automatically. On the chosen day &amp; time,
                every active company's previous week is fetched and processed up to its remaining call limit.
            </p>
        </header>

        <div class="auto-body">

            <!-- Enable toggle -->
            <section class="section">
                <div class="section__label">
                    <h2 class="section__title">Weekly Auto-Run</h2>
                    <p class="section__sub">Turn the scheduled weekly pipeline on or off.</p>
                </div>
                <div class="section__fields">
                    <label class="switch">
                        <input type="checkbox" v-model="form.weekly_run_enabled" />
                        <span class="switch__track"><span class="switch__thumb"></span></span>
                        <span class="switch__label">{{ form.weekly_run_enabled ? 'Enabled' : 'Disabled' }}</span>
                    </label>
                </div>
            </section>

            <div class="divider"></div>

            <!-- Schedule -->
            <section class="section" :class="{ 'is-dim': !form.weekly_run_enabled }">
                <div class="section__label">
                    <h2 class="section__title">Schedule</h2>
                    <p class="section__sub">The run fires once per week at this day, time and timezone.</p>
                </div>
                <div class="section__fields">
                    <div class="field">
                        <label class="label">Day of week</label>
                        <select v-model.number="form.weekly_run_day" class="input" :disabled="!form.weekly_run_enabled">
                            <option v-for="d in days" :key="d.value" :value="d.value">{{ d.label }}</option>
                        </select>
                    </div>
                    <div class="field">
                        <label class="label">Time (24h)</label>
                        <input v-model="form.weekly_run_time" class="input" type="time" :disabled="!form.weekly_run_enabled" />
                        <p class="field__hint">Interpreted in the timezone selected below.</p>
                    </div>
                    <div class="field">
                        <label class="label">Timezone</label>
                        <select v-model="form.weekly_run_timezone" class="input" :disabled="!form.weekly_run_enabled">
                            <option v-for="tz in timezones" :key="tz" :value="tz">{{ tz }}</option>
                        </select>
                    </div>

                    <div class="preview">
                        <svg viewBox="0 0 20 20" fill="none"><circle cx="10" cy="10" r="8" stroke="currentColor" stroke-width="1.5"/><path d="M10 6v4l2.5 2.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
                        <span v-if="form.weekly_run_enabled">
                            Next runs <strong>every {{ dayLabel }}</strong> at <strong>{{ form.weekly_run_time }}</strong> ({{ form.weekly_run_timezone }}).
                        </span>
                        <span v-else>Weekly auto-run is currently disabled.</span>
                    </div>
                </div>
            </section>

            <div class="actions">
                <BaseButton variant="primary" :loading="saving" :disabled="saving" @click="save">
                    <template v-if="saving">Saving…</template>
                    <template v-else>Save settings</template>
                </BaseButton>
                <Transition name="fade">
                    <span v-if="success" class="status status--ok">
                        <svg viewBox="0 0 24 24" fill="none"><path d="M20 6L9 17l-5-5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        Saved
                    </span>
                    <span v-else-if="error" class="status status--err">{{ error }}</span>
                </Transition>
            </div>

        </div>
    </div>
</template>

<script setup>
import { computed, onMounted, ref } from "vue";
import { BaseButton } from "../../components/admin/base";
import adminApi from "../../router/admin/api";

const days = [
    { value: 0, label: "Sunday" },
    { value: 1, label: "Monday" },
    { value: 2, label: "Tuesday" },
    { value: 3, label: "Wednesday" },
    { value: 4, label: "Thursday" },
    { value: 5, label: "Friday" },
    { value: 6, label: "Saturday" },
];

function buildTimezones() {
    try {
        if (typeof Intl.supportedValuesOf === "function") {
            return Intl.supportedValuesOf("timeZone");
        }
    } catch {
        // fall through
    }
    return [
        "UTC", "America/New_York", "America/Chicago", "America/Denver", "America/Los_Angeles",
        "Europe/London", "Europe/Paris", "Europe/Berlin", "Asia/Dubai", "Asia/Karachi",
        "Asia/Kolkata", "Asia/Singapore", "Asia/Tokyo", "Australia/Sydney",
    ];
}
const timezones = buildTimezones();

const form = ref({
    weekly_run_enabled: true,
    weekly_run_day: 1,
    weekly_run_time: "02:00",
    weekly_run_timezone: "UTC",
});

const saving  = ref(false);
const error   = ref("");
const success = ref(false);

const dayLabel = computed(() => days.find(d => d.value === form.value.weekly_run_day)?.label ?? "Monday");

async function load() {
    try {
        const res = await adminApi.get("/settings/automation");
        const data = res?.data?.data || {};
        form.value = {
            weekly_run_enabled: data.weekly_run_enabled ?? true,
            weekly_run_day: data.weekly_run_day ?? 1,
            weekly_run_time: data.weekly_run_time ?? "02:00",
            weekly_run_timezone: data.weekly_run_timezone ?? "UTC",
        };
    } catch {
        // ignore
    }
}

async function save() {
    if (saving.value) return;
    saving.value = true;
    error.value = "";
    success.value = false;
    try {
        await adminApi.post("/settings/automation", form.value);
        success.value = true;
        setTimeout(() => { success.value = false; }, 2500);
    } catch (e) {
        error.value = e?.response?.data?.message || "Failed to save automation settings.";
    } finally {
        saving.value = false;
    }
}

onMounted(load);
</script>

<style scoped>
.auto-page { max-width: 880px; }
.auto-header { margin-bottom: 24px; }
.auto-header__title { font-size: 1.5rem; font-weight: 800; margin: 0 0 6px; color: var(--text-primary); }
.auto-header__sub { color: var(--text-secondary); font-size: 0.9rem; line-height: 1.5; margin: 0; }

.auto-body {
    background: var(--bg-surface, #fff);
    border: 1px solid var(--border-soft, #e5e7eb);
    border-radius: 16px;
    padding: 24px 28px;
}

.section { display: grid; grid-template-columns: 260px 1fr; gap: 24px; }
.section.is-dim { opacity: .55; }
.section__title { font-size: 1rem; font-weight: 700; margin: 0 0 4px; color: var(--text-primary); }
.section__sub { font-size: 0.82rem; color: var(--text-secondary); margin: 0; line-height: 1.5; }
.section__fields { display: flex; flex-direction: column; gap: 16px; }

.divider { height: 1px; background: var(--border-soft, #e5e7eb); margin: 24px 0; }

.field { display: flex; flex-direction: column; gap: 6px; max-width: 340px; }
.label { font-size: 0.82rem; font-weight: 700; color: var(--text-primary); }
.field__hint { font-size: 0.75rem; color: var(--text-secondary); margin: 0; }

.input {
    height: 42px; padding: 0 12px;
    background: var(--input-bg, #fff); border: 1px solid var(--border, #d1d5db);
    border-radius: 8px; font-size: 0.9rem; color: var(--text-primary);
    outline: none; transition: border-color .15s;
}
.input:focus { border-color: var(--accent, #3b82f6); }
.input:disabled { opacity: .6; cursor: not-allowed; }

/* Switch */
.switch { display: inline-flex; align-items: center; gap: 10px; cursor: pointer; }
.switch input { position: absolute; opacity: 0; width: 0; height: 0; }
.switch__track {
    width: 44px; height: 24px; border-radius: 999px;
    background: var(--border, #cbd5e1); position: relative; transition: background .2s;
}
.switch__thumb {
    position: absolute; top: 2px; left: 2px;
    width: 20px; height: 20px; border-radius: 50%; background: #fff;
    transition: transform .2s; box-shadow: 0 1px 3px rgba(0,0,0,.2);
}
.switch input:checked + .switch__track { background: var(--accent, #3b82f6); }
.switch input:checked + .switch__track .switch__thumb { transform: translateX(20px); }
.switch__label { font-size: 0.88rem; font-weight: 600; color: var(--text-primary); }

.preview {
    display: flex; align-items: center; gap: 8px;
    background: color-mix(in srgb, var(--accent, #3b82f6) 7%, transparent);
    border: 1px solid color-mix(in srgb, var(--accent, #3b82f6) 18%, transparent);
    border-radius: 10px; padding: 11px 14px; font-size: 0.85rem; color: var(--text-secondary);
}
.preview svg { width: 16px; height: 16px; flex-shrink: 0; color: var(--accent, #3b82f6); }

.actions { display: flex; align-items: center; gap: 14px; margin-top: 24px; }
.status { display: inline-flex; align-items: center; gap: 6px; font-size: 0.85rem; font-weight: 600; }
.status--ok { color: #059669; }
.status--ok svg { width: 16px; height: 16px; }
.status--err { color: #dc2626; }
.fade-enter-active, .fade-leave-active { transition: opacity .2s; }
.fade-enter-from, .fade-leave-to { opacity: 0; }

@media (max-width: 720px) {
    .section { grid-template-columns: 1fr; gap: 12px; }
}
</style>
