<template>
    <BaseCard title="Report Information" variant="glass">
        <div v-if="loading" class="admin-skeletonLines">
            <div class="admin-skeleton admin-skeleton--line" />
            <div class="admin-skeleton admin-skeleton--line" />
            <div class="admin-skeleton admin-skeleton--line" />
        </div>

        <div v-else class="admin-kvGrid admin-kvGrid--3col">
            <div class="admin-kv">
                <div class="admin-kv__k">Company</div>
                <div class="admin-kv__v">
                    {{ header?.company?.name || "—" }}
                </div>
            </div>

            <div class="admin-kv">
                <div class="admin-kv__k">PBX Account</div>
                <div class="admin-kv__v">
                    {{ header?.pbx_account?.name || "—" }}
                </div>
            </div>

            <div class="admin-kv">
                <div class="admin-kv__k">Report ID</div>
                <div class="admin-kv__v admin-mono">
                    #{{ header?.id || "—" }}
                </div>
            </div>

            <div class="admin-kv">
                <div class="admin-kv__k">Week Range</div>
                <div class="admin-kv__v">
                    {{ header?.week_range?.formatted || "—" }}
                </div>
            </div>

            <div class="admin-kv">
                <div class="admin-kv__k">Period</div>
                <div class="admin-kv__v admin-mono">
                    {{ formatDateRange(header?.week_range) }}
                </div>
            </div>

            <div class="admin-kv">
                <div class="admin-kv__k">Status</div>
                <div class="admin-kv__v">
                    <BaseBadge :variant="statusVariant(header?.status)">
                        {{ (header?.status || "pending").toUpperCase() }}
                    </BaseBadge>
                </div>
            </div>
        </div>
    </BaseCard>
</template>

<script setup>
import { BaseCard, BaseBadge } from "../base";

defineProps({
    loading: { type: Boolean, default: false },
    header: { type: Object, default: null },
});

function formatDateRange(range) {
    if (!range?.start || !range?.end) return "—";
    return `${range.start} to ${range.end}`;
}

function statusVariant(status) {
    const s = String(status || "").toLowerCase();
    if (s === "completed") return "active";
    if (s === "failed") return "failed";
    return "processing";
}
</script>
