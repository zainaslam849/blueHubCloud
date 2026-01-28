<template>
    <div class="admin-container admin-page">
        <header class="admin-page__header">
            <div>
                <p class="admin-page__kicker">Configuration</p>
                <h1 class="admin-page__title">Categories</h1>
                <p class="admin-page__subtitle">
                    Manage call categories for organization and reporting.
                </p>
            </div>
            <BaseButton variant="primary" size="md" @click="openAddForm">
                + Add Category
            </BaseButton>
        </header>

        <section class="admin-card admin-card--glass">
            <div v-if="error" class="admin-alert admin-alert--error">
                {{ error }}
            </div>

            <div
                v-if="validationErrors.general"
                class="admin-alert admin-alert--error"
            >
                <div v-for="msg in validationErrors.general" :key="msg">
                    {{ msg }}
                </div>
            </div>

            <div v-if="loading" class="admin-tableWrap">
                <div class="admin-loadingState">
                    <p>Loading categories...</p>
                </div>
            </div>

            <div v-else-if="categories.length === 0" class="admin-tableWrap">
                <div class="admin-emptyState">
                    <p>No categories yet.</p>
                </div>
            </div>

            <div v-else class="admin-tableWrap">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th class="admin-table__th">Name</th>
                            <th class="admin-table__th">Description</th>
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
                            v-for="category in sortedCategories"
                            :key="category.id"
                            class="admin-table__tr"
                        >
                            <td class="admin-table__td" data-label="Name">
                                <div class="font-medium">
                                    {{ category.name }}
                                    <BaseBadge
                                        v-if="category.name === 'General'"
                                        variant="default"
                                        size="sm"
                                    >
                                        Default
                                    </BaseBadge>
                                </div>
                            </td>
                            <td
                                class="admin-table__td"
                                data-label="Description"
                            >
                                {{ category.description || "—" }}
                            </td>
                            <td class="admin-table__td" data-label="Status">
                                <div class="admin-status__wrapper">
                                    <BaseBadge
                                        v-if="category.is_enabled"
                                        variant="success"
                                        size="sm"
                                        class="admin-statusBadge admin-statusBadge--enabled"
                                    >
                                        <span class="admin-statusBadge__icon"
                                            >●</span
                                        >
                                        <span>Enabled</span>
                                    </BaseBadge>
                                    <BaseBadge
                                        v-else
                                        variant="secondary"
                                        size="sm"
                                        class="admin-statusBadge admin-statusBadge--disabled"
                                    >
                                        <span class="admin-statusBadge__icon"
                                            >○</span
                                        >
                                        <span>Disabled</span>
                                    </BaseBadge>
                                    <BaseBadge
                                        v-if="category.deleted_at"
                                        variant="danger"
                                        size="sm"
                                        class="admin-statusBadge admin-statusBadge--deleted"
                                    >
                                        <span class="admin-statusBadge__icon"
                                            >✕</span
                                        >
                                        <span>Deleted</span>
                                    </BaseBadge>
                                </div>
                            </td>
                            <td
                                class="admin-table__td admin-table__td--actions"
                                data-label="Actions"
                            >
                                <div class="admin-table__actions">
                                    <BaseButton
                                        v-if="!category.deleted_at"
                                        @click="openEditForm(category)"
                                        size="sm"
                                        variant="secondary"
                                        class="admin-actionBtn admin-actionBtn--edit"
                                    >
                                        <span class="admin-actionBtn__icon"
                                            >✎</span
                                        >
                                        <span class="admin-actionBtn__text"
                                            >Edit</span
                                        >
                                    </BaseButton>
                                    <BaseButton
                                        v-if="
                                            !category.deleted_at &&
                                            category.name !== 'General'
                                        "
                                        @click="toggleCategory(category)"
                                        size="sm"
                                        :variant="
                                            category.is_enabled
                                                ? 'warning'
                                                : 'success'
                                        "
                                        class="admin-actionBtn"
                                        :disabled="togglingId === category.id"
                                        :loading="togglingId === category.id"
                                    >
                                        <span class="admin-actionBtn__icon">{{
                                            category.is_enabled ? "⊘" : "●"
                                        }}</span>
                                        <span
                                            v-if="togglingId !== category.id"
                                            class="admin-actionBtn__text"
                                            >{{
                                                category.is_enabled
                                                    ? "Disable"
                                                    : "Enable"
                                            }}</span
                                        >
                                        <span
                                            v-else
                                            class="admin-actionBtn__text"
                                            >{{
                                                category.is_enabled
                                                    ? "Disabling..."
                                                    : "Enabling..."
                                            }}</span
                                        >
                                    </BaseButton>
                                    <BaseButton
                                        v-if="
                                            !category.deleted_at &&
                                            category.name !== 'General'
                                        "
                                        @click="openDeleteConfirm(category)"
                                        size="sm"
                                        variant="danger"
                                        class="admin-actionBtn admin-actionBtn--delete"
                                        :disabled="
                                            deleting &&
                                            deleteTarget?.id === category.id
                                        "
                                    >
                                        <span class="admin-actionBtn__icon"
                                            >🗑</span
                                        >
                                        <span class="admin-actionBtn__text"
                                            >Delete</span
                                        >
                                    </BaseButton>
                                    <BaseButton
                                        v-if="!category.deleted_at"
                                        @click="
                                            openSubCategoriesModal(category)
                                        "
                                        size="sm"
                                        variant="secondary"
                                        class="admin-actionBtn admin-actionBtn--manage"
                                        title="Manage sub-categories"
                                    >
                                        <span class="admin-actionBtn__icon"
                                            >⊞</span
                                        >
                                        <span class="admin-actionBtn__text"
                                            >Sub-Cats</span
                                        >
                                    </BaseButton>
                                    <BaseButton
                                        v-if="category.deleted_at"
                                        @click="restoreCategory(category)"
                                        size="sm"
                                        variant="secondary"
                                        class="admin-actionBtn admin-actionBtn--restore"
                                        :disabled="restoringId === category.id"
                                        :loading="restoringId === category.id"
                                    >
                                        <span class="admin-actionBtn__icon"
                                            >↺</span
                                        >
                                        <span
                                            v-if="restoringId !== category.id"
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
        </section>

        <!-- Category Form Modal -->
        <Teleport to="body">
            <Transition name="admin-modal">
                <div
                    v-if="showForm"
                    class="admin-modalOverlay"
                    @click="showForm = false"
                >
                    <div class="admin-modal" @click.stop>
                        <div class="admin-modal__header">
                            <h2 class="admin-modal__title">
                                {{ isEditing ? "Edit" : "Add" }} Category
                            </h2>
                            <button
                                type="button"
                                class="admin-modal__close"
                                @click="closeForm"
                            >
                                ✕
                            </button>
                        </div>

                        <div class="admin-modal__body">
                            <div
                                v-if="isGeneralCategory"
                                class="admin-alert admin-alert--info"
                            >
                                The "General" category is the default and cannot
                                be edited or deleted.
                            </div>

                            <!-- Name field -->
                            <div class="admin-field">
                                <label for="name" class="admin-field__label">
                                    Category Name *
                                </label>
                                <input
                                    id="name"
                                    v-model="formData.name"
                                    type="text"
                                    class="admin-input"
                                    :disabled="isGeneralCategory"
                                    placeholder="e.g., Sales, Support, etc."
                                />
                                <div
                                    v-if="validationErrors.name"
                                    class="admin-field__error"
                                >
                                    <div
                                        v-for="error in validationErrors.name"
                                        :key="error"
                                    >
                                        {{ error }}
                                    </div>
                                </div>
                            </div>

                            <!-- Description field -->
                            <div class="admin-field">
                                <label
                                    for="description"
                                    class="admin-field__label"
                                >
                                    Description
                                </label>
                                <textarea
                                    id="description"
                                    v-model="formData.description"
                                    class="admin-input admin-textarea"
                                    rows="3"
                                    :disabled="isGeneralCategory"
                                    placeholder="Brief description of this category"
                                />
                            </div>

                            <!-- Enabled toggle -->
                            <div class="admin-field">
                                <label class="admin-checkbox">
                                    <input
                                        v-model="formData.is_enabled"
                                        type="checkbox"
                                        :disabled="isGeneralCategory"
                                    />
                                    <span>Enabled</span>
                                </label>
                                <div
                                    v-if="validationErrors.is_enabled"
                                    class="admin-field__error"
                                >
                                    <div
                                        v-for="error in validationErrors.is_enabled"
                                        :key="error"
                                    >
                                        {{ error }}
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="admin-modal__footer">
                            <BaseButton
                                @click="closeForm"
                                variant="secondary"
                                :disabled="submitting"
                            >
                                Cancel
                            </BaseButton>
                            <BaseButton
                                @click="submitForm"
                                variant="primary"
                                :disabled="
                                    !isFormValid ||
                                    submitting ||
                                    isGeneralCategory
                                "
                                :loading="submitting"
                            >
                                Save
                            </BaseButton>
                        </div>
                    </div>
                </div>
            </Transition>
        </Teleport>

        <!-- Delete Confirmation Modal -->
        <Teleport to="body">
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
                                Delete Category
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
                                This action will soft delete the category. You
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
                            >
                                Cancel
                            </BaseButton>
                            <BaseButton
                                @click="confirmDelete"
                                variant="danger"
                                :loading="deleting"
                                :disabled="deleting"
                                class="admin-btn--full"
                            >
                                {{
                                    deleting ? "Deleting..." : "Delete Category"
                                }}
                            </BaseButton>
                        </div>
                    </div>
                </div>
            </Transition>
        </Teleport>

        <!-- Sub-Categories Modal -->
        <SubCategoriesModal
            :is-open="showSubCatsModal"
            :category="selectedCategory"
            @close="showSubCatsModal = false"
        />
    </div>
