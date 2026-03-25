<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from "vue";
import { useRoute } from "vue-router";
import Card from "../components/ui/Card.vue";
import PageHeader from "../components/ui/PageHeader.vue";
import { adminApi } from "../api/adminApi";
import {
    getAiPendingStats,
    triggerAiRegenerate,
    type AiPendingStats,
    type AiProcessingStep,
} from "../api/aiProcessing";

type CompanyOption = {
    id: number;
    display_label: string;
};

const route = useRoute();

const loadingCompanies = ref(false);
const loadingPreview = ref(false);
const submitting = ref(false);
const polling = ref(false);
const error = ref<string | null>(null);

const companies = ref<CompanyOption[]>([]);
const companyId = ref<number | null>(null);
const fromDate = ref("");
const toDate = ref("");
const selectedSteps = ref<AiProcessingStep[]>(["summary", "categories"]);

const preview = ref<AiPendingStats | null>(null);
const initialPending = ref(0);
const lastPolledPending = ref(0);
const pollTimer = ref<number | null>(null);

const canPreview = computed(
    () => fromDate.value !== "" && toDate.value !== "" && selectedSteps.value.length > 0,
);

const progressPercent = computed(() => {
    if (initialPending.value <= 0) {
        return 0;
    }

    const remaining = Math.max(0, lastPolledPending.value);
    const done = initialPending.value - remaining;

    return Math.max(0, Math.min(100, Math.round((done / initialPending.value) * 100)));
});

const isDone = computed(
    () => polling.value && initialPending.value > 0 && lastPolledPending.value === 0,
);

function normalizeDateInput(value: unknown): string {
    if (typeof value !== "string" || value.trim() === "") {
        return "";
    }

    return value.slice(0, 10);
}

function initFromRouteQuery(): void {
    const query = route.query;

    const companyQuery = query.company_id;
    if (typeof companyQuery === "string" && companyQuery !== "") {
        const parsed = Number(companyQuery);
        if (!Number.isNaN(parsed)) {
            companyId.value = parsed;
        }
    }

    fromDate.value = normalizeDateInput(query.from_date);
    toDate.value = normalizeDateInput(query.to_date);
}

function stopPolling(): void {
    if (pollTimer.value !== null) {
        window.clearInterval(pollTimer.value);
        pollTimer.value = null;
    }
    polling.value = false;
}

async function loadCompanies(): Promise<void> {
    loadingCompanies.value = true;

    try {
        const response = await adminApi.get<{ data: Array<{ id: number; display_label: string }> }>(
            "/companies/dropdown",
        );
        companies.value = (response.data.data ?? []).map((item) => ({
            id: item.id,
            display_label: item.display_label,
        }));
    } catch (e) {
        error.value = e instanceof Error ? e.message : "Failed to load companies";
    } finally {
        loadingCompanies.value = false;
    }
}

async function runPreview(): Promise<void> {
    if (!canPreview.value) {
        return;
    }

    error.value = null;
    loadingPreview.value = true;

    try {
        const stats = await getAiPendingStats({
            company_id: companyId.value,
            from_date: fromDate.value,
            to_date: toDate.value,
            steps: selectedSteps.value,
        });
        preview.value = stats;
        lastPolledPending.value = stats.total_pending;
    } catch (e) {
        error.value = e instanceof Error ? e.message : "Failed to load pending stats";
    } finally {
        loadingPreview.value = false;
    }
}

async function startRegeneration(): Promise<void> {
    if (!canPreview.value || submitting.value) {
        return;
    }

    error.value = null;
    submitting.value = true;

    try {
        if (!preview.value) {
            await runPreview();
        }

        const latestPreview = preview.value;
        if (!latestPreview) {
            return;
        }

        initialPending.value = latestPreview.total_pending;
        lastPolledPending.value = latestPreview.total_pending;

        await triggerAiRegenerate({
            company_id: companyId.value ?? undefined,
            from_date: fromDate.value,
            to_date: toDate.value,
            steps: selectedSteps.value,
        });

        polling.value = true;

        if (pollTimer.value !== null) {
            window.clearInterval(pollTimer.value);
        }

        pollTimer.value = window.setInterval(async () => {
            try {
                const stats = await getAiPendingStats({
                    company_id: companyId.value,
                    from_date: fromDate.value,
                    to_date: toDate.value,
                    steps: selectedSteps.value,
                });

                preview.value = stats;
                lastPolledPending.value = stats.total_pending;

                if (stats.total_pending <= 0) {
                    stopPolling();
                }
            } catch {
                stopPolling();
            }
        }, 3000);
    } catch (e) {
        error.value = e instanceof Error ? e.message : "Failed to queue regeneration";
        stopPolling();
    } finally {
        submitting.value = false;
    }
}

function toggleStep(step: AiProcessingStep): void {
    if (selectedSteps.value.includes(step)) {
        selectedSteps.value = selectedSteps.value.filter((s) => s !== step);
        return;
    }

    selectedSteps.value = [...selectedSteps.value, step];
}

onMounted(async () => {
    initFromRouteQuery();
    await loadCompanies();

    if (canPreview.value) {
        await runPreview();
    }
});

onUnmounted(() => {
    stopPolling();
});
</script>

