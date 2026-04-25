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
            <p class="admin-executiveSummary__text" v-html="summaryHtml"></p>
        </div>
    </BaseCard>
</template>

<script setup>
import { computed } from "vue";

import { BaseCard } from "../base";

const props = defineProps({
    loading: { type: Boolean, default: false },
    summary: { type: String, default: "" },
});

function escapeHtml(value) {
    return String(value)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/\"/g, "&quot;")
        .replace(/'/g, "&#39;");
}

const summaryHtml = computed(() => {
    const escaped = escapeHtml(props.summary || "");
    return escaped
        .replace(/\*\*(.+?)\*\*/g, "<strong>$1</strong>")
        .replace(/\n/g, "<br>");
});
</script>
