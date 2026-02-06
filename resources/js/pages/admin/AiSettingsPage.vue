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

        <div v-if="error" class="admin-alert admin-alert--error">
            {{ error }}
        </div>
        <div v-if="success" class="admin-alert admin-alert--success">
            Saved.
        </div>

        <section class="admin-dashboard__grid">
            <section class="admin-card admin-card--glass">
                <div
                    style="
                        display: flex;
                        align-items: center;
                        justify-content: space-between;
                        gap: 16px;
                    "
                >
                    <div>
                        <h2 class="admin-card__headline">
                            Call Categorization
                        </h2>
                        <p class="admin-card__hint">
                            Runs per call. Controls category accuracy and
                            summaries.
                        </p>
                    </div>
                    <BaseButton
                        variant="secondary"
                        size="sm"
                        @click="openCategorizationModal"
                    >
                        Edit
                    </BaseButton>
                </div>

                <div class="admin-kvGrid" style="margin-top: 16px">
                    <div class="admin-kv">
                        <div class="admin-kv__k">Model</div>
                        <div class="admin-kv__v admin-callsMono">
                            {{ modelLabel(categorizationModel) }}
                        </div>
                    </div>
                    <div class="admin-kv">
                        <div class="admin-kv__k">System prompt</div>
                        <div class="admin-kv__v">
                            {{ promptPreview(categorizationSystemPrompt) }}
                        </div>
                    </div>
                    <div class="admin-kv" style="grid-column: 1 / -1">
                        <div class="admin-kv__k">Summary prompt</div>
                        <div class="admin-kv__v">
                            {{ promptPreview(summarySystemPrompt) }}
                        </div>
                    </div>
                </div>
            </section>

            <section class="admin-card admin-card--glass">
                <div
                    style="
                        display: flex;
                        align-items: center;
                        justify-content: space-between;
                        gap: 16px;
                    "
                >
                    <div>
                        <h2 class="admin-card__headline">Weekly Reports</h2>
                        <p class="admin-card__hint">
                            Generates weekly executive summaries and insights.
                        </p>
                    </div>
                    <BaseButton
                        variant="secondary"
                        size="sm"
                        @click="openReportModal"
                    >
                        Edit
                    </BaseButton>
                </div>

                <div class="admin-kvGrid" style="margin-top: 16px">
                    <div class="admin-kv">
                        <div class="admin-kv__k">Model</div>
                        <div class="admin-kv__v admin-callsMono">
                            {{ modelLabel(reportModel) }}
                        </div>
                    </div>
                    <div class="admin-kv">
                        <div class="admin-kv__k">Run cadence</div>
                        <div class="admin-kv__v">Weekly</div>
                    </div>
                </div>
            </section>

            <section class="admin-card admin-card--glass">
                <div
                    style="
                        display: flex;
                        align-items: center;
                        justify-content: space-between;
                        gap: 16px;
                    "
                >
                    <div>
                        <h2 class="admin-card__headline">Provider & Access</h2>
                        <p class="admin-card__hint">
                            Manage connectivity and platform-wide AI access.
                        </p>
                    </div>
                    <BaseButton
                        variant="secondary"
                        size="sm"
                        @click="openProviderModal"
                    >
                        Manage
                    </BaseButton>
                </div>

                <div class="admin-kvGrid" style="margin-top: 16px">
                    <div class="admin-kv">
                        <div class="admin-kv__k">Provider</div>
                        <div class="admin-kv__v admin-callsMono">
                            {{ providerLabel(provider) }}
                        </div>
                    </div>
                    <div class="admin-kv">
                        <div class="admin-kv__k">API Key</div>
                        <div class="admin-kv__v">
                            {{ apiKeyStatus }}
                        </div>
                    </div>
                    <div class="admin-kv">
                        <div class="admin-kv__k">Status</div>
                        <div class="admin-kv__v">
                            <BaseBadge :variant="enabled ? 'active' : 'failed'">
                                {{ enabled ? "Enabled" : "Disabled" }}
                            </BaseBadge>
                        </div>
                    </div>
                </div>
            </section>
        </section>

        <Teleport to="body">
            <Transition name="admin-modal">
                <div
                    v-if="showCategorizationModal"
                    class="admin-modalOverlay"
                    @click="closeCategorizationModal"
                >
                    <div class="admin-modal" @click.stop>
                        <div class="admin-modal__header">
                            <h2 class="admin-modal__title">
                                Call Categorization
                            </h2>
                            <button
                                type="button"
                                class="admin-modal__close"
                                @click="closeCategorizationModal"
                            >
                                ✕
                            </button>
                        </div>

                        <div class="admin-modal__body">
                            <div class="admin-field">
                                <label class="admin-field__label">Model</label>
                                <select
                                    v-model="draftCategorizationModel"
                                    class="admin-input"
                                >
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
                            </div>

                            <div class="admin-field" style="margin-top: 16px">
                                <label class="admin-field__label">
                                    Categorization System Prompt
                                </label>
                                <textarea
                                    v-model="draftCategorizationPrompt"
                                    class="admin-input admin-textarea"
                                    rows="6"
                                    placeholder="Leave blank to use the default categorization prompt"
                                />
                            </div>

                            <div class="admin-field" style="margin-top: 16px">
                                <label class="admin-field__label">
                                    Call Summary System Prompt
                                </label>
                                <textarea
                                    v-model="draftSummaryPrompt"
                                    class="admin-input admin-textarea"
                                    rows="6"
                                    placeholder="Leave blank to use the default call summary prompt"
                                />
                            </div>
                        </div>

                        <div class="admin-modal__footer">
                            <BaseButton
                                variant="secondary"
                                @click="closeCategorizationModal"
                                :disabled="saving"
                            >
                                Cancel
                            </BaseButton>
                            <BaseButton
                                variant="primary"
                                @click="saveCategorization"
                                :loading="saving"
                                :disabled="saving"
                            >
                                Save
                            </BaseButton>
                        </div>
                    </div>
                </div>
            </Transition>
        </Teleport>

        <Teleport to="body">
            <Transition name="admin-modal">
                <div
                    v-if="showReportModal"
                    class="admin-modalOverlay"
                    @click="closeReportModal"
                >
                    <div class="admin-modal" @click.stop>
                        <div class="admin-modal__header">
                            <h2 class="admin-modal__title">Weekly Reports</h2>
                            <button
                                type="button"
                                class="admin-modal__close"
                                @click="closeReportModal"
                            >
                                ✕
                            </button>
                        </div>

                        <div class="admin-modal__body">
                            <div class="admin-field">
                                <label class="admin-field__label">Model</label>
                                <select
                                    v-model="draftReportModel"
                                    class="admin-input"
                                >
                                    <option
                                        value="openai/gpt-4o-mini"
                                        :disabled="
                                            categorizationModel ===
                                            'openai/gpt-4o-mini'
                                        "
                                    >
                                        openai/gpt-4o-mini
                                    </option>
                                    <option
                                        value="openai/gpt-5.2"
                                        :disabled="
                                            categorizationModel ===
                                            'openai/gpt-5.2'
                                        "
                                    >
                                        openai/gpt-5.2 (Premium – higher cost)
                                    </option>
                                    <option
                                        value="google/gemini-1.5-flash"
                                        :disabled="
                                            categorizationModel ===
                                            'google/gemini-1.5-flash'
                                        "
                                    >
                                        google/gemini-1.5-flash
                                    </option>
                                    <option
                                        value="google/gemini-1.5-pro"
                                        :disabled="
                                            categorizationModel ===
                                            'google/gemini-1.5-pro'
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
                            </div>

                            <div
                                v-if="reportInvalidChoice"
                                class="admin-alert admin-alert--error"
                                style="margin-top: 12px"
                            >
                                Categorization and report models must differ.
                            </div>
                        </div>

                        <div class="admin-modal__footer">
                            <BaseButton
                                variant="secondary"
                                @click="closeReportModal"
                                :disabled="saving"
                            >
                                Cancel
                            </BaseButton>
                            <BaseButton
                                variant="primary"
                                @click="saveReport"
                                :loading="saving"
                                :disabled="saving || reportInvalidChoice"
                            >
                                Save
                            </BaseButton>
                        </div>
                    </div>
                </div>
            </Transition>
        </Teleport>

        <Teleport to="body">
            <Transition name="admin-modal">
                <div
                    v-if="showProviderModal"
                    class="admin-modalOverlay"
                    @click="closeProviderModal"
                >
                    <div class="admin-modal" @click.stop>
                        <div class="admin-modal__header">
                            <h2 class="admin-modal__title">
                                Provider & Access
                            </h2>
                            <button
                                type="button"
                                class="admin-modal__close"
                                @click="closeProviderModal"
                            >
                                ✕
                            </button>
                        </div>

                        <div class="admin-modal__body">
                            <div class="admin-field">
                                <label class="admin-field__label"
                                    >Provider</label
                                >
                                <select
                                    v-model="draftProvider"
                                    class="admin-input"
                                >
                                    <option value="openrouter">
                                        openrouter
                                    </option>
                                    <option value="openai">openai</option>
                                    <option value="anthropic">anthropic</option>
                                </select>
                            </div>

                            <div class="admin-field" style="margin-top: 16px">
                                <label class="admin-field__label"
                                    >API Key</label
                                >
                                <input
                                    type="password"
                                    v-model="draftApiKey"
                                    class="admin-input"
                                    placeholder="Enter API key (stored encrypted)"
                                />
                                <p
                                    class="admin-card__hint"
                                    style="margin-top: 8px"
                                >
                                    API key is stored encrypted and never
                                    returned by the API.
                                </p>
                            </div>

                            <div style="margin-top: 16px">
                                <label
                                    style="
                                        display: inline-flex;
                                        align-items: center;
                                        gap: 8px;
                                    "
                                >
                                    <input
                                        type="checkbox"
                                        v-model="draftEnabled"
                                    />
                                    <span>Enable AI integration</span>
                                </label>
                            </div>
                        </div>

                        <div class="admin-modal__footer">
                            <BaseButton
                                variant="secondary"
                                @click="closeProviderModal"
                                :disabled="saving"
                            >
                                Cancel
                            </BaseButton>
                            <BaseButton
                                variant="primary"
                                @click="saveProvider"
                                :loading="saving"
                                :disabled="saving"
                            >
                                Save
                            </BaseButton>
                        </div>
                    </div>
                </div>
            </Transition>
        </Teleport>
    </div>
