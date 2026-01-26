<template>
    <BaseCard
        title="Category Breakdowns"
        description="Call distribution by category and sub-category"
        variant="glass"
    >
        <div v-if="loading" class="admin-skeletonLines">
            <div class="admin-skeleton admin-skeleton--line" />
            <div class="admin-skeleton admin-skeleton--line" />
            <div class="admin-skeleton admin-skeleton--line" />
            <div class="admin-skeleton admin-skeleton--line" />
        </div>

        <div v-else-if="!hasCategories" class="admin-empty">
            <div class="admin-empty__title">No category data</div>
            <div class="admin-empty__desc">
                No calls have been categorized for this period.
            </div>
        </div>

        <div v-else class="admin-categoryBreakdowns">
            <!-- Category Summary Table -->
            <div class="admin-reportSection">
                <h4 class="admin-reportSection__title">Category Summary</h4>
                <table class="admin-table admin-table--compact">
                    <thead>
                        <tr>
                            <th>Category</th>
                            <th class="admin-table__num">Calls</th>
                            <th class="admin-table__num">% of Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="cat in sortedCategories" :key="cat.name">
                            <td>{{ cat.name }}</td>
                            <td class="admin-table__num admin-mono">{{ formatNumber(cat.count) }}</td>
                            <td class="admin-table__num">
                                <span class="admin-percentBar">
                                    <span
                                        class="admin-percentBar__fill"
                                        :style="{ width: cat.percent + '%' }"
                                    />
                                    <span class="admin-percentBar__label">{{ cat.percent }}%</span>
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Sub-category Details -->
            <div v-if="hasSubCategories" class="admin-reportSection">
                <h4 class="admin-reportSection__title">Sub-category Details</h4>
                <div class="admin-subCategoryGrid">
                    <div
                        v-for="cat in categoriesWithSubs"
                        :key="cat.name"
                        class="admin-subCategoryCard"
                    >
                        <div class="admin-subCategoryCard__header">
                            <span class="admin-subCategoryCard__name">{{ cat.name }}</span>
                            <span class="admin-subCategoryCard__count">{{ cat.count }} calls</span>
                        </div>
                        <table class="admin-table admin-table--mini">
                            <tbody>
                                <tr v-for="sub in cat.subCategories" :key="sub.name">
                                    <td>{{ sub.name }}</td>
                                    <td class="admin-table__num admin-mono">{{ sub.count }}</td>
                                    <td class="admin-table__num">{{ sub.percent }}%</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Top DIDs -->
            <div v-if="hasTopDids" class="admin-reportSection">
                <h4 class="admin-reportSection__title">Top DIDs</h4>
                <table class="admin-table admin-table--compact">
                    <thead>
                        <tr>
                            <th>DID</th>
                            <th class="admin-table__num">Calls</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(did, idx) in breakdowns?.top_dids" :key="idx">
                            <td class="admin-mono">{{ did.did }}</td>
                            <td class="admin-table__num admin-mono">{{ formatNumber(did.calls) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Hourly Distribution -->
            <div v-if="hasHourlyData" class="admin-reportSection">
                <h4 class="admin-reportSection__title">Hourly Distribution</h4>
                <div class="admin-hourlyGrid">
                    <div
                        v-for="hour in hourlyData"
                        :key="hour.hour"
                        class="admin-hourlyCell"
                        :class="{ 'admin-hourlyCell--peak': hour.isPeak }"
                    >
                        <span class="admin-hourlyCell__hour">{{ hour.label }}</span>
                        <span class="admin-hourlyCell__count">{{ hour.count }}</span>
                    </div>
                </div>
            </div>

            <!-- Sample Calls -->
            <div v-if="hasSampleCalls" class="admin-reportSection">
                <h4 class="admin-reportSection__title">Sample Calls by Category</h4>
                <div
                    v-for="cat in categoriesWithSamples"
                    :key="cat.name"
                    class="admin-sampleCallsSection"
                >
                    <h5 class="admin-sampleCallsSection__title">{{ cat.name }}</h5>
                    <div class="admin-sampleCallsList">
                        <div
                            v-for="(sample, idx) in cat.samples"
                            :key="idx"
                            class="admin-sampleCall"
                        >
                            <div class="admin-sampleCall__meta">
                                <span class="admin-mono">{{ formatDate(sample.date) }}</span>
                                <span v-if="sample.did" class="admin-sampleCall__did">DID: {{ sample.did }}</span>
                                <span v-if="sample.src" class="admin-sampleCall__src">From: {{ sample.src }}</span>
                            </div>
                            <div class="admin-sampleCall__transcript">
                                {{ sample.transcript }}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </BaseCard>
</template>

<script setup>
import { computed } from "vue";
import { BaseCard } from "../base";

const props = defineProps({
    loading: { type: Boolean, default: false },
    breakdowns: { type: Object, default: null },
});

const hasCategories = computed(() => {
    const counts = props.breakdowns?.counts;
    return counts && Object.keys(counts).length > 0;
});

const hasSubCategories = computed(() => {
    const details = props.breakdowns?.details;
    if (!details) return false;
    return Object.values(details).some(cat =>
        cat.sub_categories && Object.keys(cat.sub_categories).length > 0
    );
});

const hasTopDids = computed(() => {
    return props.breakdowns?.top_dids?.length > 0;
});

const hasHourlyData = computed(() => {
    const dist = props.breakdowns?.hourly_distribution;
    return dist && Object.keys(dist).length > 0;
});

const hasSampleCalls = computed(() => {
    const details = props.breakdowns?.details;
    if (!details) return false;
    return Object.values(details).some(cat =>
        cat.sample_calls && cat.sample_calls.length > 0
    );
});

const totalCalls = computed(() => {
    const counts = props.breakdowns?.counts || {};
    return Object.values(counts).reduce((sum, c) => sum + c, 0);
});

const sortedCategories = computed(() => {
    const counts = props.breakdowns?.counts || {};
    const total = totalCalls.value;

    return Object.entries(counts)
        .map(([name, count]) => ({
            name,
            count,
            percent: total > 0 ? Math.round((count / total) * 1000) / 10 : 0,
        }))
        .sort((a, b) => b.count - a.count);
});

const categoriesWithSubs = computed(() => {
    const details = props.breakdowns?.details || {};

    return Object.entries(details)
        .filter(([, cat]) => cat.sub_categories && Object.keys(cat.sub_categories).length > 0)
        .map(([name, cat]) => {
            const subTotal = Object.values(cat.sub_categories).reduce((s, c) => s + c, 0);
            const subCategories = Object.entries(cat.sub_categories)
                .map(([subName, subCount]) => ({
                    name: subName,
                    count: subCount,
                    percent: subTotal > 0 ? Math.round((subCount / subTotal) * 1000) / 10 : 0,
                }))
                .sort((a, b) => b.count - a.count);

            return {
                name,
                count: cat.count,
                subCategories,
            };
        })
        .sort((a, b) => b.count - a.count);
});

const categoriesWithSamples = computed(() => {
    const details = props.breakdowns?.details || {};

    return Object.entries(details)
        .filter(([, cat]) => cat.sample_calls && cat.sample_calls.length > 0)
        .map(([name, cat]) => ({
            name,
            samples: cat.sample_calls,
        }))
        .sort((a, b) => b.samples.length - a.samples.length);
});

const hourlyData = computed(() => {
    const dist = props.breakdowns?.hourly_distribution || {};
    const maxCount = Math.max(...Object.values(dist), 1);
    const peakThreshold = maxCount * 0.7;

    return Array.from({ length: 24 }, (_, hour) => ({
        hour,
        label: formatHour(hour),
        count: dist[hour] || 0,
        isPeak: (dist[hour] || 0) >= peakThreshold,
    }));
});

function formatNumber(value) {
    const num = Number(value);
    if (!Number.isFinite(num)) return "—";
    return num.toLocaleString();
}

function formatHour(hour) {
    if (hour === 0) return "12a";
    if (hour === 12) return "12p";
    if (hour < 12) return `${hour}a`;
    return `${hour - 12}p`;
}

function formatDate(iso) {
    if (!iso) return "—";
    const date = new Date(iso);
    if (!Number.isFinite(date.getTime())) return "—";
    return date.toLocaleDateString();
}
</script>
