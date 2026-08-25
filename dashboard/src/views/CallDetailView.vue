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

const DIRECTION_LABELS: Record<string, string> = {
    inbound: "Inbound", outbound: "Outbound", internal: "Internal",
};
function directionLabel(direction: string | null | undefined): string {
    if (!direction) return "—";
    return DIRECTION_LABELS[direction] ?? direction;
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
        <!-- Mobile back bar — always visible, large tap target -->
        <button type="button" class="dMobileBack" @click="goBack">
            <svg viewBox="0 0 24 24" fill="none"><path d="M19 12H5M5 12L12 19M5 12L12 5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            Back to Calls
        </button>

        <!-- Header -->
        <header class="dHeader">
            <div class="dHeader__top">
                <button type="button" class="dBreadLink" @click="goBack">
                    <svg viewBox="0 0 24 24" fill="none"><path d="M19 12H5M5 12L12 19M5 12L12 5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    Calls
                </button>
                <span class="dBreadSep">/</span>
                <span class="dMono">{{ call?.callId || "Loading…" }}</span>
            </div>

            <div class="dHeader__row">
                <div class="dHeader__content">
                    <h1 class="dHeader__title">{{ call?.company || "Unknown Company" }}</h1>
                    <p class="dHeader__subtitle">{{ call?.from || '—' }} → {{ call?.to || '—' }} · {{ formatDate(call?.createdAt) }}</p>
                </div>

                <div class="dHeader__actions">
                    <button type="button" class="dBtn dBtn--secondary" :disabled="loading" @click="refresh">
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
            </div>

            <div class="dHeader__stats">
                <div class="dStat">
                    <span class="dDirIcon" :class="`dDirIcon--${call?.direction}`">
                        <svg v-if="call?.direction === 'outbound'" viewBox="0 0 24 24" fill="none"><path d="M19 13V5m0 0h-8m8 0-9 9" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        <svg v-else-if="call?.direction === 'internal'" viewBox="0 0 24 24" fill="none"><path d="M8 7h8M8 12h8M8 17h5" stroke="currentColor" stroke-width="2.1" stroke-linecap="round"/></svg>
                        <svg v-else viewBox="0 0 24 24" fill="none"><path d="M5 11v8m0 0h8m-8 0 9-9" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </span>
                    <div>
                        <div class="dStat__label">Direction</div>
                        <div class="dStat__value">{{ directionLabel(call?.direction) }}</div>
                    </div>
                </div>
                <div class="dStat">
                    <div class="dStat__label">Duration</div>
                    <div class="dStat__value dMono">{{ formatDuration(call?.durationSeconds) }}</div>
                </div>
                <div class="dStat">
                    <div class="dStat__label">Status</div>
                    <div class="dStat__value">
                        <span v-if="!loading && call?.status" class="dBadge" :class="badgeClass(call?.status)">
                            {{ String(call?.status || '').toUpperCase() }}
                        </span>
                        <span v-else>—</span>
                    </div>
                </div>
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
.dPage { display: flex; flex-direction: column; gap: 16px; }

/* ── Mobile back bar ─────────────────────────────────────────────────────── */
.dMobileBack {
    display: none; align-items: center; gap: 8px; height: 44px; padding: 0 14px; width: 100%;
    border-radius: 10px; border: 1px solid var(--color-border); background: var(--color-surface);
    color: var(--color-text); font-size: 0.88rem; font-weight: 600; cursor: pointer;
}
.dMobileBack svg { width: 17px; height: 17px; flex-shrink: 0; }

/* ── Header ──────────────────────────────────────────────────────────────── */
.dHeader {
    display: flex; flex-direction: column; gap: 14px;
    padding: 18px 20px;
    background: var(--color-surface); border: 1px solid var(--color-border); border-radius: 14px;
}
.dHeader__top { display: flex; align-items: center; gap: 8px; font-size: 0.82rem; color: var(--color-muted); }
.dBreadLink {
    display: inline-flex; align-items: center; gap: 5px; background: none; border: none; cursor: pointer;
    font-size: 0.82rem; color: var(--color-primary); padding: 0; font-weight: 500;
}
.dBreadLink svg { width: 14px; height: 14px; }
.dBreadLink:hover { text-decoration: underline; }
.dBreadSep { opacity: 0.5; }

.dHeader__row { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; flex-wrap: wrap; }
.dHeader__title { font-size: 1.5rem; font-weight: 700; margin: 0; letter-spacing: -0.01em; }
.dHeader__subtitle { font-size: 0.85rem; color: var(--color-muted); margin: 4px 0 0 0; }

.dHeader__actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; flex-shrink: 0; }

.dHeader__stats { display: flex; gap: 22px; flex-wrap: wrap; padding-top: 14px; border-top: 1px solid var(--color-border); }
.dStat { display: flex; align-items: center; gap: 10px; }
.dStat__label { font-size: 0.68rem; font-weight: 600; letter-spacing: 0.03em; color: var(--color-muted); text-transform: uppercase; }
.dStat__value { font-size: 0.92rem; font-weight: 600; margin-top: 2px; }

