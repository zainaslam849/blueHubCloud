<template>
    <div class="admin-container dPage">
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
                        <router-link :to="{ name: 'admin.calls' }" class="dBreadLink">Calls</router-link>
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
                        <BaseBadge v-if="!loading && call?.status" :variant="badgeVariant(call?.status)">
                            {{ String(call?.status || "").toUpperCase() }}
                        </BaseBadge>
                        <span v-else>—</span>
                    </div>
                </div>
            </div>

            <div class="dHeader__actions">
                <BaseButton variant="ghost" size="sm" :to="{ name: 'admin.calls' }" class="dActionBtn">
                    <svg viewBox="0 0 24 24" fill="none" class="dActionBtn__icon">
                        <path d="M19 12H5M5 12L12 19M5 12L12 5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    Back
                </BaseButton>
                <BaseButton variant="ghost" size="sm" :loading="loading" class="dActionBtn" @click="refresh">
                    <svg viewBox="0 0 24 24" fill="none" class="dActionBtn__icon">
                        <path d="M20 12a8 8 0 1 1-2.34-5.66" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                        <path d="M20 4v6h-6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    Refresh
                </BaseButton>
                <BaseButton
                    v-if="!loading && aiRecovery?.canRegenerate"
                    variant="primary"
                    size="sm"
                    :loading="regenerating"
                    class="dActionBtn"
                    @click="regenerateAi"
                >
                    {{ aiRecovery.actionLabel }}
                </BaseButton>
            </div>
        </header>

        <div v-if="error" class="dAlert dAlert--error">{{ error }}</div>

        <div class="dGrid">
            <!-- Hero Stats -->
            <div class="dCard dCard--hero">
                <div v-if="loading" class="dSkeletonBlock"></div>
                <div v-else class="dStats">
                    <div class="dStat">
                        <div class="dStat__icon">
                            <svg viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/><path d="M12 6V12L16 14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                        </div>
                        <div class="dStat__content">
                            <div class="dStat__label">Duration</div>
                            <div class="dStat__value">{{ formatDuration(call?.durationSeconds) }}</div>
                        </div>
                    </div>
                    <div class="dStat">
                        <div class="dStat__icon">
                            <svg viewBox="0 0 24 24" fill="none"><rect x="3" y="4" width="18" height="16" rx="2" stroke="currentColor" stroke-width="2"/><path d="M3 10h18" stroke="currentColor" stroke-width="2"/></svg>
                        </div>
                        <div class="dStat__content">
                            <div class="dStat__label">Provider</div>
                            <div class="dStat__value">{{ call?.provider ?? "—" }}</div>
                        </div>
                    </div>
                    <div class="dStat">
                        <div class="dStat__icon">
                            <svg viewBox="0 0 24 24" fill="none"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><circle cx="12" cy="7" r="4" stroke="currentColor" stroke-width="2"/></svg>
                        </div>
                        <div class="dStat__content">
                            <div class="dStat__label">Company</div>
                            <div class="dStat__value">{{ call?.company ?? "—" }}</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="dColumns">
                <!-- 1. Call Information -->
                <div class="dCard">
                    <div class="dCard__title">Call Information</div>
                    <div v-if="loading" class="dSkeletonLines"></div>
                    <div v-else class="dKvGrid">
                        <div class="dKv"><div class="dKv__k">Call ID</div><div class="dKv__v dMono">{{ call?.callId ?? "—" }}</div></div>
                        <div class="dKv"><div class="dKv__k">From Number</div><div class="dKv__v dMono">{{ call?.from ?? "—" }}</div></div>
                        <div class="dKv"><div class="dKv__k">To Number</div><div class="dKv__v dMono">{{ call?.to ?? "—" }}</div></div>
                        <div class="dKv"><div class="dKv__k">Started At</div><div class="dKv__v dMono">{{ formatDate(call?.startedAt) }}</div></div>
                        <div class="dKv"><div class="dKv__k">Created At</div><div class="dKv__v dMono">{{ formatDate(call?.createdAt) }}</div></div>
                        <div class="dKv">
                            <div class="dKv__k">Status</div>
                            <div class="dKv__v">
                                <BaseBadge :variant="badgeVariant(call?.status)">
                                    {{ String(call?.status || "").toUpperCase() || "—" }}
                                </BaseBadge>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 2. Call Summary -->
                <div class="dCard">
                    <div class="dCard__title">Call Summary</div>
                    <div class="dCard__desc">AI-generated summary</div>
                    <div v-if="loading" class="dSkeletonLines"></div>
                    <div v-else-if="!call?.aiSummary" class="dEmpty">
                        <div class="dEmpty__title">No summary yet</div>
                        <div class="dEmpty__desc">Summary will appear after AI processing completes.</div>
                    </div>
                    <div v-else class="dKvGrid">
                        <div class="dKv" style="grid-column: 1 / -1">
                            <div class="dKv__k">Summary</div>
                            <div class="dKv__v"><pre class="dTranscript">{{ call?.aiSummary || "" }}</pre></div>
                        </div>
                    </div>
                </div>

                <!-- 3. Categorization -->
                <div class="dCard">
                    <div class="dCard__title">Categorization</div>
                    <div class="dCard__desc">AI-generated category assignment</div>
                    <div v-if="loading" class="dSkeletonLines"></div>
                    <div v-else-if="!call?.category" class="dEmpty">
                        <div class="dEmpty__title">No category yet</div>
                        <div class="dEmpty__desc">This call will show its AI category after categorization completes.</div>
                    </div>
                    <div v-else class="dKvGrid">
                        <div class="dKv"><div class="dKv__k">Category</div><div class="dKv__v">{{ call?.category || "—" }}</div></div>
                        <div class="dKv"><div class="dKv__k">Sub-category</div><div class="dKv__v">{{ call?.subCategory || "—" }}</div></div>
                        <div class="dKv"><div class="dKv__k">Confidence</div><div class="dKv__v">{{ formatConfidence(call?.categoryConfidence) }}</div></div>
                    </div>
                </div>

                <!-- 4. AI Processing -->
                <div class="dCard">
                    <div class="dCard__title">AI Processing</div>
                    <div class="dCard__desc">Transcript, summary, and categorization recovery</div>
                    <div v-if="loading" class="dSkeletonLines"></div>
                    <div v-else class="dKvGrid">
                        <div class="dKv">
                            <div class="dKv__k">Transcript</div>
                            <div class="dKv__v">{{ transcription?.hasTranscription ? "Available" : "Transcript is not available for that call." }}</div>
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
                                <span>{{ aiRecovery?.statusText || "—" }}</span>
                                <BaseButton
                                    v-if="aiRecovery?.canRegenerate"
                                    variant="secondary"
                                    size="sm"
                                    :loading="regenerating"
                                    @click="regenerateAi"
                                >
                                    {{ aiRecovery.actionLabel }}
                                </BaseButton>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 5. Job History -->
                <div class="dCard">
                    <div class="dCard__title">Job History</div>
                    <div class="dCard__desc">Ingestion and transcription events</div>
                    <div v-if="loading" class="dSkeletonLines"></div>
                    <div v-else-if="jobHistory.length === 0" class="dEmpty">
                        <div class="dEmpty__title">No job history</div>
                        <div class="dEmpty__desc">No jobs have been recorded for this call.</div>
                    </div>
                    <ol v-else class="dTimeline">
                        <li v-for="ev in jobHistory" :key="ev.key" class="dTimeline__item">
                            <div class="dTimeline__rail" aria-hidden="true"><div class="dTimeline__dot"></div></div>
                            <div class="dTimeline__content">
                                <div class="dTimeline__top">
                                    <div class="dTimeline__title">{{ ev.label }}</div>
                                    <BaseBadge :variant="badgeVariant(ev.status)">
                                        {{ String(ev.status || "").toUpperCase() }}
                                    </BaseBadge>
                                </div>
                                <div class="dTimeline__meta dMono">
                                    {{ formatDate(ev.occurredAt) }}
                                    <span v-if="ev.detail"> • {{ ev.detail }}</span>
                                </div>
                            </div>
                        </li>
                    </ol>
                </div>

                <!-- 6. Transcription -->
                <div class="dCard">
                    <div class="dCard__title">Transcription</div>
                    <div class="dCard__desc">PBX-provided transcript</div>
                    <div v-if="loading" class="dSkeletonLines"></div>
                    <div v-else-if="!transcription?.hasTranscription" class="dEmpty">
                        <div class="dEmpty__title">No transcription</div>
                        <div class="dEmpty__desc">This call does not have a PBX-provided transcript.</div>
                    </div>
                    <div v-else class="dKvGrid">
                        <div class="dKv">
                            <div class="dKv__k">Status</div>
                            <div class="dKv__v"><BaseBadge variant="active">COMPLETED</BaseBadge></div>
                        </div>
                        <div class="dKv">
                            <div class="dKv__k">Provider</div>
                            <div class="dKv__v">{{ transcription?.provider ?? "pbxware" }}</div>
                        </div>
                        <div class="dKv" style="grid-column: 1 / -1">
                            <div class="dKv__k">Text</div>
                            <div class="dKv__v"><pre class="dTranscript">{{ transcription?.text || "" }}</pre></div>
                        </div>
                    </div>
                </div>

                <!-- 7. Metadata -->
                <div class="dCard">
                    <div class="dCard__title">Metadata</div>
                    <div class="dCard__desc">Identifiers and raw fields</div>
                    <div v-if="loading" class="dSkeletonLines"></div>
                    <div v-else class="dKvGrid">
                        <div v-for="row in metadataRows" :key="row.key" class="dKv">
                            <div class="dKv__k">{{ row.label }}</div>
                            <div class="dKv__v dMono">{{ row.value }}</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from "vue";