</template>

<script setup>
import { ref, computed, onMounted } from "vue";
import { BaseBadge, BaseButton } from "../../components/admin/base";
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

const showCategorizationModal = ref(false);
const showReportModal = ref(false);
const showProviderModal = ref(false);

const draftCategorizationModel = ref("");
const draftCategorizationPrompt = ref("");
const draftSummaryPrompt = ref("");
const draftReportModel = ref("");
const draftProvider = ref("");
const draftApiKey = ref("");
const draftEnabled = ref(false);

const saving = ref(false);
const error = ref("");
const success = ref(false);

const invalidChoice = computed(
    () => categorizationModel.value === reportModel.value,
);

const reportInvalidChoice = computed(
    () => draftReportModel.value === categorizationModel.value,
);

const apiKeyStatus = computed(() =>
    apiKey.value ? "New key pending save" : "Stored securely (hidden)",
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
        return false;
    }

    saving.value = true;
    error.value = "";
    success.value = false;

    let ok = false;

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
        ok = true;
    } catch (e) {
        error.value =
            e?.response?.data?.message || "Failed to save AI settings.";
    } finally {
        saving.value = false;
    }

    return ok;
}

onMounted(() => load());

function modelLabel(value) {
    const map = {
        "openai/gpt-4o-mini": "openai/gpt-4o-mini (Recommended)",
        "openai/gpt-4.1-mini": "openai/gpt-4.1-mini (Cheapest)",
        "openai/gpt-5.2": "openai/gpt-5.2 (Premium)",
        "google/gemini-1.5-flash": "google/gemini-1.5-flash (Fast)",
        "google/gemini-1.5-pro": "google/gemini-1.5-pro (Premium)",
        "anthropic/claude-3.5-sonnet": "anthropic/claude-3.5-sonnet",
    };
    return map[value] ?? value ?? "—";
}

