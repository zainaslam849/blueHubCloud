<template>
    <div class="admin-container admin-page">
        <header class="admin-page__header">
            <div>
                <p class="admin-page__kicker">Library</p>
                <h1 class="admin-page__title">Recording Detail</h1>
                <p class="admin-page__subtitle">
                    Read-only overview of a single recording.
                </p>
            </div>

            <div class="admin-callsDetailHeader__actions">
                <BaseButton
                    variant="secondary"
                    size="sm"
                    :to="{ name: 'admin.recordings' }"
                >
                    Back to Recordings
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
            <BaseCard title="Recording Metadata" variant="glass">
                <div v-if="loading" class="admin-skeletonLines">
                    <div class="admin-skeleton admin-skeleton--line" />
                    <div class="admin-skeleton admin-skeleton--line" />
                    <div class="admin-skeleton admin-skeleton--line" />
                </div>

                <div v-else class="admin-kvGrid">
                    <div class="admin-kv">
                        <div class="admin-kv__k">Status</div>
                        <div class="admin-kv__v">
                            <BaseBadge
                                :variant="badgeVariant(recording?.status)"
                            >
                                {{
                                    String(
                                        recording?.rawStatus ||
                                            recording?.status ||
                                            ""
                                    ).toUpperCase() || "—"
                                }}
                            </BaseBadge>
                        </div>
                    </div>

                    <div class="admin-kv">
                        <div class="admin-kv__k">Duration</div>
                        <div class="admin-kv__v admin-recordingsMono">
                            {{ formatDuration(recording?.durationSeconds) }}
                        </div>
                    </div>

                    <div class="admin-kv">
                        <div class="admin-kv__k">Codec</div>
                        <div class="admin-kv__v admin-recordingsMono">
                            {{ recording?.codec || "—" }}
                        </div>
                    </div>

                    <div class="admin-kv">
                        <div class="admin-kv__k">Created</div>
                        <div class="admin-kv__v admin-recordingsMono">
                            {{ formatDate(recording?.createdAt) }}
                        </div>
                    </div>

                    <div class="admin-kv">
                        <div class="admin-kv__k">Updated</div>
                        <div class="admin-kv__v admin-recordingsMono">
                            {{ formatDate(recording?.updatedAt) }}
                        </div>
                    </div>

                    <div class="admin-kv">
                        <div class="admin-kv__k">Recording ID</div>
                        <div class="admin-kv__v admin-recordingsMono">
                            {{ recording?.id ?? "—" }}
                        </div>
                    </div>
                </div>

                <div
                    v-if="!loading && recording?.errorMessage"
                    class="admin-alert admin-alert--error"
                    style="margin-top: 12px"
                >
                    {{ recording.errorMessage }}
                </div>
            </BaseCard>

            <BaseCard
                title="Call Association"
                description="Where this recording belongs"
                variant="glass"
            >
                <div v-if="loading" class="admin-skeletonLines">
                    <div class="admin-skeleton admin-skeleton--line" />
                    <div class="admin-skeleton admin-skeleton--line" />
                </div>

                <div v-else class="admin-kvGrid">
                    <div class="admin-kv">
                        <div class="admin-kv__k">Call ID</div>
                        <div class="admin-kv__v admin-recordingsMono">
                            {{ call?.callId || "—" }}
                        </div>
                    </div>

                    <div class="admin-kv">
                        <div class="admin-kv__k">Call Status</div>
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
                        <div class="admin-kv__k">Company</div>
                        <div class="admin-kv__v">
                            {{ company?.name || call?.company || "—" }}
                        </div>
                    </div>

                    <div class="admin-kv">
                        <div class="admin-kv__k">Direction</div>
                        <div class="admin-kv__v admin-recordingsMono">
                            {{ call?.direction || "—" }}
                        </div>
                    </div>

                    <div class="admin-kv">
                        <div class="admin-kv__k">From</div>
                        <div class="admin-kv__v admin-recordingsMono">
                            {{ call?.fromNumber || "—" }}
                        </div>
                    </div>

                    <div class="admin-kv">
                        <div class="admin-kv__k">To</div>
                        <div class="admin-kv__v admin-recordingsMono">
                            {{ call?.toNumber || "—" }}
                        </div>
                    </div>
                </div>

                <div v-if="!loading && call?.callId" style="margin-top: 12px">
                    <BaseButton
                        variant="ghost"
                        size="sm"
                        :to="{
                            name: 'admin.calls.detail',
                            params: { id: call.callId },
                        }"
                    >
                        View Call
                    </BaseButton>
                </div>
            </BaseCard>

            <BaseCard
                title="Storage Provider"
                description="Where the audio asset is stored"
                variant="glass"
            >
                <div v-if="loading" class="admin-skeletonLines">
                    <div class="admin-skeleton admin-skeleton--line" />
                </div>

                <div v-else class="admin-kvGrid">
                    <div class="admin-kv">
                        <div class="admin-kv__k">Provider</div>
                        <div class="admin-kv__v admin-recordingsMono">
                            {{ storage?.provider || "—" }}
                        </div>
                    </div>

                    <div class="admin-kv">
                        <div class="admin-kv__k">Path</div>
                        <div class="admin-kv__v admin-recordingsMono">
                            {{ storage?.path || "—" }}
                        </div>
                    </div>

                    <div class="admin-kv">
                        <div class="admin-kv__k">File size</div>
                        <div class="admin-kv__v admin-recordingsMono">
                            {{ formatBytes(storage?.fileSizeBytes) }}
                        </div>
                    </div>

                    <div class="admin-kv">
                        <div class="admin-kv__k">Recording URL</div>
                        <div class="admin-kv__v admin-recordingsMono">
                            {{ storage?.url ? "available" : "—" }}
                        </div>
                    </div>
                </div>

                <div v-if="!loading" style="margin-top: 12px">
                    <BaseButton
                        variant="ghost"
                        size="sm"
                        :href="storage?.url || ''"
                        target="_blank"
                        rel="noreferrer"
                        :disabled="!storage?.url"
                    >
                        Open URL
                    </BaseButton>
                </div>
            </BaseCard>

            <BaseCard
                title="Transcription Status"
                description="Speech-to-text pipeline status (no transcript UI here yet)"
                variant="glass"
            >
                <div v-if="loading" class="admin-skeletonLines">
                    <div class="admin-skeleton admin-skeleton--line" />
                </div>

                <div v-else class="admin-kvGrid">
                    <div class="admin-kv">
                        <div class="admin-kv__k">Status</div>
                        <div class="admin-kv__v">
                            <BaseBadge
                                :variant="badgeVariant(transcription?.status)"
                            >
                                {{
                                    String(
                                        transcription?.status || ""
                                    ).toUpperCase() || "—"
                                }}
                            </BaseBadge>
                        </div>
                    </div>

                    <div class="admin-kv">
                        <div class="admin-kv__k">Provider</div>
                        <div class="admin-kv__v">
                            {{ transcription?.provider || "—" }}
                        </div>
                    </div>

                    <div class="admin-kv">
                        <div class="admin-kv__k">Count</div>
                        <div class="admin-kv__v">
                            {{ transcription?.count ?? 0 }}
                        </div>
                    </div>

                    <div class="admin-kv">
                        <div class="admin-kv__k">Last update</div>
                        <div class="admin-kv__v admin-recordingsMono">
                            {{ formatDate(transcription?.lastCreatedAt) }}
                        </div>
                    </div>
                </div>
            </BaseCard>

            <BaseCard
                title="Processing Timeline"
                description="Ingested → processed → stored"
                variant="glass"
            >
                <div v-if="loading" class="admin-skeletonLines">
                    <div class="admin-skeleton admin-skeleton--line" />
                    <div class="admin-skeleton admin-skeleton--line" />
                    <div class="admin-skeleton admin-skeleton--line" />
                </div>

                <div v-else-if="timeline.length === 0" class="admin-empty">
                    <div class="admin-empty__title">No timeline</div>
                    <div class="admin-empty__desc">
                        No timeline events are available for this recording.
                    </div>
                </div>

                <BaseTimeline
                    v-else
                    :events="timeline"
                    mono-class="admin-recordingsMono"
                />
            </BaseCard>

            <BaseCard
                title="Waveform (Placeholder)"
                description="Designed for a future waveform player"
                variant="glass"
            >
                <div class="admin-empty">
                    <div class="admin-empty__title">No playback yet</div>
                    <div class="admin-empty__desc">
                        This area is reserved for a waveform player.
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
import {
    BaseBadge,
    BaseButton,
    BaseCard,
    BaseTimeline,
} from "../../components/admin/base";

