<template>
    <div class="admin-container admin-page">
        <header class="admin-page__header">
            <div>
                <p class="admin-page__kicker">Insights</p>
                <h1 class="admin-page__title">Transcription</h1>
                <p class="admin-page__subtitle">
                    Read-only viewer with speakers and time markers.
                </p>
            </div>

            <div class="admin-callsDetailHeader__actions">
                <BaseButton
                    variant="secondary"
                    size="sm"
                    :to="{ name: 'admin.transcriptions' }"
                >
                    Back to Transcriptions
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

        <div class="admin-transcriptionGrid">
            <BaseCard title="Metadata" variant="glass">
                <div v-if="loading" class="admin-skeletonLines">
                    <div class="admin-skeleton admin-skeleton--line" />
                    <div class="admin-skeleton admin-skeleton--line" />
                    <div class="admin-skeleton admin-skeleton--line" />
                </div>

                <div v-else class="admin-kvGrid">
                    <div class="admin-kv">
                        <div class="admin-kv__k">Provider</div>
                        <div class="admin-kv__v">{{ t?.provider || "—" }}</div>
                    </div>

                    <div class="admin-kv">
                        <div class="admin-kv__k">Language</div>
                        <div class="admin-kv__v admin-transcriptionMono">
                            {{ t?.language || "—" }}
                        </div>
                    </div>

                    <div class="admin-kv">
                        <div class="admin-kv__k">Duration</div>
                        <div class="admin-kv__v admin-transcriptionMono">
                            {{ formatDuration(t?.durationSeconds) }}
                        </div>
                    </div>

                    <div class="admin-kv">
                        <div class="admin-kv__k">Segments</div>
                        <div class="admin-kv__v">
                            {{ segments.length }}
                        </div>
                    </div>

                    <div class="admin-kv">
                        <div class="admin-kv__k">Created</div>
                        <div class="admin-kv__v admin-transcriptionMono">
                            {{ formatDate(t?.createdAt) }}
                        </div>
                    </div>

                    <div class="admin-kv">
                        <div class="admin-kv__k">Updated</div>
                        <div class="admin-kv__v admin-transcriptionMono">
                            {{ formatDate(t?.updatedAt) }}
                        </div>
                    </div>
                </div>

                <div v-if="!loading" class="admin-transcriptionLinks">
                    <BaseButton
                        v-if="call?.callId"
                        variant="ghost"
                        size="sm"
                        :to="{
                            name: 'admin.calls.detail',
                            params: { id: call.callId },
                        }"
                    >
                        View Call
                    </BaseButton>

                    <BaseButton
                        v-if="recording?.id"
                        variant="ghost"
                        size="sm"
                        :to="{
                            name: 'admin.recordings.detail',
                            params: { id: recording.id },
                        }"
                    >
                        View Recording
                    </BaseButton>
                </div>
            </BaseCard>

            <BaseCard
                title="Call"
                description="Association for this transcription"
                variant="glass"
            >
                <div v-if="loading" class="admin-skeletonLines">
                    <div class="admin-skeleton admin-skeleton--line" />
                    <div class="admin-skeleton admin-skeleton--line" />
                </div>

                <div v-else class="admin-kvGrid">
                    <div class="admin-kv">
                        <div class="admin-kv__k">Call ID</div>
                        <div class="admin-kv__v admin-transcriptionMono">
                            {{ call?.callId || "—" }}
                        </div>
                    </div>

                    <div class="admin-kv">
                        <div class="admin-kv__k">Company</div>
                        <div class="admin-kv__v">
                            {{ company?.name || "—" }}
                        </div>
                    </div>

                    <div class="admin-kv">
                        <div class="admin-kv__k">Direction</div>
                        <div class="admin-kv__v admin-transcriptionMono">
                            {{ call?.direction || "—" }}
                        </div>
                    </div>

                    <div class="admin-kv">
                        <div class="admin-kv__k">From</div>
                        <div class="admin-kv__v admin-transcriptionMono">
                            {{ call?.fromNumber || "—" }}
                        </div>
                    </div>

                    <div class="admin-kv">
                        <div class="admin-kv__k">To</div>
                        <div class="admin-kv__v admin-transcriptionMono">
                            {{ call?.toNumber || "—" }}
                        </div>
                    </div>
                </div>
            </BaseCard>

            <BaseCard
                title="Transcript"
                description="Scrollable viewer"
                variant="glass"
            >
                <div v-if="loading" class="admin-skeletonLines">
                    <div class="admin-skeleton admin-skeleton--line" />
                    <div class="admin-skeleton admin-skeleton--line" />
                    <div class="admin-skeleton admin-skeleton--line" />
                    <div class="admin-skeleton admin-skeleton--line" />
                </div>

                <div v-else-if="hasNoContent" class="admin-empty">
                    <div class="admin-empty__title">No transcription</div>
                    <div class="admin-empty__desc">
                        No segments or transcription text are available.
                    </div>
                </div>

                <div v-else class="admin-transcriptPanel">
                    <template v-if="segments.length">
                        <div
                            v-for="seg in segments"
                            :key="seg.id"
                            class="admin-transcriptBlock"
                        >
                            <div class="admin-transcriptBlock__top">
                                <span
                                    class="admin-speakerTag"
                                    :class="speakerClass(seg.speaker)"
                                >
                                    {{ seg.speaker || "Speaker" }}
                                </span>
                                <span class="admin-transcriptTime">
                                    {{ formatMMSS(seg.startSecond) }}–{{
                                        formatMMSS(seg.endSecond)
                                    }}
                                </span>
                            </div>
                            <pre class="admin-transcriptText">{{
                                seg.text
                            }}</pre>
                        </div>
                    </template>

                    <template v-else>
                        <div class="admin-transcriptBlock">
                            <div class="admin-transcriptBlock__top">
                                <span
                                    class="admin-speakerTag admin-speakerTag--neutral"
                                >
                                    Transcript
                                </span>
                                <span class="admin-transcriptTime">—</span>
                            </div>
                            <pre class="admin-transcriptText">{{
                                t?.text
                            }}</pre>
                        </div>
                    </template>
                </div>
            </BaseCard>
        </div>
    </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from "vue";