import { useRoute } from "vue-router";

import adminApi from "../../router/admin/api";
import { BaseBadge, BaseButton, BaseCard } from "../../components/admin/base";

const route = useRoute();

const loading = ref(true);
const error = ref("");
const regenerating = ref(false);

const call = ref(null);
const transcription = ref(null);
const aiRecovery = ref(null);
const jobHistory = ref([]);
const metadata = ref({});

function badgeVariant(status) {
    const s = String(status || "").toLowerCase();
    if (s === "completed") return "active";
    if (s === "failed") return "failed";
    return "processing";
}

function formatDuration(seconds) {
    const s = Number(seconds);
    if (!Number.isFinite(s) || s < 0) return "—";
    if (s === 0) return "0 seconds";

    const totalMinutes = Math.floor(s / 60);
    const secs = Math.floor(s % 60);

    const parts = [];
    if (totalMinutes > 0) {
        parts.push(
            `${totalMinutes} ${totalMinutes === 1 ? "minute" : "minutes"}`,
        );
    }
    if (secs > 0) {
        parts.push(`${secs} ${secs === 1 ? "second" : "seconds"}`);
    }

    return parts.join(" ");
}

function formatDate(iso) {
    const t = new Date(iso);
    const ms = t.getTime();
    if (!Number.isFinite(ms)) return "—";
    return t.toLocaleString();
}