</template>
<script setup>
import { ref, computed, onMounted } from "vue";
import { BaseButton, BaseBadge } from "../../components/admin/base";
import SubCategoriesModal from "../../components/admin/SubCategoriesModal.vue";
import adminApi from "../../router/admin/api";

// Interfaces/types
const categories = ref([]);
const loading = ref(true);
const error = ref(null);
const showForm = ref(false);
const isEditing = ref(false);
const submitting = ref(false);
const validationErrors = ref({});
const showDeleteConfirm = ref(false);
const deleteTarget = ref(null);
const deleting = ref(false);
const togglingId = ref(null);
const restoringId = ref(null);
const showSubCatsModal = ref(false);
const selectedCategory = ref(null);

const formData = ref({
    name: "",
    description: "",
    is_enabled: true,
});

// Computed
const sortedCategories = computed(() => {
    return categories.value.slice().sort((a, b) => {
        // Enabled first, then alphabetical
        if (a.is_enabled !== b.is_enabled) {
            return b.is_enabled ? 1 : -1;
        }
        return a.name.localeCompare(b.name);
    });
});

const isGeneralCategory = computed(() => {
    return isEditing.value && formData.value.name === "General";
});

const isFormValid = computed(() => {
    return formData.value.name.trim().length > 0;
});

