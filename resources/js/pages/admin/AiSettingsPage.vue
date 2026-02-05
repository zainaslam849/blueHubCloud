<template>
    <div class="admin-container admin-page">
        <header class="admin-page__header">
            <div>
                <p class="admin-page__kicker">Settings</p>
                <h1 class="admin-page__title">AI Settings</h1>
                <p class="admin-page__subtitle">
                    Configure AI models used by the platform.
                </p>
            </div>
        </header>

        <section class="admin-card admin-card--glass">
            <h2 class="admin-card__headline">Call Categorization Model</h2>

            <div class="admin-form-row">
                <label class="admin-label">Model</label>
                <select v-model="categorizationModel" class="admin-input">
                    <option value="openai/gpt-4o-mini">
                        openai/gpt-4o-mini (Recommended)
                    </option>
                    <option value="openai/gpt-4.1-mini">
                        openai/gpt-4.1-mini (Cheapest)
                    </option>
                    <option value="google/gemini-1.5-flash">
                        google/gemini-1.5-flash (Fast)
                    </option>
                    <option value="google/gemini-1.5-pro">
                        google/gemini-1.5-pro (Premium)
                    </option>
                    <option value="anthropic/claude-3.5-sonnet">
                        anthropic/claude-3.5-sonnet (Premium)
                    </option>
                </select>

                <p class="admin-card__hint" style="margin-top: 8px">
                    Used to categorize individual calls. Runs once per call.
                    Accuracy here affects all reports.
                </p>
            </div>

            <h2 class="admin-card__headline" style="margin-top: 20px">
                Weekly Report Model
            </h2>

            <div class="admin-form-row">
                <label class="admin-label">Model</label>
                <select v-model="reportModel" class="admin-input">
                    <option
                        value="openai/gpt-4o-mini"
                        :disabled="categorizationModel === 'openai/gpt-4o-mini'"
                    >
                        openai/gpt-4o-mini
                    </option>
                    <option
                        value="openai/gpt-5.2"
                        :disabled="categorizationModel === 'openai/gpt-5.2'"
                    >
                        openai/gpt-5.2 (Premium – higher cost)
                    </option>
                    <option
                        value="google/gemini-1.5-flash"
                        :disabled="
                            categorizationModel === 'google/gemini-1.5-flash'
                        "
                    >
                        google/gemini-1.5-flash
                    </option>
                    <option
                        value="google/gemini-1.5-pro"
                        :disabled="
                            categorizationModel === 'google/gemini-1.5-pro'
                        "
                    >
                        google/gemini-1.5-pro
                    </option>
                    <option
                        value="anthropic/claude-3.5-sonnet"
                        :disabled="
                            categorizationModel ===
                            'anthropic/claude-3.5-sonnet'
                        "
                    >
                        anthropic/claude-3.5-sonnet
                    </option>
                </select>

                <p class="admin-card__hint" style="margin-top: 8px">
                    Used once per weekly report to generate executive summaries
                    and insights.
                </p>
            </div>

            <div class="admin-form-row" style="margin-top: 16px">
                <label class="admin-label">Provider</label>
                <select v-model="provider" class="admin-input">
                    <option value="openrouter">openrouter</option>
                    <option value="openai">openai</option>
                    <option value="anthropic">anthropic</option>
                </select>
            </div>

            <div class="admin-form-row" style="margin-top: 12px">
                <label class="admin-label">API Key</label>
                <input
                    type="password"
                    v-model="apiKey"
                    class="admin-input"
                    placeholder="Enter API key (will be stored encrypted)"
                />
                <p class="admin-card__hint" style="margin-top: 8px">
                    API key is stored encrypted and never returned by the API.
                </p>
            </div>

            <div class="admin-form-row" style="margin-top: 16px">
                <label class="admin-label">Categorization System Prompt</label>
                <textarea
                    v-model="categorizationSystemPrompt"
                    class="admin-input admin-textarea"
                    rows="6"
                    placeholder="Leave blank to use the default categorization prompt"
                />
                <p class="admin-card__hint" style="margin-top: 8px">
                    Overrides the system prompt used for call categorization.
                </p>
            </div>

            <div class="admin-form-row" style="margin-top: 16px">
                <label class="admin-label">Call Summary System Prompt</label>
                <textarea
                    v-model="summarySystemPrompt"
                    class="admin-input admin-textarea"
                    rows="6"
                    placeholder="Leave blank to use the default call summary prompt"
                />
                <p class="admin-card__hint" style="margin-top: 8px">
                    Overrides the system prompt used for per-call summaries.
                </p>
            </div>

            <div style="margin-top: 16px">
                <label
                    style="display: inline-flex; align-items: center; gap: 8px"
                >
                    <input type="checkbox" v-model="enabled" />
                    <span>Enable AI integration</span>
                </label>
            </div>

            <div style="margin-top: 16px">
                <BaseButton
                    variant="primary"
                    size="md"
                    @click="save"
                    :loading="saving"
                    :disabled="saving || invalidChoice"
                >
                    <template v-if="saving">Saving...</template>
                    <template v-else>Save</template>
                </BaseButton>
            </div>

            <div
                v-if="error"
                class="admin-alert admin-alert--error"
                style="margin-top: 12px"
            >
                {{ error }}
            </div>
            <div
                v-if="success"
                class="admin-alert admin-alert--success"
                style="margin-top: 12px"
            >
                Saved.
            </div>
        </section>
    </div>
