<script setup lang="ts">
import { ref, computed, onMounted } from "vue";
import Card from "../components/ui/Card.vue";
import PageHeader from "../components/ui/PageHeader.vue";
import BaseButton from "../components/ui/BaseButton.vue";
import BaseBadge from "../components/ui/BaseBadge.vue";
import {
    categoriesApi,
    type CallCategory,
    type Company,
} from "../api/categories";
import { adminApi } from "../api/adminApi";

interface FormState {
    id?: number;
    name: string;
    description: string;
    is_enabled: boolean;
    company_id?: number;
}

interface ValidationErrors {
    name?: string[];
    description?: string[];
    is_enabled?: string[];
    general?: string[];
}

// State
const categories = ref<CallCategory[]>([]);
const companies = ref<Company[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);
const showForm = ref(false);
const isEditing = ref(false);
const submitting = ref(false);
const validationErrors = ref<ValidationErrors>({});

// Filter state
const showFilterModal = ref(false);
const selectedCompanyFilter = ref<number | null>(null);
const selectedStatusFilter = ref<string>("all");
const selectedSourceFilter = ref<string>("all");
const searchQuery = ref("");

const formData = ref<FormState>({
    name: "",
    description: "",
    is_enabled: true,
});

// Computed
const selectedCompanyName = computed(() => {
    if (!selectedCompanyFilter.value) return "All Clients";
    const company = companies.value.find(
        (c) => c.id === selectedCompanyFilter.value,
    );
    return company?.name || "Unknown";
});

const filteredCategories = computed(() => {
    let filtered = categories.value;

    // Filter by company
    if (selectedCompanyFilter.value !== null) {
        filtered = filtered.filter(
            (cat) => cat.company_id === selectedCompanyFilter.value,
        );
    }

    // Filter by status (is_enabled)
    if (selectedStatusFilter.value === "enabled") {
        filtered = filtered.filter((cat) => cat.is_enabled && !cat.deleted_at);
    } else if (selectedStatusFilter.value === "disabled") {
        filtered = filtered.filter((cat) => !cat.is_enabled && !cat.deleted_at);
    } else if (selectedStatusFilter.value === "deleted") {
        filtered = filtered.filter((cat) => cat.deleted_at);
    } else if (selectedStatusFilter.value === "active") {
        filtered = filtered.filter((cat) => !cat.deleted_at);
    }

    // Filter by search
    if (searchQuery.value) {
        const query = searchQuery.value.toLowerCase();
        filtered = filtered.filter((cat) =>
            cat.name.toLowerCase().includes(query),
        );
    }

    return filtered;
});

