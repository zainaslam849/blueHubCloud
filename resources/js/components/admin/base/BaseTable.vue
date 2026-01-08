<template>
    <div v-if="virtualized" class="admin-tableWrap">
        <div v-if="caption" class="admin-table__caption">{{ caption }}</div>

        <BaseVirtualTable
            :columns="columns"
            :rows="rows"
            :row-key="rowKey"
            :height="height"
            :row-height="rowHeight"
            :overscan="overscan"
            :loading="loading"
            :skeleton-rows="skeletonRows"
            :empty-title="emptyTitle"
            :empty-description="emptyDescription"
            :clickable="clickable"
            @row-click="onRowClick"
            @range-change="$emit('range-change', $event)"
        >
            <template
                v-for="col in columns"
                :key="`h-${col.key}`"
                #[`header-${col.key}`]
            >
                <slot :name="`header-${col.key}`">{{ col.label }}</slot>
            </template>

            <template
                v-for="col in columns"
                :key="`c-${col.key}`"
                #[`cell-${col.key}`]="{ row, value }"
            >
                <slot :name="`cell-${col.key}`" :row="row" :value="value">
                    {{ value ?? "—" }}
                </slot>
            </template>

            <template #empty>
                <slot name="empty" />
            </template>
        </BaseVirtualTable>
    </div>

    <div
        v-else
        class="admin-tableWrap"
        :class="{ 'admin-tableWrap--sticky': stickyHeader }"
    >
        <table class="admin-table">
            <caption v-if="caption" class="admin-table__caption">
                {{
                    caption
                }}
            </caption>

            <thead class="admin-table__head">
                <tr>
                    <th
                        v-for="col in columns"
                        :key="col.key"
                        class="admin-table__th"
                        :class="col.headerClass"
                        scope="col"
                    >
                        <slot :name="`header-${col.key}`">{{ col.label }}</slot>
                    </th>
                </tr>
            </thead>

            <tbody v-if="loading" class="admin-table__body">
                <tr v-for="i in skeletonRows" :key="i" class="admin-table__tr">
                    <td
                        v-for="col in columns"
                        :key="col.key"
                        class="admin-table__td"
                    >
                        <div
                            class="admin-skeleton admin-skeleton--line"
                            aria-hidden="true"
                        />
                    </td>
                </tr>
            </tbody>

            <tbody v-else-if="rows.length === 0" class="admin-table__body">
                <tr class="admin-table__tr">
                    <td class="admin-table__empty" :colspan="columns.length">
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
                    </td>
                </tr>
            </tbody>

            <tbody v-else class="admin-table__body">
                <tr
                    v-for="row in rows"
                    :key="getRowKey(row)"
                    class="admin-table__tr"
                    :class="{ 'admin-table__tr--clickable': clickable }"
                    @click="onRowClick(row)"
                >
                    <td
                        v-for="col in columns"
                        :key="col.key"
                        class="admin-table__td"
                        :class="col.cellClass"
                    >
                        <slot
                            :name="`cell-${col.key}`"
                            :row="row"
                            :value="row?.[col.key]"
                        >
                            {{ row?.[col.key] ?? "—" }}
                        </slot>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>

<script setup>
import BaseVirtualTable from "./BaseVirtualTable.vue";

const props = defineProps({
    columns: {
        type: Array,
        required: true,
        // [{ key, label, headerClass?, cellClass? }]
    },

    rows: { type: Array, default: () => [] },

    rowKey: { type: [String, Function], default: "id" },

    caption: { type: String, default: "" },

    virtualized: { type: Boolean, default: false },
    height: {
        type: String,
        default: "md",
        validator: (v) => ["sm", "md", "lg", "xl"].includes(v),
    },
    rowHeight: { type: Number, default: 48 },
    overscan: { type: Number, default: 6 },

    loading: { type: Boolean, default: false },
    skeletonRows: { type: Number, default: 6 },

    stickyHeader: { type: Boolean, default: true },

    emptyTitle: { type: String, default: "No data" },
    emptyDescription: {
        type: String,
        default: "There are no records to display.",
    },

    clickable: { type: Boolean, default: false },
});

const emit = defineEmits(["row-click", "range-change"]);

function getRowKey(row) {
    if (typeof props.rowKey === "function") return props.rowKey(row);
    return row?.[props.rowKey] ?? JSON.stringify(row);
}

function onRowClick(row) {
    if (!props.clickable) return;
    emit("row-click", row);
}
</script>
