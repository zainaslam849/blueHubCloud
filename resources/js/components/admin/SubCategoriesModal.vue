<template>
    <Teleport to="body">
        <Transition name="admin-modal">
            <div v-if="isOpen" class="admin-modalOverlay" @click="close">
                <div class="admin-modal admin-modal--lg" @click.stop>
                    <div class="admin-modal__header">
                        <h2 class="admin-modal__title">
                            Sub-Categories:
                            <strong>{{ category?.name }}</strong>
                        </h2>
                        <button
                            type="button"
                            class="admin-modal__close"
                            @click="close"
                        >
                            ✕
                        </button>
                    </div>

                    <div class="admin-modal__body">
                        <div
                            v-if="error"
                            class="admin-alert admin-alert--error"
                        >
                            {{ error }}
                        </div>
                        <div
                            v-if="validationErrors.general"
                            class="admin-alert admin-alert--error"
                        >
                            <div
                                v-for="msg in validationErrors.general"
                                :key="msg"
                            >
                                {{ msg }}
                            </div>
                        </div>

                        <div v-if="loadingSubCats" class="admin-tableWrap">
                            <div class="admin-loadingState">
                                <p>Loading sub-categories...</p>
                            </div>
                        </div>

                        <div
                            v-else-if="subCategories.length === 0"
                            class="admin-tableWrap"
                        >
                            <div class="admin-emptyState">
                                <p>No sub-categories yet.</p>
                            </div>
                        </div>

                        <div v-else class="admin-tableWrap">
                            <table class="admin-table">
                                <thead>
                                    <tr>
                                        <th class="admin-table__th">Name</th>
                                        <th class="admin-table__th">
                                            Description
                                        </th>
                                        <th class="admin-table__th">Status</th>
                                        <th
                                            class="admin-table__th"
                                            style="text-align: right"
                                        >
                                            Actions
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="admin-table__body">
                                    <tr
                                        v-for="subCat in sortedSubCategories"
                                        :key="subCat.id"
                                        class="admin-table__tr"
                                    >
                                        <td
                                            class="admin-table__td"
                                            data-label="Name"
                                        >
                                            <div class="font-medium">
                                                {{ subCat.name }}
                                            </div>
                                        </td>
                                        <td
                                            class="admin-table__td"
                                            data-label="Description"
                                        >
                                            {{ subCat.description || "—" }}
                                        </td>
                                        <td
                                            class="admin-table__td"
                                            data-label="Status"
                                        >
                                            <div class="admin-status__wrapper">
                                                <BaseBadge
                                                    v-if="subCat.is_enabled"
                                                    variant="success"
                                                    size="sm"
                                                    class="admin-statusBadge admin-statusBadge--enabled"
                                                >
                                                    <span
                                                        class="admin-statusBadge__icon"
                                                        >●</span
                                                    ><span>Enabled</span>
                                                </BaseBadge>
                                                <BaseBadge
                                                    v-else
                                                    variant="secondary"
                                                    size="sm"
                                                    class="admin-statusBadge admin-statusBadge--disabled"
                                                >
                                                    <span
                                                        class="admin-statusBadge__icon"
                                                        >○</span
                                                    ><span>Disabled</span>
                                                </BaseBadge>
                                                <BaseBadge
                                                    v-if="subCat.deleted_at"
                                                    variant="danger"
                                                    size="sm"
                                                    class="admin-statusBadge admin-statusBadge--deleted"
                                                >
                                                    <span
                                                        class="admin-statusBadge__icon"
                                                        >✕</span
                                                    ><span>Deleted</span>
                                                </BaseBadge>
                                            </div>
                                        </td>
                                        <td
                                            class="admin-table__td admin-table__td--actions"
                                            data-label="Actions"
                                        >
                                            <div class="admin-table__actions">
                                                <BaseButton
                                                    v-if="!subCat.deleted_at"
                                                    @click="
                                                        openEditForm(subCat)
                                                    "
                                                    size="sm"
                                                    variant="secondary"
                                                    class="admin-actionBtn admin-actionBtn--edit"
                                                >
                                                    <span
                                                        class="admin-actionBtn__icon"
                                                        >✎</span
                                                    ><span
                                                        class="admin-actionBtn__text"
                                                        >Edit</span
                                                    >
                                                </BaseButton>
                                                <BaseButton
                                                    v-if="!subCat.deleted_at"
                                                    @click="
                                                        toggleSubCategory(
                                                            subCat,
                                                        )
                                                    "
                                                    size="sm"
                                                    :variant="
                                                        subCat.is_enabled
                                                            ? 'warning'
                                                            : 'success'
                                                    "
                                                    class="admin-actionBtn"
                                                    :disabled="
                                                        togglingId === subCat.id
                                                    "
                                                    :loading="
                                                        togglingId === subCat.id
                                                    "
                                                >
                                                    <span
                                                        class="admin-actionBtn__icon"
                                                        >{{
                                                            subCat.is_enabled
                                                                ? "⊘"
                                                                : "●"
                                                        }}</span
                                                    >
                                                    <span
                                                        v-if="
                                                            togglingId !==
                                                            subCat.id
                                                        "
                                                        class="admin-actionBtn__text"
                                                        >{{
                                                            subCat.is_enabled
                                                                ? "Disable"
                                                                : "Enable"
                                                        }}</span
                                                    >
                                                    <span
                                                        v-else
                                                        class="admin-actionBtn__text"
                                                        >{{
                                                            subCat.is_enabled
                                                                ? "Disabling..."
                                                                : "Enabling..."
                                                        }}</span
                                                    >
                                                </BaseButton>
                                                <BaseButton
                                                    v-if="!subCat.deleted_at"
                                                    @click="
                                                        openDeleteConfirm(
                                                            subCat,
                                                        )
                                                    "
                                                    size="sm"
                                                    variant="danger"
                                                    class="admin-actionBtn admin-actionBtn--delete"
                                                    :disabled="
                                                        deleting &&
                                                        deleteTarget?.id ===
                                                            subCat.id
                                                    "
                                                >
                                                    <span
                                                        class="admin-actionBtn__icon"
                                                        >🗑</span
                                                    ><span
                                                        class="admin-actionBtn__text"
                                                        >Delete</span
                                                    >
                                                </BaseButton>
                                                <BaseButton
                                                    v-if="subCat.deleted_at"
                                                    @click="
                                                        restoreSubCategory(
                                                            subCat,
                                                        )
                                                    "
                                                    size="sm"
                                                    variant="secondary"
                                                    class="admin-actionBtn admin-actionBtn--restore"
                                                    :disabled="
                                                        restoringId ===
                                                        subCat.id
                                                    "
                                                    :loading="
                                                        restoringId ===
                                                        subCat.id
                                                    "
                                                >
                                                    <span
                                                        class="admin-actionBtn__icon"
                                                        >↺</span
                                                    >
                                                    <span
                                                        v-if="
                                                            restoringId !==
                                                            subCat.id
                                                        "
                                                        class="admin-actionBtn__text"
                                                        >Restore</span
                                                    >
                                                    <span
                                                        v-else
                                                        class="admin-actionBtn__text"
                                                        >Restoring...</span
                                                    >
                                                </BaseButton>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div v-if="!showForm" class="admin-spacer"></div>
                        <div v-else class="admin-subCatForm">
                            <h3 class="admin-subCatForm__title">
                                {{
                                    isEditing
                                        ? "Edit Sub-Category"
                                        : "Add Sub-Category"
                                }}
                            </h3>
                            <div class="admin-formGroup">
                                <label class="admin-formLabel">Name *</label>
                                <input
                                    v-model="formData.name"
                                    type="text"
                                    class="admin-input"
                                    :class="{
                                        'admin-input--error':
                                            validationErrors.name,
                                    }"
                                    placeholder="Enter sub-category name"
                                />
                                <div
                                    v-if="validationErrors.name"
                                    class="admin-inputError"
                                >
                                    {{ validationErrors.name[0] }}
                                </div>
                            </div>
                            <div class="admin-formGroup">
                                <label class="admin-formLabel"
                                    >Description</label
                                >
                                <textarea
                                    v-model="formData.description"
                                    class="admin-textarea"
                                    placeholder="Enter description (optional)"
                                    rows="3"
                                ></textarea>
                            </div>
                            <div class="admin-formGroup">
                                <label class="admin-formCheckbox">
                                    <input
                                        v-model="formData.is_enabled"
                                        type="checkbox"
                                    />
                                    <span>Enable this sub-category</span>
                                </label>
                            </div>
                            <div class="admin-formActions">
                                <BaseButton
                                    @click="cancelForm"
                                    variant="secondary"
                                    :disabled="formSubmitting"
                                    >Cancel</BaseButton
                                >
                                <BaseButton
                                    @click="submitForm"
                                    variant="primary"
                                    :disabled="!isFormValid || formSubmitting"
                                    :loading="formSubmitting"
                                >
                                    {{
                                        isEditing ? "Update" : "Add"
                                    }}
                                    Sub-Category
                                </BaseButton>
                            </div>
                        </div>
                    </div>

                    <div class="admin-modal__footer">
                        <BaseButton
                            v-if="!showForm"
                            @click="openAddForm"
                            variant="primary"
                            size="md"
                            >+ Add Sub-Category</BaseButton
                        >
                        <BaseButton
                            @click="close"
                            variant="secondary"
                            size="md"
                            >{{ showForm ? "Back" : "Close" }}</BaseButton
                        >
                    </div>
                </div>
            </div>
        </Transition>

        <Transition name="admin-modal">
            <div
                v-if="showDeleteConfirm"
                class="admin-modalOverlay"
                @click="showDeleteConfirm = false"
            >
                <div class="admin-modal admin-modal--confirm" @click.stop>
                    <div
                        class="admin-modal__header admin-modal__header--danger"
                    >
                        <div class="admin-modal__headerIcon">⚠</div>
                        <h2
                            class="admin-modal__title admin-modal__title--danger"
                        >
                            Delete Sub-Category
                        </h2>
                        <button
                            type="button"
                            class="admin-modal__close"
                            @click="cancelDelete"
                        >
                            ✕
                        </button>
                    </div>
                    <div class="admin-modal__body">
                        <p class="admin-deletePrompt">
                            Are you sure you want to delete
                            <strong>{{ deleteTarget?.name }}</strong
                            >?
                        </p>
                        <p
                            class="admin-deletePrompt admin-deletePrompt--secondary"
                        >
                            This action will soft delete the sub-category. You
                            can restore it later if needed.
                        </p>
                    </div>
                    <div
                        class="admin-modal__footer admin-modal__footer--confirm"
                    >
                        <BaseButton
                            @click="cancelDelete"
                            variant="secondary"
                            class="admin-btn--full"
                            :disabled="deleting"
                            >Cancel</BaseButton
                        >
                        <BaseButton
                            @click="confirmDelete"
                            variant="danger"
                            :loading="deleting"
                            :disabled="deleting"
                            class="admin-btn--full"
                            >{{
                                deleting ? "Deleting..." : "Delete Sub-Category"
                            }}</BaseButton
                        >
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

