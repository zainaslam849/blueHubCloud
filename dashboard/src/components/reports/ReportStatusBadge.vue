<script setup lang="ts">
import { computed } from "vue";
import Badge from "../ui/Badge.vue";
import type { WeeklyReportStatus } from "../../api/reports";

type Props = {
    status: WeeklyReportStatus;
};

const props = defineProps<Props>();

const normalized = computed(() => String(props.status ?? "").toLowerCase());

const ui = computed(() => {
    const s = normalized.value;

    if (["completed", "ready", "done", "generated"].includes(s)) {
        return { label: "Ready", tone: "success" as const };
    }

    if (["failed", "error"].includes(s)) {
        return { label: "Failed", tone: "danger" as const };
    }

    if (["processing", "pending", "queued", "generating"].includes(s)) {
        return { label: "Processing", tone: "warning" as const };
    }

    return { label: String(props.status), tone: "neutral" as const };
});
</script>

<template>
    <Badge :tone="ui.tone">{{ ui.label }}</Badge>
</template>