function formatConfidence(value) {
    const numeric = Number(value);
    if (!Number.isFinite(numeric)) return "—";
    return `${Math.round(numeric * 100)}%`;
}

function humanizeAiStatus(status) {
    const normalized = String(status || "").toLowerCase();

    if (!normalized) return "Pending";
    if (normalized === "queued") return "Queued";
    if (normalized === "running") return "Processing";
    if (normalized === "completed") return "Completed";
    if (normalized === "credit_exhausted") return "Credit exhausted";
    if (normalized === "not_generated") return "Not generated";

    return normalized.replaceAll("_", " ");
}

async function fetchDetail() {
    const callId = route.params.callId;

    loading.value = true;
    error.value = "";

    try {
        const res = await adminApi.get(`/calls/${callId}`);
        const data = res?.data;

        call.value = data?.call ?? null;
        transcription.value = data?.transcription ?? null;
        aiRecovery.value = data?.aiRecovery ?? null;
        jobHistory.value = Array.isArray(data?.jobHistory)
            ? data.jobHistory
            : [];
        metadata.value = data?.metadata ?? {};
    } catch (e) {
        call.value = null;
        transcription.value = null;
        aiRecovery.value = null;
        jobHistory.value = [];
        metadata.value = {};

        const status = e?.response?.status;
        error.value =
            status === 404 ? "Call not found." : "Failed to load call.";
    } finally {
        loading.value = false;
    }
}

