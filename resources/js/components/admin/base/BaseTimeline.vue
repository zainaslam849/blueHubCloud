<template>
    <ol class="admin-timeline">
        <li v-for="ev in events" :key="ev.key" class="admin-timeline__item">
            <div class="admin-timeline__rail" aria-hidden="true">
                <div class="admin-timeline__dot" />
            </div>
            <div class="admin-timeline__content">
                <div class="admin-timeline__top">
                    <div class="admin-timeline__title">{{ ev.label }}</div>
                    <BaseBadge :variant="badgeVariant(ev.status)">
                        {{ String(ev.status || "").toUpperCase() }}
                    </BaseBadge>
                </div>
                <div class="admin-timeline__meta" :class="monoClass">
                    {{ formatDate(ev.occurredAt) }}
                    <span v-if="ev.detail">• {{ ev.detail }}</span>
                </div>
            </div>
        </li>
    </ol>
</template>

<script setup>
import BaseBadge from "./BaseBadge.vue";

defineProps({
    events: { type: Array, default: () => [] },
    monoClass: { type: String, default: "" },
});

function badgeVariant(status) {
    const s = String(status || "").toLowerCase();
    if (s === "completed") return "active";
    if (s === "failed") return "failed";
    return "processing";
}

function formatDate(iso) {
    const t = new Date(iso);
    const ms = t.getTime();
    if (!Number.isFinite(ms)) return "—";
    return t.toLocaleString();
}
</script>
