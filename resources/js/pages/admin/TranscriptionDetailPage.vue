<template>
    <div class="admin-container admin-page">
        <header class="admin-transcriptionDetailHeader">
            <div class="admin-transcriptionDetailHeader__left">
                <div class="admin-transcriptionDetailHeader__icon">
                    <svg
                        viewBox="0 0 24 24"
                        fill="none"
                        xmlns="http://www.w3.org/2000/svg"
                    >
                        <path
                            d="M3 7v10a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V7"
                            stroke="currentColor"
                            stroke-width="1.5"
                        />
                        <path
                            d="M9 13h6M9 17h4"
                            stroke="currentColor"
                            stroke-width="1.5"
                            stroke-linecap="round"
                        />
                        <path
                            d="M19 3H5a2 2 0 0 0-2 2v1a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2z"
                            stroke="currentColor"
                            stroke-width="1.5"
                        />
                    </svg>
                </div>
                <div class="admin-transcriptionDetailHeader__content">
                    <div class="admin-transcriptionDetailHeader__breadcrumb">
                        <router-link
                            :to="{ name: 'admin.transcriptions' }"
                            class="admin-transcriptionDetailHeader__breadLink"
                        >
                            Transcriptions
                        </router-link>
                        <span class="admin-transcriptionDetailHeader__breadSep"
                            >/</span
                        >
                        <span>{{ transcriptionId }}</span>
                    </div>
                    <h1 class="admin-transcriptionDetailHeader__title">
                        {{ company?.name || "Unknown Company" }}
                    </h1>
                    <p class="admin-transcriptionDetailHeader__subtitle">
                        Transcript from {{ formatDate(t?.createdAt) }}
                    </p>
                </div>
            </div>

            <div class="admin-transcriptionDetailHeader__stats">
                <div class="admin-transcriptionDetailHeader__stat">
                    <div class="admin-transcriptionDetailHeader__statLabel">
                        Duration
                    </div>
                    <div class="admin-transcriptionDetailHeader__statValue">
                        {{ formatDuration(t?.durationSeconds) }}
                    </div>
                </div>
                <div class="admin-transcriptionDetailHeader__stat">
                    <div class="admin-transcriptionDetailHeader__statLabel">
                        Provider
                    </div>
                    <div class="admin-transcriptionDetailHeader__statValue">
                        {{ t?.provider || "—" }}
                    </div>
                </div>
            </div>

            <div class="admin-transcriptionDetailHeader__actions">
                <BaseButton
                    variant="ghost"
                    size="sm"
                    :to="{ name: 'admin.transcriptions' }"
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
                        <div class="admin-kv__k">Duration</div>
                        <div class="admin-kv__v admin-transcriptionMono">
                            {{ formatDuration(t?.durationSeconds) }}
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
                            {{ call?.from || "—" }}
                        </div>
                    </div>

                    <div class="admin-kv">
                        <div class="admin-kv__k">To</div>
                        <div class="admin-kv__v admin-transcriptionMono">
                            {{ call?.to || "—" }}
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
                    <div class="admin-transcriptBlock">
                        <div class="admin-transcriptBlock__top">
                            <span
                                class="admin-speakerTag admin-speakerTag--neutral"
                            >
                                Transcript
                            </span>
                            <span class="admin-transcriptTime">—</span>
                        </div>
                        <pre class="admin-transcriptText">{{ t?.text }}</pre>
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
import { BaseButton, BaseCard } from "../../components/admin/base";

const route = useRoute();

const loading = ref(true);
const error = ref("");

const t = ref(null);
const call = ref(null);
const company = ref(null);

const transcriptionId = computed(() => String(route.params.id || "").trim());

const hasNoContent = computed(() => {
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
            "0",
        )}`;
    }

    return `${mm}:${String(ss).padStart(2, "0")}`;
}

async function fetchTranscription() {
    const id = transcriptionId.value;
    if (!id) return;

    loading.value = true;
    error.value = "";

    try {
        const res = await adminApi.get(
            `/transcriptions/${encodeURIComponent(id)}`,
        );
        const payload = res?.data;

        t.value = payload?.transcription ?? null;
        call.value = payload?.call ?? null;
        company.value = payload?.company ?? null;
    } catch (e) {
        t.value = null;
        call.value = null;
        company.value = null;
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
    },
);

onMounted(() => {
    fetchTranscription();
});
</script>