<template>
    <div class="page">
        <PageHeader
            title="AI Processing"
            description="Preview and regenerate transcript, summary, and category processing for selected calls."
        />

        <Card title="Scope" subtitle="Choose company, dates, and processing steps">
            <div class="form-grid">
                <label class="field">
                    <span>Company</span>
                    <select v-model="companyId" :disabled="loadingCompanies">
                        <option :value="null">All companies</option>
                        <option
                            v-for="company in companies"
                            :key="company.id"
                            :value="company.id"
                        >
                            {{ company.display_label }}
                        </option>
                    </select>
                </label>

                <label class="field">
                    <span>From date</span>
                    <input v-model="fromDate" type="date" />
                </label>

                <label class="field">
                    <span>To date</span>
                    <input v-model="toDate" type="date" />
                </label>
            </div>

            <div class="steps">
                <label class="step-option">
                    <input
                        type="checkbox"
                        :checked="selectedSteps.includes('transcript')"
                        @change="toggleStep('transcript')"
                    />
                    Transcript regeneration
                </label>
                <label class="step-option">
                    <input
                        type="checkbox"
                        :checked="selectedSteps.includes('summary')"
                        @change="toggleStep('summary')"
                    />
                    AI call summary
                </label>
                <label class="step-option">
                    <input
                        type="checkbox"
                        :checked="selectedSteps.includes('categories')"
                        @change="toggleStep('categories')"
                    />
                    AI categories
                </label>
            </div>

            <div class="actions">
                <button
                    class="btn btn--ghost"
                    type="button"
                    :disabled="!canPreview || loadingPreview"
                    @click="runPreview"
                >
                    {{ loadingPreview ? "Loading preview..." : "Preview pending" }}
                </button>
                <button
                    class="btn"
                    type="button"
                    :disabled="!canPreview || submitting"
                    @click="startRegeneration"
                >
                    {{ submitting ? "Queueing..." : "Start regeneration" }}
                </button>
            </div>
        </Card>

        <Card v-if="preview" title="Pending work" subtitle="Live view of remaining calls">
            <div class="stats-grid">
                <div class="stat">
                    <div class="label">Summary pending</div>
                    <div class="value">{{ preview.summary_pending }}</div>
                </div>
                <div class="stat">
                    <div class="label">Category pending</div>
                    <div class="value">{{ preview.category_pending }}</div>
                </div>
                <div class="stat">
                    <div class="label">Transcript estimate</div>
                    <div class="value">{{ preview.transcript_pending_estimate }}</div>
                </div>
                <div class="stat">
                    <div class="label">Affected reports</div>
                    <div class="value">{{ preview.affected_reports }}</div>
                </div>
            </div>

            <div v-if="polling" class="progress-wrap">
                <div class="progress-label">
                    <span>Regeneration progress</span>
                    <span>{{ progressPercent }}%</span>
                </div>
                <div class="progress-track">
                    <div class="progress-fill" :style="{ width: `${progressPercent}%` }"></div>
                </div>
                <p class="progress-note">
                    Remaining AI pending: {{ lastPolledPending }}
                </p>
                <p v-if="isDone" class="done">All selected AI steps have completed.</p>
            </div>

            <table v-if="preview.per_report.length > 0" class="report-table">
                <thead>
                    <tr>
                        <th>Report ID</th>
                        <th>Company</th>
                        <th>Week</th>
                        <th>Pending calls</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in preview.per_report" :key="row.report_id">
                        <td>{{ row.report_id }}</td>
                        <td>{{ row.company_name || "-" }}</td>
                        <td>
                            {{ row.week_start_date || "?" }}
                            to
                            {{ row.week_end_date || "?" }}
                        </td>
                        <td>{{ row.pending_count }}</td>
                    </tr>
                </tbody>
            </table>
        </Card>

        <p v-if="error" class="error-text">{{ error }}</p>
    </div>
</template>

<style scoped>
.form-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: var(--space-3);
}

.field {
    display: grid;
    gap: 8px;
    font-weight: 600;
}

.field select,
.field input {
    border: 1px solid #d9d9de;
    border-radius: 10px;
    padding: 10px 12px;
    font: inherit;
    background: #fff;
}

.steps {
    margin-top: var(--space-4);
    display: flex;
    gap: var(--space-3);
    flex-wrap: wrap;
}

.step-option {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    font-weight: 500;
}

.actions {
    margin-top: var(--space-4);
    display: flex;
    gap: var(--space-3);
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: var(--space-3);
}

.stat {
    border: 1px solid #ececf1;
    border-radius: 12px;
    padding: 12px;
}

.label {
    font-size: 12px;
    color: #666;
}

.value {
    font-size: 24px;
    font-weight: 700;
    margin-top: 4px;
}

.progress-wrap {
    margin-top: var(--space-4);
}

.progress-label {
    display: flex;
    justify-content: space-between;
    font-weight: 600;
    margin-bottom: 8px;
}

.progress-track {
    height: 12px;
    border-radius: 999px;
    background: #ececf1;
    overflow: hidden;
}

.progress-fill {
    height: 100%;
    background: linear-gradient(90deg, #1f9d55, #48bb78);
    transition: width 0.3s ease;
}

.progress-note {
    margin-top: 8px;
    color: #444;
}

.done {
    margin-top: 8px;
    color: #1f9d55;
    font-weight: 700;
}

.report-table {
    width: 100%;
    margin-top: var(--space-4);
    border-collapse: collapse;
}

.report-table th,
.report-table td {
    border: 1px solid #ececf1;
    padding: 8px 10px;
    text-align: left;
}

.error-text {
    color: #a60020;
    margin-top: var(--space-3);
}

@media (max-width: 960px) {
    .form-grid {
        grid-template-columns: 1fr;
    }

    .stats-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}
</style>
