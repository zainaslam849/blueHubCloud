<script setup lang="ts">
import { computed, ref, watch } from "vue";
import AsyncStatusBadge from "../status/AsyncStatusBadge.vue";
import NoReportsState from "../states/NoReportsState.vue";

type ReportStatus = "processing" | "ready" | "failed";

export type ReportRow = {
    id: string;
    week: string;
    status: ReportStatus;
    pdfUrl?: string;
    csvUrl?: string;
};

type Props = {
    rows: ReportRow[];
    loading?: boolean;
    pageSize?: number;
};

const props = withDefaults(defineProps<Props>(), {
    loading: false,
    pageSize: 8,
});

const page = ref(1);

const totalPages = computed(() => {
    if (props.loading) return 1;
    const size = Math.max(1, props.pageSize);
    return Math.max(1, Math.ceil(props.rows.length / size));
});

watch(
    () => [props.rows.length, props.pageSize, props.loading] as const,
    () => {
        if (page.value > totalPages.value) page.value = totalPages.value;
        if (page.value < 1) page.value = 1;
    }
);

const pagedRows = computed(() => {
    if (props.loading) return [] as ReportRow[];
    const size = Math.max(1, props.pageSize);
    const start = (page.value - 1) * size;
    return props.rows.slice(start, start + size);
});

const pageStartEnd = computed(() => {
    if (props.loading || props.rows.length === 0) return null;
    const size = Math.max(1, props.pageSize);
    const start = (page.value - 1) * size + 1;
    const end = Math.min(props.rows.length, start + size - 1);
    return { start, end, total: props.rows.length };
});

const visiblePages = computed(() => {
    const max = totalPages.value;
    const current = page.value;

    const windowSize = 5;
    const half = Math.floor(windowSize / 2);

    let start = Math.max(1, current - half);
    let end = Math.min(max, start + windowSize - 1);
    start = Math.max(1, end - windowSize + 1);

    const pages: number[] = [];
    for (let i = start; i <= end; i++) pages.push(i);
    return pages;
});

function statusTooltip(status: ReportStatus) {
    if (status === "processing") {
        return "This report is generating. Exports unlock automatically when ready.";
    }
    if (status === "ready") {
        return "This report is ready. PDF/CSV exports are available.";
    }
    return "This report failed to generate. Retry later or contact support.";
}

function canDownload(status: ReportStatus, url?: string) {
    if (!url) return false;
    return status === "ready";
}

function prev() {
    page.value = Math.max(1, page.value - 1);
}

function next() {
    page.value = Math.min(totalPages.value, page.value + 1);
}
</script>

<template>
    <div class="wrap">
        <div class="head">
            <div class="titleBlock">
                <div class="title">Weekly reports</div>
                <div class="sub">Exports and status (UI-only)</div>
            </div>

            <div class="meta" v-if="pageStartEnd">
                {{ pageStartEnd.start }}–{{ pageStartEnd.end }} of
                {{ pageStartEnd.total }}
            </div>
        </div>

        <div v-if="props.loading" class="table" aria-busy="true">
            <div class="tr th" role="row">
                <div class="td" role="columnheader">Week</div>
                <div class="td" role="columnheader">Status</div>
                <div class="td" role="columnheader">Actions</div>
            </div>

            <div v-for="i in 6" :key="i" class="tr skRow" role="row">
                <div class="td">
                    <span class="sk sk--week" aria-hidden="true"></span>
                </div>
                <div class="td">
                    <span class="sk sk--badge" aria-hidden="true"></span>
                </div>
                <div class="td actions">
                    <span class="sk sk--btn" aria-hidden="true"></span>
                    <span class="sk sk--btn" aria-hidden="true"></span>
                </div>
            </div>
        </div>

        <NoReportsState v-else-if="props.rows.length === 0" />

        <div v-else class="table" role="table" aria-label="Weekly reports">
            <div class="tr th" role="row">
                <div class="td" role="columnheader">Week</div>
                <div class="td" role="columnheader">Status</div>
                <div class="td" role="columnheader">Actions</div>
            </div>

            <div v-for="r in pagedRows" :key="r.id" class="tr" role="row">
                <div class="td" role="cell">
                    <div class="week">{{ r.week }}</div>
                </div>

                <div class="td" role="cell">
                    <AsyncStatusBadge
                        :status="r.status"
                        :tooltip="statusTooltip(r.status)"
                    />
                </div>

                <div class="td actions" role="cell">
                    <a
                        v-if="canDownload(r.status, r.pdfUrl)"
                        class="btn btn--secondary"
                        :href="r.pdfUrl"
                        target="_blank"
                        rel="noreferrer"
                    >
                        Download PDF
                    </a>
                    <button
                        v-else
                        class="btn btn--secondary"
                        type="button"
                        disabled
                    >
                        Download PDF
                    </button>

                    <a
                        v-if="canDownload(r.status, r.csvUrl)"
                        class="btn"
                        :href="r.csvUrl"
                        target="_blank"
                        rel="noreferrer"
                    >
                        Download CSV
                    </a>
                    <button v-else class="btn" type="button" disabled>
                        Download CSV
                    </button>
                </div>
            </div>
        </div>

        <div
            v-if="!props.loading && props.rows.length > 0"
            class="pager"
            aria-label="Pagination"
        >
            <button
                class="btn btn--ghost"
                type="button"
                :disabled="page === 1"
                @click="prev"
            >
                Prev
            </button>

            <button
                v-for="p in visiblePages"
                :key="p"
                class="btn btn--ghost pageBtn"
                type="button"
                :class="{ active: p === page }"
                @click="page = p"
            >
                {{ p }}
            </button>

            <button
                class="btn btn--ghost"
                type="button"
                :disabled="page === totalPages"
                @click="next"
            >
                Next
            </button>
        </div>
    </div>