import { useRoute } from "vue-router";

import adminApi from "../../router/admin/api";
import { BaseButton, BaseCard } from "../../components/admin/base";

const route = useRoute();

const loading = ref(true);
const error = ref("");

const t = ref(null);
const call = ref(null);
const company = ref(null);
const recording = ref(null);
const segments = ref([]);

const transcriptionId = computed(() => String(route.params.id || "").trim());

const hasNoContent = computed(() => {
    const segs = segments.value;
    if (Array.isArray(segs) && segs.length > 0) return false;
    return !String(t.value?.text || "").trim();
});

function formatDate(iso) {
    const dt = new Date(iso);
    const ms = dt.getTime();
    if (!Number.isFinite(ms)) return "—";
    return dt.toLocaleString();
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

function formatMMSS(seconds) {
    const s = Number(seconds);
    if (!Number.isFinite(s) || s < 0) return "—";
    const mm = Math.floor(s / 60);
    const ss = Math.floor(s % 60);
    return `${mm}:${String(ss).padStart(2, "0")}`;
}

function speakerClass(label) {
    const v = String(label || "");
    let hash = 0;
    for (let i = 0; i < v.length; i += 1) {
        hash = (hash * 31 + v.charCodeAt(i)) >>> 0;
    }
    const idx = (hash % 4) + 1;
    return `admin-speakerTag--${idx}`;
}

async function fetchTranscription() {
    const id = transcriptionId.value;
    if (!id) return;

    loading.value = true;
    error.value = "";

    try {
        const res = await adminApi.get(
            `/transcriptions/${encodeURIComponent(id)}`
        );
        const payload = res?.data;

        t.value = payload?.transcription ?? null;
        call.value = payload?.call ?? null;
        company.value = payload?.company ?? null;
        recording.value = payload?.recording ?? null;
        segments.value = Array.isArray(payload?.segments)
            ? payload.segments
            : [];
    } catch (e) {
        t.value = null;
        call.value = null;
        company.value = null;
        recording.value = null;
        segments.value = [];
        error.value = "Failed to load transcription.";
    } finally {
        loading.value = false;
    }
}

function refresh() {
    fetchTranscription();
}

watch(
    () => transcriptionId.value,
    () => {
        fetchTranscription();
    }
);

onMounted(() => {
    fetchTranscription();
});
</script>
