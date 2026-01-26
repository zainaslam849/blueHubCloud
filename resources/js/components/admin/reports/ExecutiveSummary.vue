<template>
    <BaseCard title="Executive Summary" variant="glass">
        <div v-if="loading" class="admin-skeletonLines">
            <div class="admin-skeleton admin-skeleton--line" />
            <div class="admin-skeleton admin-skeleton--line" />
            <div class="admin-skeleton admin-skeleton--line" />
        </div>

        <div v-else-if="!summary" class="admin-empty">
            <div class="admin-empty__title">No summary available</div>
            <div class="admin-empty__desc">
                Executive summary has not been generated for this report.
            </div>
        </div>

        <div v-else class="admin-executiveSummary">
            <p class="admin-executiveSummary__text">{{ summary }}</p>
        </div>
    </BaseCard>
</template>

<script setup>
import { BaseCard } from "../base";

defineProps({
    loading: { type: Boolean, default: false },
    summary: { type: String, default: "" },
});
</script>