function refresh() {
    fetchDetail();
}

async function regenerateAi() {
    const callId = route.params.callId;
    if (!callId || regenerating.value || !aiRecovery.value?.canRegenerate) {
        return;
    }

    regenerating.value = true;
    error.value = "";

    try {
        await adminApi.post(`/calls/${callId}/regenerate-ai`);
        await fetchDetail();
    } catch (e) {
        error.value =
            e?.response?.data?.message ||
            "Failed to queue AI regeneration for this call.";
    } finally {
        regenerating.value = false;
    }
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
    const m = metadata.value || {};
    const c = call.value || {};

    return [
        {
            key: "company",
            label: "Company",
            value: String(c.company ?? "—"),
        },
        {
            key: "companyTimezone",
            label: "Timezone",
            value: String(m.companyTimezone ?? "—"),
        },
        {
            key: "companyStatus",
            label: "Company Status",
            value: String(m.companyStatus ?? "—").toUpperCase(),
        },
        {
            key: "provider",
            label: "Provider",
            value: String(c.provider ?? "—"),
        },
        {
            key: "pbxProviderSlug",
            label: "Provider Slug",
            value: String(m.pbxProviderSlug ?? "—"),
        },
        {
            key: "pbxUniqueId",
            label: "PBX Unique ID",
            value: String(m.pbxUniqueId ?? "—"),
        },
        {
            key: "serverId",
            label: "Server ID",
            value: String(m.serverId ?? "—"),
        },
    ];
});

watch(
    () => route.params.callId,
    () => {
        fetchDetail();
    },
);

onMounted(() => {
    fetchDetail();
});
</script>

<style scoped>
/* ── Layout ──────────────────────────────────────────────────────────────── */
.dPage { display: flex; flex-direction: column; gap: 1rem; padding-bottom: 2rem; }

/* ── Header ──────────────────────────────────────────────────────────────── */
.dHeader {
    display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; flex-wrap: wrap;
    padding: 1.25rem 1.5rem;
    background: var(--bg-glass); backdrop-filter: blur(10px);
    border: 1px solid var(--border-soft); border-radius: 14px;
    box-shadow: var(--shadow-elev-1);
}
.dHeader__left { display: flex; align-items: flex-start; gap: 1rem; }
.dHeader__icon {
    width: 44px; height: 44px; border-radius: 10px; flex-shrink: 0;
    background: var(--accent-soft); color: var(--accent-primary);
    display: grid; place-items: center;
}
.dHeader__icon svg { width: 20px; height: 20px; }
.dHeader__breadcrumb { display: flex; align-items: center; gap: 6px; font-size: 0.82rem; opacity: 0.65; margin-bottom: 4px; }
.dBreadLink { color: var(--accent-primary); text-decoration: none; font-size: 0.82rem; }
.dBreadLink:hover { text-decoration: underline; }
.dBreadSep { opacity: 0.4; }
.dHeader__title { font-size: 1.25rem; font-weight: 800; margin: 0 0 2px; }
.dHeader__subtitle { font-size: 0.85rem; opacity: 0.65; margin: 0; }

.dHeader__stats { display: flex; gap: 1rem; align-items: flex-start; }
.dHeader__stat {
    padding: 0.5rem 1rem; border-radius: 8px; text-align: center; min-width: 80px;
    background: var(--accent-soft); border: 1px solid var(--accent-border);
}
.dHeader__statLabel { font-size: 0.7rem; opacity: 0.6; text-transform: uppercase; letter-spacing: 0.05em; }
.dHeader__statValue { font-size: 1rem; font-weight: 700; margin-top: 2px; }

.dHeader__actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.dActionBtn { display: inline-flex; align-items: center; gap: 6px; }
.dActionBtn__icon { width: 14px; height: 14px; flex-shrink: 0; }