</template>

<script setup>
import { ref, computed, onMounted } from "vue";
import { BaseButton } from "../../components/admin/base";
import adminApi from "../../router/admin/api";

const categorizationModel = ref("openai/gpt-4o-mini");
const reportModel = ref("openai/gpt-5.2");
const provider = ref("openrouter");
const apiKey = ref("");
const enabled = ref(false);
const defaultCategorizationPrompt = `You are a phone call classification engine.

Your task is to assign the call to ONE category chosen from a predefined list.
These categories are managed by the system administrator and MUST be followed strictly.

You MUST NOT invent new primary categories.
If intent is unclear, choose the closest matching category or "General".

Return valid JSON only.`;

const defaultSummaryPrompt = `You are a call summarization assistant.

Summarize the call in two concise paragraphs. Keep the summary factual, neutral, and client-friendly.
Avoid speculation and do not invent details that are not present in the transcript.
Return plain text only.`;

const categorizationSystemPrompt = ref(defaultCategorizationPrompt);
const summarySystemPrompt = ref(defaultSummaryPrompt);

const saving = ref(false);
const error = ref("");
const success = ref(false);

const invalidChoice = computed(
    () => categorizationModel.value === reportModel.value,
);

async function load() {
    try {
        const res = await adminApi.get("/ai-settings");
        const data = res?.data?.data;
        if (data) {
            categorizationModel.value =
                data.categorization_model ?? categorizationModel.value;
            reportModel.value = data.report_model ?? reportModel.value;
            provider.value = data.provider ?? provider.value;
            enabled.value = !!data.enabled;
            categorizationSystemPrompt.value =
                data.categorization_system_prompt ??
                defaultCategorizationPrompt;
            summarySystemPrompt.value =
                data.summary_system_prompt ?? defaultSummaryPrompt;
            // api_key is never returned by API — keep blank
        }
    } catch (e) {
        // ignore
    }
}

async function save() {
    if (invalidChoice.value) {
        error.value = "Categorization and report models must differ.";
        success.value = false;
        return;
    }

    saving.value = true;
    error.value = "";
    success.value = false;

    try {
        const payload = {
            provider: provider.value,
            api_key: apiKey.value || null,
            categorization_model: categorizationModel.value,
            categorization_system_prompt:
                categorizationSystemPrompt.value || null,
            summary_system_prompt: summarySystemPrompt.value || null,
            report_model: reportModel.value,
            enabled: enabled.value,
        };

        await adminApi.post("/ai-settings", payload);
        success.value = true;
        apiKey.value = ""; // clear after submit
        showToast("AI settings saved.");
    } catch (e) {
        error.value =
            e?.response?.data?.message || "Failed to save AI settings.";
    } finally {
        saving.value = false;
    }
}

onMounted(() => load());

function showToast(message) {
    try {
        let container = document.getElementById("__ai_toast_container");
        if (!container) {
            container = document.createElement("div");
            container.id = "__ai_toast_container";
            Object.assign(container.style, {
                position: "fixed",
                top: "16px",
                right: "16px",
                zIndex: 9999,
                display: "flex",
                flexDirection: "column",
                gap: "8px",
            });
            document.body.appendChild(container);
        }

        const el = document.createElement("div");
        el.textContent = message;
        Object.assign(el.style, {
            background: "#0f5132",
            color: "white",
            padding: "10px 14px",
            borderRadius: "8px",
            boxShadow: "0 6px 18px rgba(16,24,40,0.12)",
            opacity: "0",
            transition: "opacity 200ms ease, transform 200ms ease",
            transform: "translateY(-6px)",
            fontSize: "14px",
            lineHeight: "20px",
        });

        container.appendChild(el);

        // animate in
        requestAnimationFrame(() => {
            el.style.opacity = "1";
            el.style.transform = "translateY(0)";
        });

        setTimeout(() => {
            // animate out
            el.style.opacity = "0";
            el.style.transform = "translateY(-6px)";
            setTimeout(() => el.remove(), 220);
        }, 3000);
    } catch (e) {
        // fallback
        // eslint-disable-next-line no-alert
        alert(message);
    }
}
</script>
