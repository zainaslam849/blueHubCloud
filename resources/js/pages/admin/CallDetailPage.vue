<template>
    <div class="admin-container admin-page">
        <header class="admin-callDetailHeader">
            <div class="admin-callDetailHeader__left">
                <div class="admin-callDetailHeader__icon">
                    <svg
                        viewBox="0 0 24 24"
                        fill="none"
                        xmlns="http://www.w3.org/2000/svg"
                    >
                        <path
                            d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"
                            stroke="currentColor"
                            stroke-width="1.5"
                            fill="none"
                        />
                    </svg>
                </div>
                <div class="admin-callDetailHeader__content">
                    <div class="admin-callDetailHeader__breadcrumb">
                        <router-link
                            :to="{ name: 'admin.calls' }"
                            class="admin-callDetailHeader__breadLink"
                        >
                            Calls
                        </router-link>
                        <span class="admin-callDetailHeader__breadSep">/</span>
                        <span>{{ call?.callId || "Loading..." }}</span>
                    </div>
                    <h1 class="admin-callDetailHeader__title">
                        {{ call?.company || "Unknown Company" }}
                    </h1>
                    <p class="admin-callDetailHeader__subtitle">
                        Call on {{ formatDate(call?.createdAt) }}
                    </p>
                </div>
            </div>

            <div class="admin-callDetailHeader__stats">
                <div class="admin-callDetailHeader__stat">
                    <div class="admin-callDetailHeader__statLabel">
                        Duration
                    </div>
                    <div class="admin-callDetailHeader__statValue">
                        {{ formatDuration(call?.durationSeconds) }}
                    </div>
                </div>
                <div class="admin-callDetailHeader__stat">
                    <div class="admin-callDetailHeader__statLabel">Status</div>
                    <div class="admin-callDetailHeader__statValue">
                        <BaseBadge
                            v-if="!loading && call?.status"
                            :variant="badgeVariant(call?.status)"
                        >
                            {{ String(call?.status || "").toUpperCase() }}
                        </BaseBadge>
                        <span v-else>—</span>
                    </div>
                </div>
            </div>

            <div class="admin-callDetailHeader__actions">
                <BaseButton
                    variant="ghost"
                    size="sm"
                    :to="{ name: 'admin.calls' }"
                    class="admin-detailActionBtn"
                >
                    <svg
                        viewBox="0 0 24 24"
                        fill="none"
                        xmlns="http://www.w3.org/2000/svg"
                        class="admin-detailActionBtn__icon"
                    >
                        <path
                            d="M19 12H5M5 12L12 19M5 12L12 5"
                            stroke="currentColor"
                            stroke-width="2"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        />
                    </svg>
                    Back
                </BaseButton>
                <BaseButton
                    variant="ghost"
                    size="sm"
                    :loading="loading"
                    @click="refresh"
                    class="admin-detailActionBtn"
                >
                    <svg
                        viewBox="0 0 24 24"
                        fill="none"
                        xmlns="http://www.w3.org/2000/svg"
                        class="admin-detailActionBtn__icon"
                    >
                        <path
                            d="M20 12a8 8 0 1 1-2.34-5.66"
                            stroke="currentColor"
                            stroke-width="1.8"
                            stroke-linecap="round"
                        />
                        <path
                            d="M20 4v6h-6"
                            stroke="currentColor"
                            stroke-width="1.8"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        />
                    </svg>
                    Refresh
                </BaseButton>
            </div>
        </header>

        <div v-if="error" class="admin-alert admin-alert--error">
            {{ error }}
        </div>

        <div class="admin-callsDetailGrid">
            <!-- Hero Stats Card -->
            <BaseCard class="admin-callDetailHero" variant="glass">
                <div v-if="loading" class="admin-skeletonLines">
                    <div class="admin-skeleton admin-skeleton--line" />
                    <div class="admin-skeleton admin-skeleton--line" />
                </div>

                <div v-else class="admin-callDetailStats">
                    <div class="admin-callDetailStat">
                        <div class="admin-callDetailStat__icon">
                            <svg
                                viewBox="0 0 24 24"
                                fill="none"
                                xmlns="http://www.w3.org/2000/svg"
                            >
                                <circle
                                    cx="12"
                                    cy="12"
                                    r="10"
                                    stroke="currentColor"
                                    stroke-width="2"
                                />
                                <path
                                    d="M12 6V12L16 14"
                                    stroke="currentColor"
                                    stroke-width="2"
                                    stroke-linecap="round"
                                />
                            </svg>
                        </div>
                        <div class="admin-callDetailStat__content">
                            <div class="admin-callDetailStat__label">
                                Duration
                            </div>
                            <div class="admin-callDetailStat__value">
                                {{ formatDuration(call?.durationSeconds) }}
                            </div>
                        </div>
                    </div>

                    <div class="admin-callDetailStat">
                        <div class="admin-callDetailStat__icon">
                            <svg
                                viewBox="0 0 24 24"
                                fill="none"
                                xmlns="http://www.w3.org/2000/svg"
                            >
                                <rect
                                    x="3"
                                    y="4"
                                    width="18"
                                    height="16"
                                    rx="2"
                                    stroke="currentColor"
                                    stroke-width="2"
                                />
                                <path
                                    d="M3 10h18"
                                    stroke="currentColor"
                                    stroke-width="2"
                                />
                            </svg>
                        </div>
                        <div class="admin-callDetailStat__content">
                            <div class="admin-callDetailStat__label">
                                Provider
                            </div>
                            <div class="admin-callDetailStat__value">
                                {{ call?.provider ?? "—" }}
                            </div>
                        </div>
                    </div>

                    <div class="admin-callDetailStat">
                        <div class="admin-callDetailStat__icon">
                            <svg
                                viewBox="0 0 24 24"
                                fill="none"
                                xmlns="http://www.w3.org/2000/svg"
                            >
                                <path
                                    d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"
                                    stroke="currentColor"
                                    stroke-width="2"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                />
                                <circle
                                    cx="12"
                                    cy="7"
                                    r="4"
                                    stroke="currentColor"
                                    stroke-width="2"
                                />
                            </svg>
                        </div>
                        <div class="admin-callDetailStat__content">
                            <div class="admin-callDetailStat__label">
                                Company
                            </div>
                            <div class="admin-callDetailStat__value">
                                {{ call?.company ?? "—" }}
                            </div>
                        </div>
                    </div>
                </div>
            </BaseCard>

            <!-- Call Information Card -->
            <BaseCard title="Call Information" variant="glass">
                <div v-if="loading" class="admin-skeletonLines">
                    <div class="admin-skeleton admin-skeleton--line" />
                    <div class="admin-skeleton admin-skeleton--line" />
                    <div class="admin-skeleton admin-skeleton--line" />
                </div>

                <div v-else class="admin-kvGrid">
                    <div class="admin-kv">
                        <div class="admin-kv__k">Call ID</div>
                        <div class="admin-kv__v admin-callsMono">
                            {{ call?.callId ?? "—" }}
                        </div>
                    </div>

                    <div class="admin-kv">
                        <div class="admin-kv__k">From Number</div>
                        <div class="admin-kv__v admin-callsMono">
                            {{ call?.from ?? "—" }}
                        </div>
                    </div>

                    <div class="admin-kv">
                        <div class="admin-kv__k">To Number</div>
                        <div class="admin-kv__v admin-callsMono">
                            {{ call?.to ?? "—" }}
                        </div>
                    </div>

                    <div class="admin-kv">
                        <div class="admin-kv__k">Started At</div>
                        <div class="admin-kv__v admin-callsMono">
                            {{ formatDate(call?.startedAt) }}
                        </div>
                    </div>

                    <div class="admin-kv">
                        <div class="admin-kv__k">Created At</div>
                        <div class="admin-kv__v admin-callsMono">
                            {{ formatDate(call?.createdAt) }}
                        </div>
                    </div>

                    <div class="admin-kv">
                        <div class="admin-kv__k">Status</div>
                        <div class="admin-kv__v">
                            <BaseBadge :variant="badgeVariant(call?.status)">
                                {{
                                    String(call?.status || "").toUpperCase() ||
                                    "—"
                                }}
                            </BaseBadge>
                        </div>
                    </div>
                </div>
            </BaseCard>

            <BaseCard
                title="Job History"
                description="Ingestion and transcription events"
                variant="glass"
            >
                <div v-if="loading" class="admin-skeletonLines">
                    <div class="admin-skeleton admin-skeleton--line" />
                    <div class="admin-skeleton admin-skeleton--line" />
                    <div class="admin-skeleton admin-skeleton--line" />
                </div>

                <div v-else-if="jobHistory.length === 0" class="admin-empty">
                    <div class="admin-empty__title">No job history</div>
                    <div class="admin-empty__desc">
                        No jobs have been recorded for this call.
                    </div>
                </div>

                <ol v-else class="admin-timeline">
                    <li
                        v-for="ev in jobHistory"
                        :key="ev.key"
                        class="admin-timeline__item"
                    >
                        <div class="admin-timeline__rail" aria-hidden="true">
                            <div class="admin-timeline__dot" />
                        </div>
                        <div class="admin-timeline__content">
                            <div class="admin-timeline__top">
                                <div class="admin-timeline__title">
                                    {{ ev.label }}
                                </div>
                                <BaseBadge :variant="badgeVariant(ev.status)">
                                    {{ String(ev.status || "").toUpperCase() }}
                                </BaseBadge>
                            </div>
                            <div class="admin-timeline__meta admin-callsMono">
                                {{ formatDate(ev.occurredAt) }}
                                <span v-if="ev.detail">• {{ ev.detail }}</span>
                            </div>
                        </div>
                    </li>
                </ol>
            </BaseCard>

            <BaseCard
                title="Transcription"
                description="PBX-provided transcript"
                variant="glass"
            >
                <div v-if="loading" class="admin-skeletonLines">
                    <div class="admin-skeleton admin-skeleton--line" />
                </div>

                <div
                    v-else-if="!transcription?.hasTranscription"
                    class="admin-empty"
                >
                    <div class="admin-empty__title">No transcription</div>
                    <div class="admin-empty__desc">
                        This call does not have a PBX-provided transcript.
                    </div>
                </div>

                <div v-else class="admin-kvGrid">
                    <div class="admin-kv">
                        <div class="admin-kv__k">Status</div>
                        <div class="admin-kv__v">
                            <BaseBadge variant="active">COMPLETED</BaseBadge>
                        </div>
                    </div>

                    <div class="admin-kv">
                        <div class="admin-kv__k">Provider</div>
                        <div class="admin-kv__v">
                            {{ transcription?.provider ?? "pbxware" }}
                        </div>
                    </div>

                    <div class="admin-kv" style="grid-column: 1 / -1">
                        <div class="admin-kv__k">Text</div>
                        <div class="admin-kv__v">
                            <pre class="admin-transcriptText">{{
                                transcription?.text || ""
                            }}</pre>
                        </div>
                    </div>
                </div>
            </BaseCard>

            <BaseCard
                title="Call Summary"
                description="AI-generated summary"
                variant="glass"
            >
                <div v-if="loading" class="admin-skeletonLines">
                    <div class="admin-skeleton admin-skeleton--line" />
                    <div class="admin-skeleton admin-skeleton--line" />
                </div>

                <div v-else-if="!call?.aiSummary" class="admin-empty">
                    <div class="admin-empty__title">No summary yet</div>
                    <div class="admin-empty__desc">
                        Summary will appear after AI processing completes.
                    </div>
                </div>

                <div v-else class="admin-kvGrid">
                    <div class="admin-kv" style="grid-column: 1 / -1">
                        <div class="admin-kv__k">Summary</div>
                        <div class="admin-kv__v">
                            <pre class="admin-transcriptText">{{
                                call?.aiSummary || ""
                            }}</pre>
                        </div>
                    </div>
                </div>
            </BaseCard>

            <BaseCard
                title="Metadata"
                description="Identifiers and raw fields"
                variant="glass"
            >
                <div v-if="loading" class="admin-skeletonLines">
                    <div class="admin-skeleton admin-skeleton--line" />
                    <div class="admin-skeleton admin-skeleton--line" />
                    <div class="admin-skeleton admin-skeleton--line" />
                </div>

                <div v-else class="admin-kvGrid">
                    <div
                        v-for="row in metadataRows"
                        :key="row.key"
                        class="admin-kv"
                    >
                        <div class="admin-kv__k">{{ row.label }}</div>
                        <div class="admin-kv__v admin-callsMono">
                            {{ row.value }}
                        </div>
                    </div>
                </div>
            </BaseCard>
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

const call = ref(null);
const transcription = ref(null);
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

async function fetchDetail() {
    const callId = route.params.callId;

    loading.value = true;
    error.value = "";

    try {
        const res = await adminApi.get(`/calls/${callId}`);
        const data = res?.data;

        call.value = data?.call ?? null;
        transcription.value = data?.transcription ?? null;
        jobHistory.value = Array.isArray(data?.jobHistory)
            ? data.jobHistory
            : [];
        metadata.value = data?.metadata ?? {};
    } catch (e) {
        call.value = null;
        transcription.value = null;
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
    () => route.params.id,
    () => {
        fetchDetail();
    },
);

onMounted(() => {
    fetchDetail();
});
</script>