function providerLabel(value) {
    const map = {
        openrouter: "OpenRouter",
        openai: "OpenAI",
        anthropic: "Anthropic",
    };
    return map[value] ?? value ?? "—";
}

function promptPreview(text) {
    const value = String(text || "").trim();
    if (!value) return "Default prompt";
    if (value.length <= 140) return value;
    return `${value.slice(0, 140)}…`;
}

function openCategorizationModal() {
    draftCategorizationModel.value = categorizationModel.value;
    draftCategorizationPrompt.value = categorizationSystemPrompt.value;
    draftSummaryPrompt.value = summarySystemPrompt.value;
    showCategorizationModal.value = true;
}

function closeCategorizationModal() {
    showCategorizationModal.value = false;
}

function openReportModal() {
    draftReportModel.value = reportModel.value;
    showReportModal.value = true;
}

function closeReportModal() {
    showReportModal.value = false;
}

function openProviderModal() {
    draftProvider.value = provider.value;
    draftApiKey.value = apiKey.value;
    draftEnabled.value = enabled.value;
    showProviderModal.value = true;
}

function closeProviderModal() {
    showProviderModal.value = false;
}

async function saveCategorization() {
    categorizationModel.value = draftCategorizationModel.value;
    categorizationSystemPrompt.value = draftCategorizationPrompt.value;
    summarySystemPrompt.value = draftSummaryPrompt.value;
    const ok = await save();
    if (ok) {
        showCategorizationModal.value = false;
    }
}

async function saveReport() {
    reportModel.value = draftReportModel.value;
    const ok = await save();
    if (ok) {
        showReportModal.value = false;
    }
}

async function saveProvider() {
    provider.value = draftProvider.value;
    apiKey.value = draftApiKey.value;
    enabled.value = draftEnabled.value;
    const ok = await save();
    if (ok) {
        showProviderModal.value = false;
    }
}

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