const sortedCategories = computed(() => {
    return filteredCategories.value.slice().sort((a, b) => {
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
const fetchCompanies = async () => {
    try {
        const response = await adminApi.get("/companies");
        companies.value = response.data;
    } catch (err) {
        console.error("Failed to load companies:", err);
    }
};

const fetchCategories = async () => {
    try {
        loading.value = true;
        error.value = null;
        const response = await categoriesApi.getAll(
            selectedCompanyFilter.value || undefined,
        );
        categories.value = response.data;
    } catch (err) {
        error.value =
            err instanceof Error ? err.message : "Failed to load categories";
    } finally {
        loading.value = false;
    }
};

const toggleFilterModal = () => {
    showFilterModal.value = !showFilterModal.value;
};

const closeFilterModal = () => {
    showFilterModal.value = false;
};

const resetFilters = () => {
    selectedCompanyFilter.value = null;
    selectedStatusFilter.value = "all";
    selectedSourceFilter.value = "all";
    searchQuery.value = "";
    fetchCategories();
    closeFilterModal();
};

const applyFilters = () => {
    fetchCategories();
    closeFilterModal();
};

const openAddForm = () => {
    isEditing.value = false;
    showForm.value = true;
    formData.value = {
        name: "",
        description: "",
        is_enabled: true,
        company_id: selectedCompanyFilter.value || undefined,
    };
    validationErrors.value = {};
};

const openEditForm = (category: CallCategory) => {
    isEditing.value = true;
    showForm.value = true;
    formData.value = {
        id: category.id,
        name: category.name,
        description: category.description || "",
        is_enabled: category.is_enabled,
        company_id: category.company_id,
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
            await categoriesApi.update(formData.value.id, {
                name: formData.value.name,
                description: formData.value.description || undefined,
                is_enabled: formData.value.is_enabled,
            });
        } else {
            await categoriesApi.create({
                name: formData.value.name,
                description: formData.value.description || undefined,
                is_enabled: formData.value.is_enabled,
            });
        }

        await fetchCategories();
        closeForm();
    } catch (err: any) {
        if (err.response?.data?.errors) {
            validationErrors.value = err.response.data.errors;
        } else if (err.response?.data?.message) {
            validationErrors.value.general = [err.response.data.message];
        } else {
            error.value =
                err instanceof Error ? err.message : "Failed to save category";
        }
    } finally {
        submitting.value = false;
    }
};

const toggleCategory = async (category: CallCategory) => {
    try {
        await categoriesApi.toggle(category.id);
        await fetchCategories();
    } catch (err: any) {
        if (err.response?.data?.message) {
            validationErrors.value.general = [err.response.data.message];
        } else {
            error.value =
                err instanceof Error
                    ? err.message
                    : "Failed to toggle category";
        }
    }
};

const deleteCategory = async (category: CallCategory) => {
    if (!confirm(`Delete "${category.name}"? (Soft delete)`)) return;

    try {
        await categoriesApi.delete(category.id);
        await fetchCategories();
    } catch (err: any) {
        if (err.response?.data?.message) {
            validationErrors.value.general = [err.response.data.message];
        } else {
            error.value =
                err instanceof Error
                    ? err.message
                    : "Failed to delete category";
        }
    }
};

const restoreCategory = async (category: CallCategory) => {
    try {
        await categoriesApi.restore(category.id);
        await fetchCategories();
    } catch (err) {
        error.value =
            err instanceof Error ? err.message : "Failed to restore category";
    }
};

const getCompanyName = (companyId: number): string => {
    const company = companies.value.find((c) => c.id === companyId);
    return company?.name || "Unknown";
};

// Lifecycle
onMounted(() => {
    fetchCompanies();
    fetchCategories();
});
</script>

<template>
    <div>
        <PageHeader
            title="Call Categories"
            description="Manage call categories for organization and reporting."
        />

        <Card>
            <div class="space-y-6">
                <!-- Error message -->
                <div v-if="error" class="rounded-lg bg-red-50 p-4 text-red-800">
                    {{ error }}
                </div>

                <!-- Validation errors -->
                <div
                    v-if="validationErrors.general"
                    class="rounded-lg bg-red-50 p-4 text-red-800"
                >
                    <div v-for="msg in validationErrors.general" :key="msg">
                        {{ msg }}
                    </div>
                </div>

                <!-- Filter and Action buttons -->
                <div
                    class="flex flex-col gap-4 md:flex-row md:justify-between md:items-center"
                >
                    <div class="relative">
                        <BaseButton
                            @click="toggleFilterModal"
                            class="bg-gray-200 text-gray-800 hover:bg-gray-300 inline-flex items-center gap-2"
                        >
                            <svg
                                viewBox="0 0 24 24"
                                fill="none"
                                xmlns="http://www.w3.org/2000/svg"
                                class="w-4 h-4"
                            >
                                <path
                                    d="M4 5H20L14 12V19L10 21V12L4 5Z"
                                    stroke="currentColor"
                                    stroke-width="2"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                />
                            </svg>
                            Filter
                        </BaseButton>

                        <!-- Filter Popup Modal -->
                        <div
                            v-if="showFilterModal"
                            class="absolute top-12 left-0 z-50 bg-white border border-gray-200 rounded-lg shadow-lg min-w-96"
                        >
                            <div class="p-4 border-b border-gray-200">
                                <h3 class="font-semibold text-sm">
                                    Filter Options
                                </h3>
                            </div>

                            <div class="p-4 space-y-4">
                                <!-- Company Filter -->
                                <div>
                                    <label
                                        class="block text-sm font-medium mb-2"
                                    >
                                        Client
                                    </label>
                                    <select
                                        v-model.number="selectedCompanyFilter"
                                        class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                                    >
                                        <option :value="null">
                                            All Clients
                                        </option>
                                        <option
                                            v-for="company in companies"
                                            :key="company.id"
                                            :value="company.id"
                                        >
                                            {{ company.name }}
                                        </option>
                                    </select>
                                </div>

                                <!-- Status Filter -->
                                <div>
                                    <label
                                        class="block text-sm font-medium mb-2"
                                    >
                                        Status
                                    </label>
                                    <select
                                        v-model="selectedStatusFilter"
                                        class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                                    >
                                        <option value="all">All</option>
                                        <option value="active">
                                            Active (Not Deleted)
                                        </option>
                                        <option value="enabled">Enabled</option>
                                        <option value="disabled">
                                            Disabled
                                        </option>
                                        <option value="deleted">Deleted</option>
                                    </select>
                                </div>

                                <!-- Source Filter -->
                                <div>
                                    <label
                                        class="block text-sm font-medium mb-2"
                                    >
                                        Source
                                    </label>
                                    <select
                                        v-model="selectedSourceFilter"
                                        class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                                    >
                                        <option value="all">All</option>
                                        <option value="ai">AI Generated</option>
                                        <option value="admin">Manual</option>
                                    </select>
                                </div>

                                <!-- Search Filter -->
                                <div>
                                    <label
                                        class="block text-sm font-medium mb-2"
                                    >
                                        Search
                                    </label>
                                    <input
                                        v-model="searchQuery"
                                        type="text"
                                        placeholder="Search by name"
                                        class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                                    />
                                </div>
                            </div>

                            <div
                                class="p-4 border-t border-gray-200 flex gap-2 justify-end"
                            >
                                <BaseButton
                                    @click="resetFilters"
                                    class="bg-gray-200 text-gray-800 hover:bg-gray-300"
                                >
                                    Reset
                                </BaseButton>
                                <BaseButton
                                    @click="applyFilters"
                                    class="bg-blue-600 text-white hover:bg-blue-700"
                                >
                                    Apply
                                </BaseButton>
                            </div>
                        </div>
                    </div>

                    <div class="text-sm text-gray-600">
                        {{ sortedCategories.length }} categories
                    </div>

                    <BaseButton
                        @click="openAddForm"
                        class="bg-blue-600 text-white hover:bg-blue-700"
                    >
                        + Add Category
                    </BaseButton>
                </div>

                <!-- Loading state -->
                <div v-if="loading" class="text-center py-8 text-gray-500">
                    Loading categories...
                </div>

                <!-- Categories table -->
                <div v-else class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200">
                                <th class="px-4 py-3 text-left font-semibold">
                                    Client
                                </th>
                                <th class="px-4 py-3 text-left font-semibold">
                                    Name
                                </th>
                                <th class="px-4 py-3 text-left font-semibold">
                                    Description
                                </th>
                                <th class="px-4 py-3 text-left font-semibold">
                                    Status
                                </th>
                                <th class="px-4 py-3 text-right font-semibold">
                                    Actions
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="category in sortedCategories"
                                :key="category.id"
                                class="border-b border-gray-100 hover:bg-gray-50"
                            >
                                <td
                                    class="px-4 py-3 text-sm font-medium text-gray-700"
                                >
                                    {{ getCompanyName(category.company_id) }}
                                </td>
                                <td class="px-4 py-3">
                                    <div class="font-medium">
                                        {{ category.name }}
                                        <BaseBadge
                                            v-if="category.name === 'General'"
                                            class="ml-2 bg-purple-100 text-purple-800"
                                        >
                                            Default
                                        </BaseBadge>
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-gray-600">
                                    {{ category.description || "—" }}
                                </td>
                                <td class="px-4 py-3">
                                    <BaseBadge
                                        v-if="
                                            category.is_enabled &&
                                            !category.deleted_at
                                        "
                                        class="bg-green-100 text-green-800"
                                    >
                                        Enabled
                                    </BaseBadge>
                                    <BaseBadge
                                        v-else-if="
                                            !category.is_enabled &&
                                            !category.deleted_at
                                        "
                                        class="bg-gray-100 text-gray-800"
                                    >
                                        Disabled
                                    </BaseBadge>
                                    <BaseBadge
                                        v-if="category.deleted_at"
                                        class="bg-red-100 text-red-800"
                                    >
                                        Deleted
                                    </BaseBadge>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <div class="space-x-2">
                                        <BaseButton
                                            v-if="!category.deleted_at"
                                            @click="openEditForm(category)"
                                            size="sm"
                                            class="text-blue-600 hover:bg-blue-50"
                                        >
                                            Edit
                                        </BaseButton>
                                        <BaseButton
                                            v-if="
                                                !category.deleted_at &&
                                                category.name !== 'General'
                                            "
                                            @click="toggleCategory(category)"
                                            size="sm"
                                            :class="{
                                                'text-orange-600 hover:bg-orange-50':
                                                    category.is_enabled,
                                                'text-green-600 hover:bg-green-50':
                                                    !category.is_enabled,
                                            }"
                                        >
                                            {{
                                                category.is_enabled
                                                    ? "Disable"
                                                    : "Enable"
                                            }}
                                        </BaseButton>
                                        <BaseButton
                                            v-if="
                                                !category.deleted_at &&
                                                category.name !== 'General'
                                            "
                                            @click="deleteCategory(category)"
                                            size="sm"
                                            class="text-red-600 hover:bg-red-50"
                                        >
                                            Delete
                                        </BaseButton>
                                        <BaseButton
                                            v-if="category.deleted_at"
                                            @click="restoreCategory(category)"
                                            size="sm"
                                            class="text-blue-600 hover:bg-blue-50"
                                        >
                                            Restore
                                        </BaseButton>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Empty state -->
                <div
                    v-if="!loading && sortedCategories.length === 0"
                    class="py-8 text-center text-gray-500"
                >
                    No categories match the current filters.
                </div>
            </div>
        </Card>

        <!-- Form modal -->
        <div
            v-if="showForm"
            class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50"
        >
            <Card class="w-full max-w-md mx-4">
                <div class="space-y-4">
                    <h2 class="text-xl font-bold">
                        {{ isEditing ? "Edit" : "Add" }} Category
                    </h2>

                    <div
                        v-if="isGeneralCategory"
                        class="bg-blue-50 p-3 rounded"
                    >
                        <p class="text-sm text-blue-800">
                            The "General" category is the default and cannot be
                            edited or deleted.
                        </p>
                    </div>

                    <!-- Show company info when editing -->
                    <div
                        v-if="isEditing && formData.company_id"
                        class="bg-gray-50 p-3 rounded"
                    >
                        <p class="text-sm text-gray-700">
                            <strong>Client:</strong>
                            {{ getCompanyName(formData.company_id) }}
                        </p>
                    </div>

                    <!-- Name field -->
                    <div>
                        <label
                            for="name"
                            class="block text-sm font-medium mb-1"
                        >
                            Category Name *
                        </label>
                        <input
                            id="name"
                            v-model="formData.name"
                            type="text"
                            class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                            :disabled="isGeneralCategory"
                            placeholder="e.g., Sales, Support, etc."
                        />
                        <div
                            v-if="validationErrors.name"
                            class="text-red-600 text-sm mt-1"
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
                    <div>
                        <label
                            for="description"
                            class="block text-sm font-medium mb-1"
                        >
                            Description
                        </label>
                        <textarea
                            id="description"
                            v-model="formData.description"
                            class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                            rows="3"
                            :disabled="isGeneralCategory"
                            placeholder="Brief description of this category"
                        />
                    </div>

                    <!-- Enabled toggle -->
                    <div>
                        <label class="flex items-center">
                            <input
                                v-model="formData.is_enabled"
                                type="checkbox"
                                class="rounded"
                                :disabled="isGeneralCategory"
                            />
                            <span class="ml-2 text-sm">Enabled</span>
                        </label>
                        <div
                            v-if="validationErrors.is_enabled"
                            class="text-red-600 text-sm mt-1"
                        >
                            <div
                                v-for="error in validationErrors.is_enabled"
                                :key="error"
                            >
                                {{ error }}
                            </div>
                        </div>
                    </div>

                    <!-- Actions -->
                    <div class="flex gap-3 justify-end pt-4">
                        <BaseButton
                            @click="closeForm"
                            class="bg-gray-200 text-gray-800 hover:bg-gray-300"
                            :disabled="submitting"
                        >
                            Cancel
                        </BaseButton>
                        <BaseButton
                            @click="submitForm"
                            :disabled="
                                !isFormValid || submitting || isGeneralCategory
                            "
                            class="bg-blue-600 text-white hover:bg-blue-700 disabled:bg-gray-400"
                        >
                            {{ submitting ? "Saving..." : "Save" }}
                        </BaseButton>
                    </div>
                </div>
            </Card>
        </div>
    </div>
</template>