// Methods
const fetchCategories = async () => {
    try {
        loading.value = true;
        error.value = null;
        const response = await adminApi.get("/categories");
        categories.value = response.data.data;
    } catch (err) {
        error.value = err.message || "Failed to load categories";
    } finally {
        loading.value = false;
    }
};

const openAddForm = () => {
    isEditing.value = false;
    showForm.value = true;
    formData.value = {
        name: "",
        description: "",
        is_enabled: true,
    };
    validationErrors.value = {};
};

const openEditForm = (category) => {
    isEditing.value = true;
    showForm.value = true;
    formData.value = {
        id: category.id,
        name: category.name,
        description: category.description || "",
        is_enabled: category.is_enabled,
    };
    validationErrors.value = {};
};

const closeForm = () => {
    showForm.value = false;
    formData.value = {
        name: "",
        description: "",
        is_enabled: true,
    };
    validationErrors.value = {};
};

const submitForm = async () => {
    if (!isFormValid.value) return;

    try {
        submitting.value = true;
        validationErrors.value = {};

        if (isEditing.value && formData.value.id) {
            await adminApi.put(`/categories/${formData.value.id}`, {
                name: formData.value.name,
                description: formData.value.description || null,
                is_enabled: formData.value.is_enabled,
            });
        } else {
            await adminApi.post("/categories", {
                name: formData.value.name,
                description: formData.value.description || null,
                is_enabled: formData.value.is_enabled,
            });
        }

        await fetchCategories();
        closeForm();
    } catch (err) {
        if (err.response?.data?.errors) {
            validationErrors.value = err.response.data.errors;
        } else if (err.response?.data?.message) {
            validationErrors.value.general = [err.response.data.message];
        } else {
            error.value = err.message || "Failed to save category";
        }
    } finally {
        submitting.value = false;
    }
};

const toggleCategory = async (category) => {
    try {
        togglingId.value = category.id;
        await adminApi.patch(`/categories/${category.id}/toggle`);
        await fetchCategories();
    } catch (err) {
        if (err.response?.data?.message) {
            validationErrors.value.general = [err.response.data.message];
        } else {
            error.value = err.message || "Failed to toggle category";
        }
    } finally {
        togglingId.value = null;
    }
};

const openDeleteConfirm = (category) => {
    deleteTarget.value = category;
    showDeleteConfirm.value = true;
};

const cancelDelete = () => {
    showDeleteConfirm.value = false;
    deleteTarget.value = null;
    deleting.value = false;
};

const confirmDelete = async () => {
    if (!deleteTarget.value) return;

    try {
        deleting.value = true;
        await adminApi.delete(`/categories/${deleteTarget.value.id}`);
        await fetchCategories();
        cancelDelete();
    } catch (err) {
        if (err.response?.data?.message) {
            error.value = err.response.data.message;
        } else {
            error.value = err.message || "Failed to delete category";
        }
    } finally {
        deleting.value = false;
    }
};

const deleteCategory = async (category) => {
    if (!confirm(`Delete "${category.name}"? (Soft delete)`)) return;

    try {
        await adminApi.delete(`/categories/${category.id}`);
        await fetchCategories();
    } catch (err) {
        if (err.response?.data?.message) {
            validationErrors.value.general = [err.response.data.message];
        } else {
            error.value = err.message || "Failed to delete category";
        }
    }
};

const restoreCategory = async (category) => {
    try {
        restoringId.value = category.id;
        await adminApi.post(`/categories/${category.id}/restore`);
        await fetchCategories();
    } catch (err) {
        error.value =
            err instanceof Error ? err.message : "Failed to restore category";
    } finally {
        restoringId.value = null;
    }
};

const openSubCategoriesModal = (category) => {
    selectedCategory.value = category;
    showSubCatsModal.value = true;
};

// Lifecycle
onMounted(() => {
    fetchCategories();
});
</script>
