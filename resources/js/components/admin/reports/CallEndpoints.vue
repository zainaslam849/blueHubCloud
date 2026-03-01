<template>
    <BaseCard
        title="Call Endpoints (From / To)"
        description="Real From/To values returned by PBX API for calls in this report"
        variant="glass"
    >
        <div v-if="loading" class="admin-skeletonLines">
            <div class="admin-skeleton admin-skeleton--line" />
            <div class="admin-skeleton admin-skeleton--line" />
            <div class="admin-skeleton admin-skeleton--line" />
        </div>

        <div v-else-if="!rows.length" class="admin-empty">
            <div class="admin-empty__title">No From/To endpoint data yet</div>
            <div class="admin-empty__desc">
                This report currently has no calls with non-null From/To fields.
            </div>
        </div>

        <table v-else class="admin-table admin-table--compact">
            <thead>
                <tr>
                    <th>Started At</th>
                    <th>From</th>
                    <th>To</th>
                    <th>Status</th>
                    <th class="admin-table__num">Duration (s)</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="row in rows" :key="row.call_id">
                    <td>{{ formatDateTime(row.started_at) }}</td>
                    <td>{{ row.from || "—" }}</td>
                    <td>{{ row.to || "—" }}</td>
                    <td>{{ row.status || "—" }}</td>
                    <td class="admin-table__num admin-mono">
                        {{ Number(row.duration_seconds || 0) }}
                    </td>
                </tr>
            </tbody>
        </table>
    </BaseCard>
</template>

<script setup>
import { computed } from "vue";
import { BaseCard } from "../base";

const props = defineProps({
    loading: { type: Boolean, default: false },
    endpoints: { type: Array, default: () => [] },
});

const rows = computed(() =>
    Array.isArray(props.endpoints) ? props.endpoints : [],
);

function formatDateTime(value) {
    if (!value) return "—";

    const dt = new Date(value);
    if (Number.isNaN(dt.getTime())) return "—";

    return dt.toLocaleString();
}
</script>