/* Direction icon (matches Calls list) */
.dDirIcon {
    width: 30px; height: 30px; border-radius: 8px; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center;
}
.dDirIcon svg { width: 15px; height: 15px; }
.dDirIcon--inbound  { background: var(--color-success-soft); color: var(--color-success); }
.dDirIcon--outbound { background: var(--color-primary-soft); color: var(--color-primary); }
.dDirIcon--internal { background: var(--color-warning-soft); color: var(--color-warning); }

/* ── Buttons ─────────────────────────────────────────────────────────────── */
.dBtn {
    display: inline-flex; align-items: center; gap: 6px; height: 36px; padding: 0 14px;
    border-radius: 9px; font-size: 0.83rem; font-weight: 600; border: none; cursor: pointer;
    white-space: nowrap; transition: filter 0.15s, opacity 0.15s;
}
.dBtn--sm { height: 30px; padding: 0 11px; font-size: 0.78rem; }
.dBtn--primary { background: var(--color-primary); color: #fff; }
.dBtn--primary:hover:not(:disabled) { filter: brightness(1.08); }
.dBtn--secondary { background: var(--color-surface); color: inherit; border: 1px solid var(--color-border-strong); }
.dBtn--secondary:hover:not(:disabled) { background: var(--color-surface-2); }
.dBtn:disabled { opacity: 0.45; cursor: not-allowed; }
.dBtn__icon { width: 14px; height: 14px; flex-shrink: 0; }

/* ── Alert ───────────────────────────────────────────────────────────────── */
.dAlert { padding: 10px 14px; border-radius: 10px; font-size: 0.9rem; }
.dAlert--error { background: var(--color-error-soft); border: 1px solid var(--color-error-soft-border); color: var(--color-error); }

/* Card */
.dCard {
    background: var(--color-surface); border: 1px solid var(--color-border);
    border-radius: 14px; padding: 20px; display: flex; flex-direction: column; gap: 12px;
}
.dCard__title { font-size: 0.98rem; font-weight: 700; margin: 0; }
.dCard__desc { font-size: 0.82rem; color: var(--color-muted); margin-top: -8px; }

/* Cards column */
.dColumns { display: flex; flex-direction: column; gap: 16px; }

/* Key-value grid */
.dKvGrid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 14px; }
.dKv { display: flex; flex-direction: column; gap: 4px; min-width: 0; }
.dKv__k { font-size: 0.7rem; font-weight: 600; color: var(--color-muted); text-transform: uppercase; letter-spacing: 0.04em; }
.dKv__v { font-size: 0.88rem; word-break: break-word; }
.dMono { font-family: var(--font-mono); font-size: 0.82rem; }

/* Badge */
.dBadge {
    padding: 3px 9px; border-radius: 999px; font-size: 0.7rem; font-weight: 700;
    display: inline-block; white-space: nowrap;
}
.dBadge--active     { background: var(--color-success-soft); color: var(--color-success); }
.dBadge--failed     { background: var(--color-error-soft); color: var(--color-error); }
.dBadge--processing { background: var(--color-primary-soft); color: var(--color-primary); }

/* Transcript / pre */
.dTranscript {
    margin: 0; white-space: pre-wrap; word-break: break-word;
    font-family: inherit; font-size: 0.88rem; line-height: 1.6; color: var(--color-text);
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
    background: color-mix(in srgb, var(--color-text) 8%, transparent);
    animation: pulse 1.2s ease-in-out infinite;
}
@keyframes pulse { 0%,100% { opacity: 1; } 50% { opacity: 0.45; } }

/* Timeline */
.dTimeline { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 0; }
.dTimeline__item { display: flex; gap: 12px; position: relative; }
.dTimeline__rail { display: flex; flex-direction: column; align-items: center; flex-shrink: 0; width: 20px; }
.dTimeline__dot {
    width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; margin-top: 4px;
    background: var(--color-primary);
    border: 2px solid var(--color-surface);
    box-shadow: 0 0 0 2px var(--color-primary);
}
.dTimeline__item:not(:last-child) .dTimeline__rail::after {
    content: ''; flex: 1; width: 2px; background: var(--color-border); margin-top: 6px;
}
.dTimeline__content { padding-bottom: 16px; flex: 1; min-width: 0; }
.dTimeline__top { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-bottom: 2px; }
.dTimeline__title { font-size: 0.875rem; font-weight: 600; }
.dTimeline__meta { font-size: 0.78rem; color: var(--color-muted); }

/* ── Responsive ──────────────────────────────────────────────────────────── */
@media (max-width: 900px) {
    .dKvGrid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}

@media (max-width: 720px) {
    .dMobileBack { display: flex; }
    .dHeader__top { display: none; }
    .dHeader { padding: 14px 16px; gap: 12px; }
    .dHeader__row { flex-direction: column; align-items: stretch; }
    .dHeader__actions { width: 100%; }
    .dHeader__actions .dBtn { flex: 1; justify-content: center; }
    .dHeader__stats { gap: 16px; }
    .dKvGrid { grid-template-columns: 1fr 1fr; gap: 12px; }
    .dCard { padding: 16px; }
}

@media (max-width: 420px) {
    .dKvGrid { grid-template-columns: 1fr; }
}
</style>
