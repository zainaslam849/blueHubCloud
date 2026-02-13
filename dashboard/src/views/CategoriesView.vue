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
const selectedCompanyFilter = ref<number | null>(null);

const formData = ref<FormState>({
    name: "",
    description: "",
    is_enabled: true,
});

// Computed
const filteredCategories = computed(() => {
    if (selectedCompanyFilter.value === null) {
        return categories.value;
    }
    return categories.value.filter(
        (cat) => cat.company_id === selectedCompanyFilter.value,
    );
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
                    <div class="flex-1">
                        <label
                            for="company-filter"
                            class="block text-sm font-medium mb-2"
                        >
                            Filter by Client
                        </label>
                        <select
                            id="company-filter"
                            v-model.number="selectedCompanyFilter"
                            @change="fetchCategories"
                            class="w-full md:w-64 px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                        >
                            <option :value="null">All Clients</option>
                            <option
                                v-for="company in companies"
                                :key="company.id"
                                :value="company.id"
                            >
                                {{ company.name }}
                            </option>
                        </select>
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
                                        v-if="category.is_enabled"
                                        class="bg-green-100 text-green-800"
                                    >
                                        Enabled
                                    </BaseBadge>
                                    <BaseBadge
                                        v-else
                                        class="bg-gray-100 text-gray-800"
                                    >
                                        Disabled
                                    </BaseBadge>
                                    <BaseBadge
                                        v-if="category.deleted_at"
                                        class="ml-2 bg-red-100 text-red-800"
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
                    v-if="!loading && categories.length === 0"
                    class="py-8 text-center text-gray-500"
                >
                    No categories yet.
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
