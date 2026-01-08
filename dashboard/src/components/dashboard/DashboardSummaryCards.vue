<script setup lang="ts">
import SummaryCard from "./SummaryCard.vue";
import NoDataThisWeekState from "../states/NoDataThisWeekState.vue";

export type SummaryData = {
    totalCallsWeek: number;
    totalMinutesProcessed: number;
    reportsGenerated: number;
    currentProcessingJobs: number;
};

type Props = {
    loading?: boolean;
    data?: SummaryData | null;
};

const props = withDefaults(defineProps<Props>(), {
    loading: false,
    data: null,
});

function formatNumber(n: number) {
    return new Intl.NumberFormat().format(n);
}
</script>

<template>
    <section class="grid" aria-label="Dashboard summary">
        <template v-if="props.loading">
            <SummaryCard
                icon="calls"
                label="Total calls (this week)"
                value=""
                loading
            />
            <SummaryCard
                icon="minutes"
                label="Total minutes processed"
                value=""
                loading
            />
            <SummaryCard
                icon="generated"
                label="Reports generated"
                value=""
                loading
            />
            <SummaryCard
                icon="jobs"
                label="Current processing jobs"
                value=""
                loading
            />
        </template>

        <template v-else-if="!props.data">
            <div class="emptyWrap">
                <NoDataThisWeekState />
            </div>
        </template>

        <template v-else>
            <SummaryCard
                icon="calls"
                label="Total calls (this week)"
                :value="formatNumber(props.data.totalCallsWeek)"
            />
            <SummaryCard
                icon="minutes"
                label="Total minutes processed"
                :value="formatNumber(props.data.totalMinutesProcessed)"
            />
            <SummaryCard
                icon="generated"
                label="Reports generated"
                :value="formatNumber(props.data.reportsGenerated)"
            />
            <SummaryCard
                icon="jobs"
                label="Current processing jobs"
                :value="formatNumber(props.data.currentProcessingJobs)"
            />
        </template>
    </section>
</template>

<style scoped>
.grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: var(--space-4);
}

.emptyWrap {
    grid-column: 1 / -1;
}

@media (max-width: 960px) {
    .grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 520px) {
    .grid {
        grid-template-columns: 1fr;
    }
}
</style>