<script setup>
import { ref, computed, watch } from "vue";
import { BaseButton, BaseBadge } from "./base";
import adminApi from "../../router/admin/api";

const props = defineProps({ isOpen: Boolean, category: Object });
const emit = defineEmits(["close"]);

const subCategories = ref([]);
const loadingSubCats = ref(false);
const error = ref(null);
const showForm = ref(false);
const isEditing = ref(false);
const formSubmitting = ref(false);
const validationErrors = ref({});
const showDeleteConfirm = ref(false);
const deleteTarget = ref(null);
const deleting = ref(false);
const togglingId = ref(null);
const restoringId = ref(null);
const formData = ref({ name: "", description: "", is_enabled: true });

const sortedSubCategories = computed(() => {
    return subCategories.value.slice().sort((a, b) => {
        if ((a.deleted_at === null) !== (b.deleted_at === null))
            return a.deleted_at === null ? -1 : 1;
        if (a.is_enabled !== b.is_enabled) return b.is_enabled ? 1 : -1;
        return a.name.localeCompare(b.name);
    });
});

const isFormValid = computed(() => formData.value.name.trim().length > 0);

const fetchSubCategories = async () => {
    if (!props.category) return;
    try {
        loadingSubCats.value = true;
        error.value = null;
        const response = await adminApi.get(
            `/categories/${props.category.id}/sub-categories`,
        );
        subCategories.value = response.data.data;
    } catch (err) {
        error.value = err.message || "Failed to load sub-categories";
    } finally {
        loadingSubCats.value = false;
    }
};