</template>

<style scoped>
.wrap {
    display: grid;
    gap: var(--space-4);
}

.head {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    gap: var(--space-4);
}

.title {
    font-weight: 850;
    letter-spacing: 0.2px;
}

.sub {
    margin-top: 4px;
    font-size: var(--text-sm);
    color: var(--color-muted);
}

.meta {
    font-size: var(--text-sm);
    color: var(--color-muted);
    white-space: nowrap;
}

.table {
    display: grid;
    gap: 10px;
}

.tr {
    display: grid;
    grid-template-columns: 2fr 1fr 1.6fr;
    gap: var(--space-3);
    padding: 12px 12px;
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    background: var(--surface);
    box-shadow: var(--shadow-sm);
    transition: background var(--duration-fast) var(--ease-standard),
        border-color var(--duration-fast) var(--ease-standard),
        box-shadow var(--duration-fast) var(--ease-standard),
        transform var(--duration-fast) var(--ease-standard);
}

.tr:hover {
    background: color-mix(in srgb, var(--color-text) 2.5%, var(--surface));
    border-color: color-mix(in srgb, var(--color-text) 16%, var(--border));
    box-shadow: var(--shadow-md);
    transform: translateY(-1px);
}

.tr.th {
    background: var(--surface-2);
    box-shadow: none;
    transform: none;
    font-weight: 800;
}

.tr.th:hover {
    background: var(--surface-2);
    border-color: var(--border);
    box-shadow: none;
    transform: none;
}

.week {
    font-weight: 750;
}

.td {
    min-width: 0;
    display: flex;
    align-items: center;
}

.actions {
    justify-content: flex-end;
    gap: var(--space-2);
    flex-wrap: wrap;
}

.pager {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: var(--space-2);
    flex-wrap: wrap;
}

.pageBtn.active {
    border-color: color-mix(in srgb, var(--color-primary) 30%, var(--border));
    background: color-mix(in srgb, var(--color-primary) 8%, var(--surface));
}

/* Skeleton */
.skRow:hover {
    background: var(--surface);
    border-color: var(--border);
    box-shadow: var(--shadow-sm);
    transform: none;
}

.sk {
    display: inline-block;
    border-radius: 999px;
    background: color-mix(in srgb, var(--color-text) 10%, transparent);
    position: relative;
    overflow: hidden;
}

.sk::after {
    content: "";
    position: absolute;
    inset: 0;
    transform: translateX(-100%);
    background: linear-gradient(
        90deg,
        transparent,
        color-mix(in srgb, var(--color-text) 14%, transparent),
        transparent
    );
    animation: shimmer 1.1s var(--ease-standard) infinite;
}

@keyframes shimmer {
    100% {
        transform: translateX(100%);
    }
}

.sk--week {
    width: 220px;
    height: 14px;
}

.sk--badge {
    width: 90px;
    height: 14px;
}

.sk--btn {
    width: 128px;
    height: 34px;
    border-radius: var(--radius-md);
}

@media (max-width: 960px) {
    .tr {
        grid-template-columns: 1fr;
    }

    .tr.th {
        display: none;
    }

    .actions {
        justify-content: flex-start;
    }

    .pager {
        justify-content: flex-start;
    }
}

@media (prefers-reduced-motion: reduce) {
    .tr,
    .tr:hover {
        transition: none;
        transform: none;
    }

    .sk::after {
        animation: none;
    }
}
</style>
