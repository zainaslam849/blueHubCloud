<script setup lang="ts">
import { computed, onMounted, ref, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import { userApi } from "../api/user";

type AiRecovery = {
    hasTranscript: boolean;
    canRegenerate: boolean;
    action: string | null;
    actionLabel: string | null;
    statusText: string | null;
};

type CallDetail = {
    id: number;
    callId: string;
    company: string;
    provider: string;
    durationSeconds: number;
    status: string;
    createdAt: string | null;
    startedAt: string | null;
    direction: string | null;
    from: string | null;
    to: string | null;
    aiSummary: string | null;
    aiSummaryStatus: string | null;
    aiCategoryStatus: string | null;
    category: string | null;
    subCategory: string | null;
    categoryConfidence: number | null;
};

type Transcription = {
    status: string;
    provider: string | null;
    hasTranscription: boolean;
    text: string | null;
};

type JobEvent = {
    key: string;
    type: string;
    label: string;
    status: string;
    occurredAt: string | null;
    detail: string | null;
};

type Metadata = Record<string, string | number | null | undefined>;

type DetailData = {
    call: CallDetail;
    transcription: Transcription;
    aiRecovery: AiRecovery;
    jobHistory: JobEvent[];
    metadata: Metadata;
};

const route  = useRoute();
const router = useRouter();

const loading     = ref(true);
const error       = ref("");
const regenerating = ref(false);

const call         = ref<CallDetail | null>(null);
const transcription = ref<Transcription | null>(null);
const aiRecovery   = ref<AiRecovery | null>(null);
const jobHistory   = ref<JobEvent[]>([]);
const metadata     = ref<Metadata>({});

// ── Formatters ──────────────────────────────────────────────────────────────

function formatDuration(seconds: number | null | undefined): string {
    const s = Number(seconds);
    if (!Number.isFinite(s) || s < 0) return "—";
    if (s === 0) return "0 seconds";
    const m   = Math.floor(s / 60);
    const sec = Math.floor(s % 60);
    const parts: string[] = [];
    if (m > 0)   parts.push(`${m} ${m === 1 ? "minute" : "minutes"}`);
    if (sec > 0) parts.push(`${sec} ${sec === 1 ? "second" : "seconds"}`);
    return parts.join(" ");
}

function formatDate(iso: string | null | undefined): string {
    if (!iso) return "—";
    const t = new Date(iso);
    if (!Number.isFinite(t.getTime())) return "—";
    return t.toLocaleString();
}

function formatConfidence(value: number | null | undefined): string {
    const n = Number(value);
    if (!Number.isFinite(n)) return "—";
    return `${Math.round(n * 100)}%`;
}

function badgeClass(status: string | undefined): string {
    const s = String(status || "").toLowerCase();
    if (s === "completed") return "dBadge--active";
    if (s === "failed")    return "dBadge--failed";
    return "dBadge--processing";
}

function humanizeAiStatus(status: string | null | undefined): string {
    const s = String(status || "").toLowerCase();
    if (!s) return "Pending";
    if (s === "queued")           return "Queued";
    if (s === "running")          return "Processing";
    if (s === "completed")        return "Completed";
    if (s === "credit_exhausted") return "Credit exhausted";
    if (s === "not_generated")    return "Not generated";
    return s.replace(/_/g, " ");
}

const summaryStatusLabel = computed(() => {
    if (call.value?.aiSummary) return "Available";
    return humanizeAiStatus(call.value?.aiSummaryStatus);
});

const categoryStatusLabel = computed(() => {
    if (call.value?.category) return "Available";
    return humanizeAiStatus(call.value?.aiCategoryStatus);
});

const metadataRows = computed(() => {
    const m = metadata.value ?? {};
    const c = call.value;
    return [
        { key: "company",         label: "Company",         value: String(c?.company ?? "—") },
        { key: "companyTimezone", label: "Timezone",        value: String(m.companyTimezone ?? "—") },
        { key: "companyStatus",   label: "Company Status",  value: String(m.companyStatus ?? "—").toUpperCase() },
        { key: "provider",        label: "Provider",        value: String(c?.provider ?? "—") },
        { key: "pbxProviderSlug", label: "Provider Slug",   value: String(m.pbxProviderSlug ?? "—") },
        { key: "pbxUniqueId",     label: "PBX Unique ID",   value: String(m.pbxUniqueId ?? "—") },
        { key: "serverId",        label: "Server ID",       value: String(m.serverId ?? "—") },
    ];
});

// ── Fetch ─────────────────────────────────────────────────────────────────

async function fetchDetail() {
    const callId = route.params.callId as string;
    loading.value = true;
    error.value   = "";

    try {
        const res  = await userApi.get<DetailData>(`/calls/${callId}`);
        const data = res.data;
        call.value         = data?.call ?? null;
        transcription.value = data?.transcription ?? null;
        aiRecovery.value   = data?.aiRecovery ?? null;
        jobHistory.value   = Array.isArray(data?.jobHistory) ? data.jobHistory : [];
        metadata.value     = data?.metadata ?? {};
    } catch (e: unknown) {
        call.value         = null;
        transcription.value = null;
        aiRecovery.value   = null;
        jobHistory.value   = [];
        metadata.value     = {};
        const status = (e as { response?: { status?: number } })?.response?.status;
        error.value = status === 404 ? "Call not found." : "Failed to load call.";
    } finally {
        loading.value = false;
    }
}

function refresh() {
    fetchDetail();
}

async function regenerateAi() {
    const callId = route.params.callId as string;
    if (!callId || regenerating.value || !aiRecovery.value?.canRegenerate) return;

    regenerating.value = true;
    error.value        = "";

    try {
        await userApi.post(`/calls/${callId}/regenerate-ai`);
        await fetchDetail();
    } catch (e: unknown) {
        error.value = (e as { response?: { data?: { message?: string } } })?.response?.data?.message
            ?? "Failed to queue AI regeneration for this call.";
    } finally {
        regenerating.value = false;
    }
}

function goBack() {
    router.push({ name: "calls" });
}

watch(() => route.params.callId, () => { fetchDetail(); });

onMounted(() => { fetchDetail(); });
</script>

<template>
    <div class="dPage">
        <!-- Header -->
        <header class="dHeader">
            <div class="dHeader__left">
                <div class="dHeader__icon">
                    <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z" stroke="currentColor" stroke-width="1.5" fill="none"/>
                    </svg>
                </div>
                <div class="dHeader__content">
                    <div class="dHeader__breadcrumb">
                        <button type="button" class="dBreadLink" @click="goBack">Calls</button>
                        <span class="dBreadSep">/</span>
                        <span>{{ call?.callId || "Loading…" }}</span>
                    </div>
                    <h1 class="dHeader__title">{{ call?.company || "Unknown Company" }}</h1>
                    <p class="dHeader__subtitle">Call on {{ formatDate(call?.createdAt) }}</p>
                </div>
            </div>

            <div class="dHeader__stats">
                <div class="dHeader__stat">
                    <div class="dHeader__statLabel">Duration</div>
                    <div class="dHeader__statValue">{{ formatDuration(call?.durationSeconds) }}</div>
                </div>
                <div class="dHeader__stat">
                    <div class="dHeader__statLabel">Status</div>
                    <div class="dHeader__statValue">
                        <span v-if="!loading && call?.status" class="dBadge" :class="badgeClass(call?.status)">
                            {{ String(call?.status || '').toUpperCase() }}
                        </span>
                        <span v-else>—</span>
                    </div>
                </div>
            </div>

            <div class="dHeader__actions">
                <button type="button" class="dBtn dBtn--ghost" @click="goBack">
                    <svg viewBox="0 0 24 24" fill="none" class="dBtn__icon">
                        <path d="M19 12H5M5 12L12 19M5 12L12 5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    Back
                </button>
                <button type="button" class="dBtn dBtn--ghost" :disabled="loading" @click="refresh">
                    <svg viewBox="0 0 24 24" fill="none" class="dBtn__icon">
                        <path d="M20 12a8 8 0 1 1-2.34-5.66" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                        <path d="M20 4v6h-6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    Refresh
                </button>
                <button
                    v-if="!loading && aiRecovery?.canRegenerate"
                    type="button"
                    class="dBtn dBtn--primary"
                    :disabled="regenerating"
                    @click="regenerateAi"
                >
                    {{ regenerating ? 'Queuing…' : aiRecovery?.actionLabel }}
                </button>
            </div>
        </header>

        <div v-if="error" class="dAlert dAlert--error">{{ error }}</div>

        <div class="dColumns">
            <!-- Skeleton cards while loading -->
            <template v-if="loading">
                <div class="dCard" v-for="n in 4" :key="n"><div class="dSkeletonLines"></div></div>
            </template>

            <template v-else>
                <!-- 1. Call Information -->
                <div class="dCard">
                    <div class="dCard__title">Call Information</div>
                    <div class="dKvGrid">
                        <div class="dKv"><div class="dKv__k">Call ID</div><div class="dKv__v dMono">{{ call?.callId ?? '—' }}</div></div>
                        <div class="dKv"><div class="dKv__k">From Number</div><div class="dKv__v dMono">{{ call?.from ?? '—' }}</div></div>
                        <div class="dKv"><div class="dKv__k">To Number</div><div class="dKv__v dMono">{{ call?.to ?? '—' }}</div></div>
                        <div class="dKv"><div class="dKv__k">Started At</div><div class="dKv__v dMono">{{ formatDate(call?.startedAt) }}</div></div>
                        <div class="dKv"><div class="dKv__k">Created At</div><div class="dKv__v dMono">{{ formatDate(call?.createdAt) }}</div></div>
                        <div class="dKv">
                            <div class="dKv__k">Status</div>
                            <div class="dKv__v">
                                <span class="dBadge" :class="badgeClass(call?.status)">{{ String(call?.status || '').toUpperCase() || '—' }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 2. Call Summary — only when summary exists -->
                <div v-if="call?.aiSummary" class="dCard">
                    <div class="dCard__title">Call Summary</div>
                    <div class="dCard__desc">AI-generated summary</div>
                    <pre class="dTranscript">{{ call.aiSummary }}</pre>
                </div>

                <!-- 3. Categorization — only when category exists -->
                <div v-if="call?.category" class="dCard">
                    <div class="dCard__title">Categorization</div>
                    <div class="dCard__desc">AI-generated category assignment</div>
                    <div class="dKvGrid">
                        <div class="dKv"><div class="dKv__k">Category</div><div class="dKv__v">{{ call.category }}</div></div>
                        <div class="dKv"><div class="dKv__k">Sub-category</div><div class="dKv__v">{{ call.subCategory || '—' }}</div></div>
                        <div class="dKv"><div class="dKv__k">Confidence</div><div class="dKv__v">{{ formatConfidence(call.categoryConfidence) }}</div></div>
                    </div>
                </div>

                <!-- 4. AI Processing -->
                <div class="dCard">
                    <div class="dCard__title">AI Processing</div>
                    <div class="dCard__desc">Transcript, summary, and categorization status</div>
                    <div class="dKvGrid">
                        <div class="dKv">
                            <div class="dKv__k">Transcript</div>
                            <div class="dKv__v">{{ transcription?.hasTranscription ? 'Available' : 'Not available' }}</div>
                        </div>
                        <div class="dKv">
                            <div class="dKv__k">AI Summary</div>
                            <div class="dKv__v">{{ summaryStatusLabel }}</div>
                        </div>
                        <div class="dKv">
                            <div class="dKv__k">AI Category</div>
                            <div class="dKv__v">{{ categoryStatusLabel }}</div>
                        </div>
                        <div class="dKv" style="grid-column: 1 / -1">
                            <div class="dKv__k">Recovery</div>
                            <div class="dKv__v dRecovery">
                                <span>{{ aiRecovery?.statusText || '—' }}</span>
                                <button
                                    v-if="aiRecovery?.canRegenerate"
                                    class="dBtn dBtn--secondary dBtn--sm"
                                    type="button"
                                    :disabled="regenerating"
                                    @click="regenerateAi"
                                >
                                    {{ regenerating ? 'Queuing…' : aiRecovery?.actionLabel }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 5. Job History — only when events exist -->
                <div v-if="jobHistory.length > 0" class="dCard">
                    <div class="dCard__title">Job History</div>
                    <div class="dCard__desc">Ingestion and transcription events</div>
                    <ol class="dTimeline">
                        <li v-for="ev in jobHistory" :key="ev.key" class="dTimeline__item">
                            <div class="dTimeline__rail" aria-hidden="true"><div class="dTimeline__dot"></div></div>
                            <div class="dTimeline__content">
                                <div class="dTimeline__top">
                                    <div class="dTimeline__title">{{ ev.label }}</div>
                                    <span class="dBadge" :class="badgeClass(ev.status)">{{ String(ev.status || '').toUpperCase() }}</span>
                                </div>
                                <div class="dTimeline__meta dMono">
                                    {{ formatDate(ev.occurredAt) }}
                                    <span v-if="ev.detail"> • {{ ev.detail }}</span>
                                </div>
                            </div>
                        </li>
                    </ol>
                </div>

                <!-- 6. Transcription — only when transcript exists -->
                <div v-if="transcription?.hasTranscription" class="dCard">
                    <div class="dCard__title">Transcription</div>
                    <div class="dCard__desc">PBX-provided transcript</div>
                    <div class="dKvGrid">
                        <div class="dKv">
                            <div class="dKv__k">Status</div>
                            <div class="dKv__v"><span class="dBadge dBadge--active">COMPLETED</span></div>
                        </div>
                        <div class="dKv">
                            <div class="dKv__k">Provider</div>
                            <div class="dKv__v">{{ transcription.provider ?? 'pbxware' }}</div>
                        </div>
                    </div>
                    <pre class="dTranscript">{{ transcription.text || '' }}</pre>
                </div>

                <!-- 7. Metadata -->
                <div class="dCard">
                    <div class="dCard__title">Metadata</div>
                    <div class="dCard__desc">Identifiers and raw fields</div>
                    <div class="dKvGrid">
                        <div v-for="row in metadataRows" :key="row.key" class="dKv">
                            <div class="dKv__k">{{ row.label }}</div>
                            <div class="dKv__v dMono">{{ row.value }}</div>
                        </div>
                    </div>
                </div>
            </template>
        </div>
    </div>
</template>

<style scoped>
/* ── Layout ──────────────────────────────────────────────────────────────── */
.dPage { display: flex; flex-direction: column; gap: var(--space-4, 1rem); }

/* ── Header ──────────────────────────────────────────────────────────────── */
.dHeader {
    display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; flex-wrap: wrap;
    padding: var(--space-5, 1.25rem) var(--space-6, 1.5rem);
    background: var(--surface, #fff); border: 1px solid var(--border, #e5e7eb); border-radius: 14px;
}
.dHeader__left { display: flex; align-items: flex-start; gap: 1rem; }
.dHeader__icon {
    width: 44px; height: 44px; border-radius: 10px; flex-shrink: 0;
    background: color-mix(in srgb, var(--color-primary, #3b82f6) 15%, transparent);
    color: var(--color-primary, #3b82f6); display: grid; place-items: center;
}
.dHeader__icon svg { width: 20px; height: 20px; }
.dHeader__breadcrumb { display: flex; align-items: center; gap: 6px; font-size: 0.82rem; opacity: 0.65; margin-bottom: 4px; }
.dBreadLink { background: none; border: none; cursor: pointer; font-size: 0.82rem; color: var(--color-primary, #3b82f6); padding: 0; }
.dBreadLink:hover { text-decoration: underline; }
.dBreadSep { opacity: 0.4; }
.dHeader__title { font-size: 1.25rem; font-weight: 800; margin: 0 0 2px; }
.dHeader__subtitle { font-size: 0.85rem; opacity: 0.65; margin: 0; }

.dHeader__stats { display: flex; gap: 1rem; align-items: flex-start; }
.dHeader__stat {
    padding: 0.5rem 1rem; border-radius: 8px; text-align: center; min-width: 80px;
    background: color-mix(in srgb, var(--color-primary, #3b82f6) 8%, transparent);
}
.dHeader__statLabel { font-size: 0.7rem; opacity: 0.6; text-transform: uppercase; letter-spacing: 0.05em; }
.dHeader__statValue { font-size: 1rem; font-weight: 700; margin-top: 2px; }

.dHeader__actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }

/* ── Buttons ─────────────────────────────────────────────────────────────── */
.dBtn {
    display: inline-flex; align-items: center; gap: 6px; height: 34px; padding: 0 12px;
    border-radius: 8px; font-size: 0.82rem; font-weight: 600; border: none; cursor: pointer;
    white-space: nowrap; transition: filter 0.15s, opacity 0.15s;
}
.dBtn--sm { height: 28px; padding: 0 10px; font-size: 0.78rem; }
.dBtn--primary { background: var(--color-primary, #3b82f6); color: #fff; }
.dBtn--primary:hover:not(:disabled) { filter: brightness(1.08); }
.dBtn--secondary { background: var(--surface-2, #f3f4f6); color: inherit; border: 1px solid var(--border, #e5e7eb); }
.dBtn--secondary:hover:not(:disabled) { background: color-mix(in srgb, var(--border) 60%, var(--surface-2)); }
.dBtn--ghost { background: transparent; color: inherit; border: 1px solid var(--border, #e5e7eb); }
.dBtn--ghost:hover:not(:disabled) { background: var(--surface-2, #f3f4f6); }
.dBtn:disabled { opacity: 0.45; cursor: not-allowed; }
.dBtn__icon { width: 14px; height: 14px; flex-shrink: 0; }

/* ── Alert ───────────────────────────────────────────────────────────────── */
.dAlert { padding: 10px 14px; border-radius: 8px; font-size: 0.9rem; }
.dAlert--error { background: color-mix(in srgb, #e53e3e 10%, transparent); border: 1px solid #e53e3e; color: #e53e3e; }

/* Card */
.dCard {
    background: var(--surface, #fff); border: 1px solid var(--border, #e5e7eb);
    border-radius: 14px; padding: var(--space-5, 1.25rem); display: flex; flex-direction: column; gap: 12px;
}
.dCard__title { font-size: 1rem; font-weight: 700; margin: 0; }
.dCard__desc { font-size: 0.82rem; opacity: 0.55; margin-top: -8px; }

/* Cards column */
.dColumns { display: flex; flex-direction: column; gap: var(--space-4, 1rem); }

/* Key-value grid */
.dKvGrid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 12px; }
.dKv { display: flex; flex-direction: column; gap: 4px; }
.dKv__k { font-size: 0.72rem; font-weight: 600; opacity: 0.55; text-transform: uppercase; letter-spacing: 0.04em; }
.dKv__v { font-size: 0.9rem; }
.dMono { font-family: ui-monospace, monospace; font-size: 0.82rem; }

/* Badge */
.dBadge {
    padding: 2px 9px; border-radius: 999px; font-size: 0.72rem; font-weight: 700;
    display: inline-block; white-space: nowrap;
}
.dBadge--active     { background: color-mix(in srgb, #10b981 14%, transparent); color: #059669; }
.dBadge--failed     { background: color-mix(in srgb, #ef4444 14%, transparent); color: #dc2626; }
.dBadge--processing { background: color-mix(in srgb, #3b82f6 14%, transparent); color: #2563eb; }

/* Transcript / pre */
.dTranscript {
    margin: 0; white-space: pre-wrap; word-break: break-word;
    font-family: inherit; font-size: 0.875rem; line-height: 1.6; opacity: 0.9;
    max-height: 400px; overflow-y: auto;
    background: transparent; padding: 0; border-radius: 0;
}

/* Recovery row */
.dRecovery { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }

/* Skeleton */
.dSkeletonLines {
    display: flex; flex-direction: column; gap: 8px;
}
.dSkeletonLines::before, .dSkeletonLines::after {
    content: ''; height: 16px; border-radius: 6px;
    background: color-mix(in srgb, var(--color-text, #111) 8%, transparent);
    animation: pulse 1.2s ease-in-out infinite;
}
@keyframes pulse { 0%,100% { opacity: 1; } 50% { opacity: 0.45; } }

/* Timeline */
.dTimeline { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 0; }
.dTimeline__item { display: flex; gap: 12px; position: relative; }
.dTimeline__rail { display: flex; flex-direction: column; align-items: center; flex-shrink: 0; width: 20px; }
.dTimeline__dot {
    width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; margin-top: 4px;
    background: var(--color-primary, #3b82f6);
    border: 2px solid var(--surface, #fff);
    box-shadow: 0 0 0 2px var(--color-primary, #3b82f6);
}
.dTimeline__item:not(:last-child) .dTimeline__rail::after {
    content: ''; flex: 1; width: 2px; background: var(--border, #e5e7eb); margin-top: 6px;
}
.dTimeline__content { padding-bottom: 16px; flex: 1; min-width: 0; }
.dTimeline__top { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-bottom: 2px; }
.dTimeline__title { font-size: 0.875rem; font-weight: 600; }
.dTimeline__meta { font-size: 0.78rem; opacity: 0.55; }

/* ── Responsive ──────────────────────────────────────────────────────────── */
@media (max-width: 768px) {
    .dHeader { flex-direction: column; }
    .dHeader__stats { align-self: stretch; }
    .dStats { flex-direction: column; gap: 1rem; }
    .dKvGrid { grid-template-columns: 1fr; }
}
</style>