const openAddForm = () => {
    isEditing.value = false;
    showForm.value = true;
    formData.value = { name: "", description: "", is_enabled: true };
    validationErrors.value = {};
};

const openEditForm = (subCat) => {
    isEditing.value = true;
    showForm.value = true;
    formData.value = {
        id: subCat.id,
        name: subCat.name,
        description: subCat.description || "",
        is_enabled: subCat.is_enabled,
    };
    validationErrors.value = {};
};

const cancelForm = () => {
    showForm.value = false;
    formData.value = { name: "", description: "", is_enabled: true };
    validationErrors.value = {};
};

const submitForm = async () => {
    if (!isFormValid.value || !props.category) return;
    try {
        formSubmitting.value = true;
        validationErrors.value = {};
        if (isEditing.value && formData.value.id) {
            await adminApi.put(
                `/categories/${props.category.id}/sub-categories/${formData.value.id}`,
                {
                    name: formData.value.name,
                    description: formData.value.description || null,
                    is_enabled: formData.value.is_enabled,
                },
            );
        } else {
            await adminApi.post(
                `/categories/${props.category.id}/sub-categories`,
                {
                    name: formData.value.name,
                    description: formData.value.description || null,
                    is_enabled: formData.value.is_enabled,
                },
            );
        }
        await fetchSubCategories();
        cancelForm();
    } catch (err) {
        if (err.response?.data?.errors) {
            validationErrors.value = err.response.data.errors;
        } else if (err.response?.data?.message) {
            validationErrors.value.general = [err.response.data.message];
        } else {
            error.value = err.message || "Failed to save sub-category";
        }
    } finally {
        formSubmitting.value = false;
    }
};

