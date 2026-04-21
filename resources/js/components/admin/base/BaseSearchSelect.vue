<template>
    <div ref="rootRef" class="admin-searchSelect" :class="{ 'is-open': isOpen }">
        <button
            :id="id"
            type="button"
            class="admin-input admin-input--select admin-searchSelect__trigger"
            :disabled="disabled"
            @click="toggleOpen"
        >
            <span class="admin-searchSelect__label">{{ selectedLabel }}</span>
            <span class="admin-searchSelect__chevron" aria-hidden="true">▾</span>
        </button>

        <div v-if="isOpen" class="admin-searchSelect__panel">
            <div v-if="showSearch" class="admin-searchSelect__searchWrap">
                <input
                    ref="searchInputRef"
                    v-model="searchQuery"
                    type="search"
                    class="admin-input admin-searchSelect__search"
                    :placeholder="searchPlaceholder"
                    autocomplete="off"
                    @keydown.esc.prevent="close"
                />
            </div>

            <div class="admin-searchSelect__listWrap">
                <button
                    v-for="option in filteredOptions"
                    :key="String(option.value) + option.label"
                    type="button"
                    class="admin-searchSelect__option"
                    :class="{ 'is-selected': isValueSelected(option.value) }"
                    @click="selectOption(option)"
                >
                    {{ option.label }}
                </button>

                <div
                    v-if="filteredOptions.length === 0"
                    class="admin-searchSelect__empty"
                >
                    No results found.
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from "vue";

const props = defineProps({
    modelValue: {
        type: [String, Number, Boolean, null],
        default: "",
    },
    options: {
        type: Array,
        default: () => [],
    },
    placeholder: {
        type: String,
        default: "Select",
    },
    searchPlaceholder: {
        type: String,
        default: "Search...",
    },
    disabled: {
        type: Boolean,
        default: false,
    },
    searchable: {
        type: Boolean,
        default: true,
    },
    searchThreshold: {
        type: Number,
        default: 7,
    },
    id: {
        type: String,
        default: undefined,
    },
});

const emit = defineEmits(["update:modelValue", "change"]);

const rootRef = ref(null);
const searchInputRef = ref(null);
const isOpen = ref(false);
const searchQuery = ref("");

const normalizedOptions = computed(() => {
    return (props.options || []).map((item) => {
        if (item && typeof item === "object") {
            return {
                value: Object.prototype.hasOwnProperty.call(item, "value")
                    ? item.value
                    : item.id,
                label: String(
                    Object.prototype.hasOwnProperty.call(item, "label")
                        ? item.label
                        : item.name,
                ),
            };
        }

        return {
            value: item,
            label: String(item ?? ""),
        };
    });
});

const showSearch = computed(() => {
    return props.searchable && normalizedOptions.value.length >= props.searchThreshold;
});

const filteredOptions = computed(() => {
    const q = searchQuery.value.trim().toLowerCase();
    if (!q) return normalizedOptions.value;

    return normalizedOptions.value.filter((option) =>
        option.label.toLowerCase().includes(q),
    );
});

function valuesEqual(a, b) {
    if (a === b) return true;
    if (a === null || a === undefined || b === null || b === undefined) {
        return false;
    }

    return String(a) === String(b);
}

const selectedOption = computed(() => {
    return normalizedOptions.value.find((option) =>
        valuesEqual(option.value, props.modelValue),
    );
});

const selectedLabel = computed(() => {
    return selectedOption.value?.label || props.placeholder;
});

function isValueSelected(value) {
    return valuesEqual(value, props.modelValue);
}

function close() {
    isOpen.value = false;
    searchQuery.value = "";
}

function toggleOpen() {
    if (props.disabled) return;

    isOpen.value = !isOpen.value;
}

function selectOption(option) {
    emit("update:modelValue", option.value);
    emit("change", option.value);
    close();
}

function handleDocumentClick(event) {
    if (!isOpen.value) return;

    const target = event.target;
    if (!rootRef.value || !(target instanceof Node)) return;
    if (rootRef.value.contains(target)) return;

    close();
}

watch(isOpen, async (opened) => {
    if (!opened || !showSearch.value) return;

    await nextTick();
    searchInputRef.value?.focus();
});

onMounted(() => {
    document.addEventListener("click", handleDocumentClick);
});

onBeforeUnmount(() => {
    document.removeEventListener("click", handleDocumentClick);
});
</script>

<style scoped>
.admin-searchSelect {
    position: relative;
}

.admin-searchSelect__trigger {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    width: 100%;
    text-align: left;
}

.admin-searchSelect__label {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.admin-searchSelect__chevron {
    font-size: 12px;
    line-height: 1;
    color: var(--text-muted);
    transition: transform 160ms ease;
}

.admin-searchSelect.is-open .admin-searchSelect__chevron {
    transform: rotate(180deg);
}

.admin-searchSelect__panel {
    position: absolute;
    left: 0;
    right: 0;
    top: calc(100% + 6px);
    border: 1px solid var(--border-soft);
    border-radius: 12px;
    background: var(--bg-surface);
    box-shadow: 0 16px 28px rgba(15, 23, 42, 0.12);
    z-index: 50;
    overflow: hidden;
}

.admin-searchSelect__searchWrap {
    padding: 8px;
    border-bottom: 1px solid var(--border-soft);
    background: var(--bg-faint);
}

.admin-searchSelect__search {
    height: 38px;
}

.admin-searchSelect__listWrap {
    max-height: 280px;
    overflow: auto;
    padding: 4px;
}

.admin-searchSelect__option {
    width: 100%;
    border: 0;
    background: transparent;
    color: var(--text-primary);
    text-align: left;
    border-radius: 8px;
    padding: 9px 10px;
    font-size: 13px;
    cursor: pointer;
}

.admin-searchSelect__option:hover,
.admin-searchSelect__option.is-selected {
    background: var(--accent-soft);
}

.admin-searchSelect__empty {
    padding: 10px;
    font-size: 12px;
    color: var(--text-muted);
}

html[data-theme="dark"] .admin-searchSelect__panel {
    box-shadow: 0 16px 30px rgba(2, 6, 23, 0.45);
}
</style>
