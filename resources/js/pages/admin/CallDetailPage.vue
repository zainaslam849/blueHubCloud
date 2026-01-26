<template>
    <div class="admin-container admin-page">
        <header class="admin-page__header">
            <div>
                <p class="admin-page__kicker">Operations</p>
                <h1 class="admin-page__title">Call Detail</h1>
                <p class="admin-page__subtitle">
                    Read-only overview of a single call.
                </p>
            </div>

            <div class="admin-callsDetailHeader__actions">
                <BaseButton
                    variant="secondary"
                    size="sm"
                    :to="{ name: 'admin.calls' }"
                >
                    Back to Calls
                </BaseButton>
                <BaseButton
                    variant="secondary"
                    size="sm"
                    :loading="loading"
                    @click="refresh"
                >
                    Refresh
                </BaseButton>
            </div>
        </header>

        <div v-if="error" class="admin-alert admin-alert--error">
            {{ error }}
        </div>

        <div class="admin-callsDetailGrid">
            <BaseCard title="Summary" variant="glass">
                <div v-if="loading" class="admin-skeletonLines">
                    <div class="admin-skeleton admin-skeleton--line" />
                    <div class="admin-skeleton admin-skeleton--line" />
                    <div class="admin-skeleton admin-skeleton--line" />
                </div>

                <div v-else class="admin-kvGrid">
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

                    <div class="admin-kv">
                        <div class="admin-kv__k">Duration</div>
                        <div class="admin-kv__v admin-callsMono">
                            {{ formatDuration(call?.durationSeconds) }}
                        </div>
                    </div>

                    <div class="admin-kv">
                        <div class="admin-kv__k">Provider</div>
                        <div class="admin-kv__v">
                            {{ call?.provider ?? "—" }}
                        </div>
                    </div>

                    <div class="admin-kv">
                        <div class="admin-kv__k">Company</div>
                        <div class="admin-kv__v">
                            {{ call?.company ?? "—" }}
                        </div>
                    </div>

                    <div class="admin-kv">
                        <div class="admin-kv__k">Created</div>
                        <div class="admin-kv__v admin-callsMono">
                            {{ formatDate(call?.createdAt) }}
                        </div>
                    </div>

                    <div class="admin-kv">
                        <div class="admin-kv__k">Call ID</div>
                        <div class="admin-kv__v admin-callsMono">
                            {{ call?.callId ?? "—" }}
                        </div>
                    </div>
                </div>
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

    const hh = Math.floor(s / 3600);
    const mm = Math.floor((s % 3600) / 60);
    const ss = Math.floor(s % 60);

    if (hh > 0) {
        return `${hh}:${String(mm).padStart(2, "0")}:${String(ss).padStart(
            2,
            "0",
        )}`;
    }

    return `${mm}:${String(ss).padStart(2, "0")}`;
}

function formatDate(iso) {
    const t = new Date(iso);
    const ms = t.getTime();
    if (!Number.isFinite(ms)) return "—";
    return t.toLocaleString();
}

async function fetchDetail() {
    const id = route.params.id;

    loading.value = true;
    error.value = "";

    try {
        const res = await adminApi.get(`/calls/${id}`);
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

    return [
        {
            key: "companyId",
            label: "Company ID",
            value: String(m.companyId ?? "—"),
        },
        {
            key: "companyTimezone",
            label: "Company TZ",
            value: String(m.companyTimezone ?? "—"),
        },
        {
            key: "companyStatus",
            label: "Company status",
            value: String(m.companyStatus ?? "—"),
        },
        {
            key: "pbxAccountId",
            label: "PBX account",
            value: String(m.pbxAccountId ?? "—"),
        },
        {
            key: "pbxProviderId",
            label: "PBX provider ID",
            value: String(m.pbxProviderId ?? "—"),
        },
        {
            key: "pbxProviderSlug",
            label: "PBX provider slug",
            value: String(m.pbxProviderSlug ?? "—"),
        },
        {
            key: "pbxUniqueId",
            label: "PBX unique ID",
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