const route = useRoute();

const loading = ref(true);
const error = ref("");

const recording = ref(null);
const call = ref(null);
const company = ref(null);
const storage = ref(null);
const transcription = ref(null);
const timeline = ref([]);

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
            "0"
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

function formatBytes(bytes) {
    const n = Number(bytes);
    if (!Number.isFinite(n) || n <= 0) return "—";

    const units = ["B", "KB", "MB", "GB", "TB"];
    let v = n;
    let idx = 0;

    while (v >= 1024 && idx < units.length - 1) {
        v /= 1024;
        idx += 1;
    }

    return `${v.toFixed(v < 10 && idx > 0 ? 1 : 0)} ${units[idx]}`;
}

const recordingId = computed(() => String(route.params.id || "").trim());

async function fetchRecording() {
    const id = recordingId.value;
    if (!id) return;

    loading.value = true;
    error.value = "";

    try {
        const res = await adminApi.get(`/recordings/${encodeURIComponent(id)}`);
        const payload = res?.data;

        recording.value = payload?.recording ?? null;
        call.value = payload?.call ?? null;
        company.value = payload?.company ?? null;
        storage.value = payload?.storage ?? null;
        transcription.value = payload?.transcription ?? null;
        timeline.value = Array.isArray(payload?.timeline)
            ? payload.timeline
            : [];
    } catch (e) {
        recording.value = null;
        call.value = null;
        company.value = null;
        storage.value = null;
        transcription.value = null;
        timeline.value = [];
        error.value = "Failed to load recording.";
    } finally {
        loading.value = false;
    }
}

function refresh() {
    fetchRecording();
}

watch(
    () => recordingId.value,
    () => {
        fetchRecording();
    }
);

onMounted(() => {
    fetchRecording();
});
</script>