/* ── Alert ───────────────────────────────────────────────────────────────── */
.dAlert { padding: 10px 14px; border-radius: 8px; font-size: 0.9rem; }
.dAlert--error { background: color-mix(in srgb, #e53e3e 10%, transparent); border: 1px solid #e53e3e; color: #e53e3e; }

/* ── Grid ────────────────────────────────────────────────────────────────── */
.dGrid { display: flex; flex-direction: column; gap: 1rem; }

/* ── Cards ───────────────────────────────────────────────────────────────── */
.dCard {
    background: var(--bg-surface); border: 1px solid var(--border-soft);
    border-radius: 14px; padding: 1.25rem; display: flex; flex-direction: column; gap: 12px;
    box-shadow: var(--shadow-elev-1);
}
.dCard__title { font-size: 1rem; font-weight: 700; margin: 0; }
.dCard__desc { font-size: 0.82rem; opacity: 0.55; margin-top: -8px; }

/* Hero stats */
.dStats { display: flex; gap: 2rem; flex-wrap: wrap; }
.dStat { display: flex; align-items: center; gap: 12px; }
.dStat__icon {
    width: 40px; height: 40px; border-radius: 10px; flex-shrink: 0;
    background: var(--accent-soft); color: var(--accent-primary);
    display: grid; place-items: center;
}
.dStat__icon svg { width: 18px; height: 18px; }
.dStat__label { font-size: 0.72rem; opacity: 0.6; text-transform: uppercase; letter-spacing: 0.05em; }
.dStat__value { font-size: 1rem; font-weight: 700; margin-top: 2px; }

/* Cards column */
.dColumns { display: flex; flex-direction: column; gap: 1rem; }

/* Key-value grid */
.dKvGrid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 12px; }
.dKv { display: flex; flex-direction: column; gap: 4px; }
.dKv__k { font-size: 0.72rem; font-weight: 600; opacity: 0.55; text-transform: uppercase; letter-spacing: 0.04em; }
.dKv__v { font-size: 0.9rem; }
.dMono { font-family: ui-monospace, monospace; font-size: 0.82rem; }

/* Transcript / pre */
.dTranscript {
    margin: 0; white-space: pre-wrap; word-break: break-word;
    font-family: inherit; font-size: 0.875rem; line-height: 1.6; opacity: 0.9;
    max-height: 400px; overflow-y: auto;
    background: var(--bg-soft); border: 1px solid var(--border-soft);
    padding: 12px; border-radius: 8px;
}

/* Recovery row */
.dRecovery { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }

/* Empty state */
.dEmpty { padding: 1.5rem; text-align: center; }
.dEmpty__title { font-size: 0.9rem; font-weight: 600; margin-bottom: 4px; }
.dEmpty__desc { font-size: 0.82rem; opacity: 0.55; }

/* Skeleton */
.dSkeletonBlock {
    height: 60px; border-radius: 8px;
    background: var(--skeleton-bg);
    animation: dPulse 1.2s ease-in-out infinite;
}
.dSkeletonLines { display: flex; flex-direction: column; gap: 8px; }
.dSkeletonLines::before,
.dSkeletonLines::after {
    content: ''; display: block; height: 16px; border-radius: 6px;
    background: var(--skeleton-bg);
    animation: dPulse 1.2s ease-in-out infinite;
}
@keyframes dPulse { 0%,100% { opacity: 1; } 50% { opacity: 0.45; } }

/* Timeline */
.dTimeline { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; }
.dTimeline__item { display: flex; gap: 12px; position: relative; }
.dTimeline__rail { display: flex; flex-direction: column; align-items: center; flex-shrink: 0; width: 20px; }
.dTimeline__dot {
    width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; margin-top: 4px;
    background: var(--accent-primary);
    border: 2px solid var(--bg-surface);
    box-shadow: 0 0 0 2px var(--accent-primary);
}
.dTimeline__item:not(:last-child) .dTimeline__rail::after {
    content: ''; flex: 1; width: 2px; background: var(--border-soft); margin-top: 6px;
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
