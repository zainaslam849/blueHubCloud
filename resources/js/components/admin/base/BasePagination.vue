<template>
    <nav class="admin-pagination" aria-label="Pagination">
        <div class="admin-pagination__meta">
            <span class="admin-pagination__metaMain">{{ metaText }}</span>
            <span v-if="hint" class="admin-pagination__metaHint">{{
                hint
            }}</span>
        </div>

        <div class="admin-pagination__controls">
            <button
                type="button"
                class="admin-btn admin-btn--secondary admin-btn--sm"
                :disabled="disabled || page <= 1"
                @click="setPage(page - 1)"
                aria-label="Previous page"
            >
                Prev
            </button>

            <div
                class="admin-pagination__pages"
                role="group"
                aria-label="Page selector"
            >
                <button
                    v-for="p in pageButtons"
                    :key="p.key"
                    type="button"
                    class="admin-pagination__page"
                    :class="{
                        'is-active': p.page === page,
                        'is-ellipsis': p.type === 'ellipsis',
                    }"
                    :disabled="disabled || p.type === 'ellipsis'"
                    :aria-current="p.page === page ? 'page' : undefined"
                    @click="p.type === 'page' ? setPage(p.page) : undefined"
                >
                    {{ p.label }}
                </button>
            </div>

            <button
                type="button"
                class="admin-btn admin-btn--secondary admin-btn--sm"
                :disabled="disabled || page >= totalPages"
                @click="setPage(page + 1)"
                aria-label="Next page"
            >
                Next
            </button>
        </div>

        <div v-if="pageSizeOptions.length" class="admin-pagination__size">
            <label class="admin-pagination__sizeLabel">
                <span class="admin-pagination__sizeText">Rows</span>
                <select
                    class="admin-pagination__select"
                    :disabled="disabled"
                    :value="pageSize"
                    @change="onPageSizeChange"
                >
                    <option
                        v-for="opt in pageSizeOptions"
                        :key="opt"
                        :value="opt"
                    >
                        {{ opt }}
                    </option>
                </select>
            </label>
        </div>
    </nav>
</template>

<script setup>
import { computed } from "vue";

const props = defineProps({
    page: { type: Number, required: true },
    pageSize: { type: Number, required: true },
    total: { type: Number, required: true },

    maxButtons: { type: Number, default: 7 },

    pageSizeOptions: { type: Array, default: () => [10, 25, 50, 100] },

    disabled: { type: Boolean, default: false },

    hint: { type: String, default: "" },
});

const emit = defineEmits(["update:page", "update:pageSize", "change"]);

const totalPages = computed(() => {
    const pages = Math.ceil(props.total / props.pageSize);
    return Math.max(1, Number.isFinite(pages) ? pages : 1);
});

const metaText = computed(() => {
    const total = Math.max(0, props.total);
    if (total === 0) return "0 results";

    const start = (props.page - 1) * props.pageSize + 1;
    const end = Math.min(props.page * props.pageSize, total);

    return `${start}–${end} of ${total}`;
});

function setPage(next) {
    const clamped = Math.min(totalPages.value, Math.max(1, next));
    emit("update:page", clamped);
    emit("change", { page: clamped, pageSize: props.pageSize });
}

function onPageSizeChange(e) {
    const next = Number(e.target.value);
    const safe = Number.isFinite(next) && next > 0 ? next : props.pageSize;

    emit("update:pageSize", safe);

    // Reset to page 1 when page size changes.
    emit("update:page", 1);
    emit("change", { page: 1, pageSize: safe });
}

const pageButtons = computed(() => {
    const pages = totalPages.value;
    const current = Math.min(pages, Math.max(1, props.page));

    const max = Math.max(5, props.maxButtons);

    const buttons = [];

    function pushPage(p) {
        buttons.push({
            key: `p-${p}`,
            type: "page",
            page: p,
            label: String(p),
        });
    }

    function pushEllipsis(key) {
        buttons.push({ key, type: "ellipsis", page: null, label: "…" });
    }

    if (pages <= max) {
        for (let p = 1; p <= pages; p++) pushPage(p);
        return buttons;
    }

    pushPage(1);

    const windowSize = max - 2;
    let start = Math.max(2, current - Math.floor(windowSize / 2));
    let end = Math.min(pages - 1, start + windowSize - 1);

    start = Math.max(2, end - windowSize + 1);

    if (start > 2) pushEllipsis("e-left");

    for (let p = start; p <= end; p++) pushPage(p);

    if (end < pages - 1) pushEllipsis("e-right");

    pushPage(pages);

    return buttons;
});
</script>
