<template>
    <BaseCard
        title="Quantitative Analysis"
        description="Key performance metrics for the reporting period"
        variant="glass"
    >
        <div v-if="loading" class="admin-skeletonLines">
            <div class="admin-skeleton admin-skeleton--line" />
            <div class="admin-skeleton admin-skeleton--line" />
            <div class="admin-skeleton admin-skeleton--line" />
            <div class="admin-skeleton admin-skeleton--line" />
        </div>

        <div v-else class="admin-metricsGrid">
            <!-- Call Volume -->
            <div class="admin-metricCard">
                <div class="admin-metricCard__header">
                    <span class="admin-metricCard__icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                            <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/>
                        </svg>
                    </span>
                    <span class="admin-metricCard__label">Total Calls</span>
                </div>
                <div class="admin-metricCard__value">{{ formatNumber(metrics?.total_calls) }}</div>
            </div>

            <!-- Answered Calls -->
            <div class="admin-metricCard admin-metricCard--success">
                <div class="admin-metricCard__header">
                    <span class="admin-metricCard__icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                            <polyline points="22 4 12 14.01 9 11.01"/>
                        </svg>
                    </span>
                    <span class="admin-metricCard__label">Answered</span>
                </div>
                <div class="admin-metricCard__value">{{ formatNumber(metrics?.answered_calls) }}</div>
                <div class="admin-metricCard__sub">{{ metrics?.answer_rate || 0 }}% answer rate</div>
            </div>

            <!-- Missed Calls -->
            <div class="admin-metricCard admin-metricCard--warning">
                <div class="admin-metricCard__header">
                    <span class="admin-metricCard__icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                            <line x1="1" y1="1" x2="23" y2="23"/>
                            <path d="M16.72 11.06A10.94 10.94 0 0 1 19 12.55"/>
                            <path d="M5 12.55a10.94 10.94 0 0 1 5.17-2.39"/>
                            <path d="M10.71 5.05A16 16 0 0 1 22.58 9"/>
                            <path d="M1.42 9a15.91 15.91 0 0 1 4.7-2.88"/>
                            <path d="M8.53 16.11a6 6 0 0 1 6.95 0"/>
                            <line x1="12" y1="20" x2="12.01" y2="20"/>
                        </svg>
                    </span>
                    <span class="admin-metricCard__label">Missed</span>
                </div>
                <div class="admin-metricCard__value">{{ formatNumber(metrics?.missed_calls) }}</div>
                <div class="admin-metricCard__sub">{{ missedRate }}% of total</div>
            </div>

            <!-- Transcriptions -->
            <div class="admin-metricCard">
                <div class="admin-metricCard__header">
                    <span class="admin-metricCard__icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                            <polyline points="14 2 14 8 20 8"/>
                            <line x1="16" y1="13" x2="8" y2="13"/>
                            <line x1="16" y1="17" x2="8" y2="17"/>
                            <polyline points="10 9 9 9 8 9"/>
                        </svg>
                    </span>
                    <span class="admin-metricCard__label">Transcribed</span>
                </div>
                <div class="admin-metricCard__value">{{ formatNumber(metrics?.calls_with_transcription) }}</div>
                <div class="admin-metricCard__sub">{{ metrics?.transcription_rate || 0 }}% coverage</div>
            </div>

            <!-- Average Duration -->
            <div class="admin-metricCard">
                <div class="admin-metricCard__header">
                    <span class="admin-metricCard__icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                            <circle cx="12" cy="12" r="10"/>
                            <polyline points="12 6 12 12 16 14"/>
                        </svg>
                    </span>
                    <span class="admin-metricCard__label">Avg Duration</span>
                </div>
                <div class="admin-metricCard__value">{{ metrics?.avg_call_duration_formatted || "—" }}</div>
                <div class="admin-metricCard__sub">{{ formatDuration(metrics?.avg_call_duration_seconds) }}</div>
            </div>

            <!-- Total Duration -->
            <div class="admin-metricCard">
                <div class="admin-metricCard__header">
                    <span class="admin-metricCard__icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                            <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                            <line x1="16" y1="2" x2="16" y2="6"/>
                            <line x1="8" y1="2" x2="8" y2="6"/>
                            <line x1="3" y1="10" x2="21" y2="10"/>
                        </svg>
                    </span>
                    <span class="admin-metricCard__label">Total Duration</span>
                </div>
                <div class="admin-metricCard__value">{{ formatTotalDuration(metrics?.total_call_duration_seconds) }}</div>
            </div>
        </div>

        <!-- Time Range -->
        <div class="admin-reportTimeRange">
            <div class="admin-kv">
                <div class="admin-kv__k">First Call</div>
                <div class="admin-kv__v admin-mono">{{ formatDateTime(metrics?.first_call_at) }}</div>
            </div>
            <div class="admin-kv">
                <div class="admin-kv__k">Last Call</div>
                <div class="admin-kv__v admin-mono">{{ formatDateTime(metrics?.last_call_at) }}</div>
            </div>
        </div>
    </BaseCard>
</template>

<script setup>
import { computed } from "vue";
import { BaseCard } from "../base";

const props = defineProps({
    loading: { type: Boolean, default: false },
    metrics: { type: Object, default: null },
});

const missedRate = computed(() => {
    const total = props.metrics?.total_calls || 0;
    const missed = props.metrics?.missed_calls || 0;
    if (total === 0) return 0;
    return Math.round((missed / total) * 100 * 10) / 10;
});

function formatNumber(value) {
    const num = Number(value);
    if (!Number.isFinite(num)) return "—";
    return num.toLocaleString();
}

function formatDuration(seconds) {
    const s = Number(seconds);
    if (!Number.isFinite(s) || s < 0) return "";
    return `${s} seconds`;
}

function formatTotalDuration(seconds) {
    const s = Number(seconds);
    if (!Number.isFinite(s) || s < 0) return "—";

    const hours = Math.floor(s / 3600);
    const minutes = Math.floor((s % 3600) / 60);

    if (hours > 0) {
        return `${hours}h ${minutes}m`;
    }
    return `${minutes}m`;
}

function formatDateTime(iso) {
    if (!iso) return "—";
    const date = new Date(iso);
    if (!Number.isFinite(date.getTime())) return "—";
    return date.toLocaleString();
}
</script>
