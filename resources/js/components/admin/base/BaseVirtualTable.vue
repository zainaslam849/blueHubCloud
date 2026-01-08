<template>
    <div
        ref="scrollerEl"
        class="admin-vtable"
        :class="heightClass"
        role="table"
        :aria-busy="loading ? 'true' : 'false'"
    >
        <div class="admin-vtable__head" role="rowgroup">
            <div class="admin-vtable__row admin-vtable__row--head" role="row">
                <div
                    v-for="col in columns"
                    :key="col.key"
                    class="admin-vtable__cell admin-vtable__cell--head"
                    :class="col.headerClass"
                    role="columnheader"
                >
                    <slot :name="`header-${col.key}`">{{ col.label }}</slot>
                </div>
            </div>
        </div>

        <div class="admin-vtable__body" role="rowgroup">
            <div class="admin-vtable__spacer" aria-hidden="true" />

            <div ref="windowEl" class="admin-vtable__window">
                <template v-if="loading">
                    <div
                        v-for="i in skeletonRows"
                        :key="i"
                        class="admin-vtable__row"
                        role="row"
                    >
                        <div
                            v-for="col in columns"
                            :key="col.key"
                            class="admin-vtable__cell"
                            role="cell"
                        >
                            <div
                                class="admin-skeleton admin-skeleton--line"
                                aria-hidden="true"
                            />
                        </div>
                    </div>
                </template>

                <template v-else-if="rows.length === 0">
                    <div class="admin-vtable__empty">
                        <slot name="empty">
                            <div class="admin-empty">
                                <div class="admin-empty__title">
                                    {{ emptyTitle }}
                                </div>
                                <div
                                    v-if="emptyDescription"
                                    class="admin-empty__desc"
                                >
                                    {{ emptyDescription }}
                                </div>
                            </div>
                        </slot>
                    </div>
                </template>

                <template v-else>
                    <div
                        v-for="row in visibleRows"
                        :key="getRowKey(row)"
                        class="admin-vtable__row"
                        :class="{ 'admin-vtable__row--clickable': clickable }"
                        role="row"
                        @click="onRowClick(row)"
                    >
                        <div
                            v-for="col in columns"
                            :key="col.key"
                            class="admin-vtable__cell"
                            :class="col.cellClass"
                            role="cell"
                        >
                            <slot
                                :name="`cell-${col.key}`"
                                :row="row"
                                :value="row?.[col.key]"
                            >
                                {{ row?.[col.key] ?? "—" }}
                            </slot>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </div>
</template>

<script setup>
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from "vue";

const props = defineProps({
    columns: { type: Array, required: true },
    rows: { type: Array, default: () => [] },

    rowKey: { type: [String, Function], default: "id" },

    height: {
        type: String,
        default: "md",
        validator: (v) => ["sm", "md", "lg", "xl"].includes(v),
    },

    rowHeight: { type: Number, default: 48 },
    overscan: { type: Number, default: 6 },

    loading: { type: Boolean, default: false },
    skeletonRows: { type: Number, default: 10 },

    emptyTitle: { type: String, default: "No data" },
    emptyDescription: {
        type: String,
        default: "There are no records to display.",
    },

    clickable: { type: Boolean, default: false },
});

const emit = defineEmits(["row-click", "range-change"]);

const scrollerEl = ref(null);
const windowEl = ref(null);

const scrollTop = ref(0);
const viewportHeight = ref(420);
let rafId = 0;
let resizeObs = null;

const heightClass = computed(() => {
    return {
        "admin-vtable--sm": props.height === "sm",
        "admin-vtable--md": props.height === "md",
        "admin-vtable--lg": props.height === "lg",
        "admin-vtable--xl": props.height === "xl",
    };
});

function getRowKey(row) {
    if (typeof props.rowKey === "function") return props.rowKey(row);
    return row?.[props.rowKey] ?? JSON.stringify(row);
}

function onRowClick(row) {
    if (!props.clickable) return;
    emit("row-click", row);
}

function setVars({ totalHeight, offset }) {
    if (!scrollerEl.value) return;
    scrollerEl.value.style.setProperty("--vt-total", `${totalHeight}px`);
    scrollerEl.value.style.setProperty("--vt-offset", `${offset}px`);
}

function recalc() {
    const total = props.loading ? props.skeletonRows : props.rows.length;
    const totalHeight = Math.max(0, total * props.rowHeight);

    const visibleCount = Math.max(
        1,
        Math.ceil(viewportHeight.value / props.rowHeight)
    );

    const rawStart =
        Math.floor(scrollTop.value / props.rowHeight) - props.overscan;
    const start = Math.max(0, rawStart);

    const end = Math.min(total, start + visibleCount + props.overscan * 2);
    const offset = start * props.rowHeight;

    setVars({ totalHeight, offset });
    emit("range-change", { start, end });

    return { start, end };
}

const range = ref({ start: 0, end: 0 });

const visibleRows = computed(() => {
    const { start, end } = range.value;
    return props.rows.slice(start, end);
});

function onScroll() {
    if (!scrollerEl.value) return;

    const next = scrollerEl.value.scrollTop;

    if (rafId) cancelAnimationFrame(rafId);
    rafId = requestAnimationFrame(() => {
        scrollTop.value = next;
        range.value = recalc();
    });
}

onMounted(async () => {
    await nextTick();

    if (scrollerEl.value) {
        scrollerEl.value.addEventListener("scroll", onScroll, {
            passive: true,
        });

        viewportHeight.value = scrollerEl.value.clientHeight;
    }

    if (typeof ResizeObserver !== "undefined" && scrollerEl.value) {
        resizeObs = new ResizeObserver(() => {
            viewportHeight.value =
                scrollerEl.value?.clientHeight ?? viewportHeight.value;
            range.value = recalc();
        });
        resizeObs.observe(scrollerEl.value);
    }

    range.value = recalc();
});

onBeforeUnmount(() => {
    if (rafId) cancelAnimationFrame(rafId);
    rafId = 0;

    if (scrollerEl.value) {
        scrollerEl.value.removeEventListener("scroll", onScroll);
    }

    if (resizeObs) {
        resizeObs.disconnect();
        resizeObs = null;
    }
});

watch(
    () => [props.rows.length, props.loading, props.rowHeight],
    () => {
        range.value = recalc();
    }
);
</script>
