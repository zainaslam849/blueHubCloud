<template>
    <BaseCard
        title="Insights & Recommendations"
        description="AI opportunities and actionable recommendations"
        variant="glass"
    >
        <div v-if="loading" class="admin-skeletonLines">
            <div class="admin-skeleton admin-skeleton--line" />
            <div class="admin-skeleton admin-skeleton--line" />
            <div class="admin-skeleton admin-skeleton--line" />
        </div>

        <div v-else-if="!hasInsights" class="admin-empty">
            <div class="admin-empty__title">No insights available</div>
            <div class="admin-empty__desc">
                Not enough data to generate insights for this period.
            </div>
        </div>

        <div v-else class="admin-insightsContainer">
            <!-- AI Opportunities -->
            <div v-if="hasOpportunities" class="admin-reportSection">
                <h4 class="admin-reportSection__title">
                    <span class="admin-reportSection__icon admin-reportSection__icon--opportunity">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                            <circle cx="12" cy="12" r="10"/>
                            <path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/>
                            <line x1="12" y1="17" x2="12.01" y2="17"/>
                        </svg>
                    </span>
                    AI & Automation Opportunities
                </h4>
                <div class="admin-insightsList">
                    <div
                        v-for="(opp, idx) in insights?.ai_opportunities"
                        :key="idx"
                        class="admin-insightCard admin-insightCard--opportunity"
                    >
                        <div class="admin-insightCard__header">
                            <BaseBadge :variant="opportunityVariant(opp.type)">
                                {{ formatOpportunityType(opp.type) }}
                            </BaseBadge>
                            <span v-if="opp.category" class="admin-insightCard__category">
                                {{ opp.category }}
                            </span>
                        </div>

                        <div class="admin-insightCard__body">
                            <p class="admin-insightCard__reason">{{ opp.reason }}</p>

                            <div class="admin-insightCard__metrics">
                                <div v-if="opp.call_count" class="admin-insightMetric">
                                    <span class="admin-insightMetric__label">Calls</span>
                                    <span class="admin-insightMetric__value">{{ formatNumber(opp.call_count) }}</span>
                                </div>
                                <div v-if="opp.percentage" class="admin-insightMetric">
                                    <span class="admin-insightMetric__label">Share</span>
                                    <span class="admin-insightMetric__value">{{ opp.percentage }}%</span>
                                </div>
                                <div v-if="opp.top_sub_category" class="admin-insightMetric">
                                    <span class="admin-insightMetric__label">Top Sub-category</span>
                                    <span class="admin-insightMetric__value">
                                        {{ opp.top_sub_category }}
                                        <span class="admin-insightMetric__sub">
                                            ({{ opp.top_sub_category_count }} calls, {{ opp.top_sub_category_percentage }}%)
                                        </span>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recommendations -->
            <div v-if="hasRecommendations" class="admin-reportSection">
                <h4 class="admin-reportSection__title">
                    <span class="admin-reportSection__icon admin-reportSection__icon--recommendation">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                            <path d="M14 9V5a3 3 0 0 0-3-3l-4 9v11h11.28a2 2 0 0 0 2-1.7l1.38-9a2 2 0 0 0-2-2.3zM7 22H4a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2h3"/>
                        </svg>
                    </span>
                    Recommendations
                </h4>
                <div class="admin-recommendationsList">
                    <div
                        v-for="(rec, idx) in insights?.recommendations"
                        :key="idx"
                        class="admin-recommendationCard"
                        :class="recommendationClass(rec.type)"
                    >
                        <div class="admin-recommendationCard__icon">
                            <component :is="getRecommendationIcon(rec.type)" />
                        </div>
                        <div class="admin-recommendationCard__content">
                            <div class="admin-recommendationCard__type">
                                {{ formatRecommendationType(rec.type) }}
                            </div>
                            <p class="admin-recommendationCard__message">{{ rec.message }}</p>

                            <!-- Additional metrics for specific types -->
                            <div v-if="rec.hours" class="admin-recommendationCard__tags">
                                <span
                                    v-for="hour in rec.hours"
                                    :key="hour"
                                    class="admin-recommendationTag"
                                >
                                    {{ formatHour(hour) }}
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </BaseCard>
</template>

<script setup>
import { computed, h } from "vue";
import { BaseCard, BaseBadge } from "../base";

const props = defineProps({
    loading: { type: Boolean, default: false },
    insights: { type: Object, default: null },
});

const hasInsights = computed(() => {
    return hasOpportunities.value || hasRecommendations.value;
});

const hasOpportunities = computed(() => {
    return props.insights?.ai_opportunities?.length > 0;
});

const hasRecommendations = computed(() => {
    return props.insights?.recommendations?.length > 0;
});

function formatNumber(value) {
    const num = Number(value);
    if (!Number.isFinite(num)) return "—";
    return num.toLocaleString();
}

function formatOpportunityType(type) {
    const types = {
        automation_candidate: "Automation Candidate",
        sub_category_highlight: "Sub-category Focus",
    };
    return types[type] || type;
}

function opportunityVariant(type) {
    if (type === "automation_candidate") return "active";
    return "processing";
}

function formatRecommendationType(type) {
    const types = {
        low_answer_rate: "Answer Rate Alert",
        high_missed_calls: "Missed Calls Alert",
        peak_hours: "Peak Hours",
        after_hours_volume: "After-Hours Volume",
    };
    return types[type] || type;
}

function recommendationClass(type) {
    const classes = {
        low_answer_rate: "admin-recommendationCard--warning",
        high_missed_calls: "admin-recommendationCard--warning",
        peak_hours: "admin-recommendationCard--info",
        after_hours_volume: "admin-recommendationCard--info",
    };
    return classes[type] || "";
}

function formatHour(hour) {
    if (hour === 0) return "12am";
    if (hour === 12) return "12pm";
    if (hour < 12) return `${hour}am`;
    return `${hour - 12}pm`;
}

// Icon components for different recommendation types
function getRecommendationIcon(type) {
    const icons = {
        low_answer_rate: () => h("svg", { viewBox: "0 0 24 24", fill: "none", stroke: "currentColor", "stroke-width": "1.8" }, [
            h("path", { d: "M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" }),
            h("line", { x1: "12", y1: "9", x2: "12", y2: "13" }),
            h("line", { x1: "12", y1: "17", x2: "12.01", y2: "17" }),
        ]),
        high_missed_calls: () => h("svg", { viewBox: "0 0 24 24", fill: "none", stroke: "currentColor", "stroke-width": "1.8" }, [
            h("circle", { cx: "12", cy: "12", r: "10" }),
            h("line", { x1: "12", y1: "8", x2: "12", y2: "12" }),
            h("line", { x1: "12", y1: "16", x2: "12.01", y2: "16" }),
        ]),
        peak_hours: () => h("svg", { viewBox: "0 0 24 24", fill: "none", stroke: "currentColor", "stroke-width": "1.8" }, [
            h("circle", { cx: "12", cy: "12", r: "10" }),
            h("polyline", { points: "12 6 12 12 16 14" }),
        ]),
        after_hours_volume: () => h("svg", { viewBox: "0 0 24 24", fill: "none", stroke: "currentColor", "stroke-width": "1.8" }, [
            h("path", { d: "M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z" }),
        ]),
    };
    return icons[type] || icons.peak_hours;
}
</script>