const toggleSubCategory = async (subCat) => {
    if (!props.category) return;
    try {
        togglingId.value = subCat.id;
        await adminApi.patch(
            `/categories/${props.category.id}/sub-categories/${subCat.id}/toggle`,
        );
        await fetchSubCategories();
    } catch (err) {
        if (err.response?.data?.message) {
            validationErrors.value.general = [err.response.data.message];
        } else {
            error.value = err.message || "Failed to toggle sub-category";
        }
    } finally {
        togglingId.value = null;
    }
};

const openDeleteConfirm = (subCat) => {
    deleteTarget.value = subCat;
    showDeleteConfirm.value = true;
};

const cancelDelete = () => {
    showDeleteConfirm.value = false;
    deleteTarget.value = null;
    deleting.value = false;
};

const confirmDelete = async () => {
    if (!deleteTarget.value || !props.category) return;
    try {
        deleting.value = true;
        await adminApi.delete(
            `/categories/${props.category.id}/sub-categories/${deleteTarget.value.id}`,
        );
        await fetchSubCategories();
        cancelDelete();
    } catch (err) {
        if (err.response?.data?.message) {
            error.value = err.response.data.message;
        } else {
            error.value = err.message || "Failed to delete sub-category";
        }
    } finally {
        deleting.value = false;
    }
};

const restoreSubCategory = async (subCat) => {
    if (!props.category) return;
    try {
        restoringId.value = subCat.id;
        await adminApi.post(
            `/categories/${props.category.id}/sub-categories/${subCat.id}/restore`,
        );
        await fetchSubCategories();
    } catch (err) {
        error.value =
            err instanceof Error
                ? err.message
                : "Failed to restore sub-category";
    } finally {
        restoringId.value = null;
    }
};

const close = () => {
    cancelForm();
    emit("close");
};

watch(
    () => props.isOpen,
    (newVal) => {
        if (newVal && props.category) {
            fetchSubCategories();
        }
    },
);
</script>

<style scoped>
.admin-spacer {
    height: 20px;
}
.admin-subCatForm {
    border-top: 1px solid var(--border-soft);
    padding-top: 24px;
    margin-top: 24px;
}
.admin-subCatForm__title {
    font-size: 15px;
    font-weight: 650;
    color: var(--text-primary);
    margin-bottom: 16px;
}
.admin-formGroup {
    margin-bottom: 16px;
}
.admin-formLabel {
    display: block;
    font-size: 13px;
    font-weight: 600;
    color: var(--text-primary);
    margin-bottom: 6px;
}
.admin-input,
.admin-textarea {
    width: 100%;
    padding: 10px 12px;
    border-radius: 8px;
    border: 1px solid var(--border-soft);
    background: var(--surface);
    color: var(--text-primary);
    font-size: 13px;
    font-family: inherit;
    transition: all 0.2s ease;
}
.admin-input:focus,
.admin-textarea:focus {
    outline: none;
    border-color: var(--accent-border);
    box-shadow: 0 0 0 3px var(--accent-soft);
}
.admin-input--error,
.admin-textarea--error {
    border-color: var(--error);
}
.admin-inputError {
    font-size: 12px;
    color: var(--error);
    margin-top: 4px;
}
.admin-formCheckbox {
    display: flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
    font-size: 13px;
    color: var(--text-primary);
}
.admin-formCheckbox input {
    cursor: pointer;
}
.admin-formActions {
    display: flex;
    gap: 10px;
    margin-top: 20px;
}
.admin-formActions :deep(button) {
    flex: 1;
}
</style>
