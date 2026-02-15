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
            <div style="display: flex; gap: 10px; flex-wrap: wrap">
                <BaseButton
                    variant="secondary"
                    size="md"
                    @click="openAiGenerateModal"
                >
                    Generate AI Categories
                </BaseButton>
                <BaseButton variant="primary" size="md" @click="openAddForm">
                    + Add Category
                </BaseButton>
            </div>
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

            <div class="admin-callsToolbar">
                <div class="admin-callsToolbar__left">
                    <div class="admin-field admin-callsToolbar__search">
                        <label
                            class="admin-field__label"
                            for="categories-search"
                        >
                            Search
                        </label>
                        <input
                            id="categories-search"
                            v-model="searchQuery"
                            class="admin-input"
                            type="search"
                            autocomplete="off"
                            placeholder="Search by name"
                        />
                    </div>
                </div>

                <div class="admin-callsToolbar__right">
                    <div ref="filterWrap" class="admin-filterPopover">
                        <BaseButton
                            variant="secondary"
                            size="sm"
                            class="admin-filterTrigger"
                            @click="toggleFilters"
                        >
                            <span
                                class="admin-filterTrigger__icon"
                                aria-hidden="true"
                            >
                                <svg
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    xmlns="http://www.w3.org/2000/svg"
                                >
                                    <path
                                        d="M4 5H20L14 12V19L10 21V12L4 5Z"
                                        stroke="currentColor"
                                        stroke-width="1.8"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                    />
                                </svg>
                            </span>
                            Filter
                        </BaseButton>

                        <div
                            v-if="filtersOpen && isDesktop"
                            class="admin-filterPanel"
                            role="dialog"
                            aria-label="Filter options"
                        >
                            <div class="admin-filterPanel__header">
                                Filter Options
                            </div>

                            <div class="admin-filterGrid">
                                <div class="admin-field">
                                    <label
                                        class="admin-field__label"
                                        for="filter-company"
                                    >
                                        Company
                                    </label>
                                    <select
                                        id="filter-company"
                                        v-model="draftFilterCompany"
                                        class="admin-input admin-input--select"
                                    >
                                        <option value="">All Companies</option>
                                        <option
                                            v-for="company in companies"
                                            :key="company.id"
                                            :value="company.id"
                                        >
                                            {{ company.name }}
                                        </option>
                                    </select>
                                </div>

                                <div class="admin-field">
                                    <label
                                        class="admin-field__label"
                                        for="filter-status"
                                    >
                                        Status
                                    </label>
                                    <select
                                        id="filter-status"
                                        v-model="draftFilterStatus"
                                        class="admin-input admin-input--select"
                                    >
                                        <option value="all">All</option>
                                        <option value="active">Active</option>
                                        <option value="archived">
                                            Archived
                                        </option>
                                    </select>
                                </div>

                                <div class="admin-field">
                                    <label
                                        class="admin-field__label"
                                        for="filter-source"
                                    >
                                        Source
                                    </label>
                                    <select
                                        id="filter-source"
                                        v-model="draftFilterSource"
                                        class="admin-input admin-input--select"
                                    >
                                        <option value="all">All</option>
                                        <option value="ai">AI Generated</option>
                                        <option value="admin">Manual</option>
                                    </select>
                                </div>
                            </div>

                            <div class="admin-filterActions">
                                <BaseButton
                                    variant="ghost"
                                    size="sm"
                                    @click="resetDraftFilters"
                                >
                                    Reset
                                </BaseButton>
                                <BaseButton
                                    variant="primary"
                                    size="sm"
                                    @click="applyFilters"
                                >
                                    Apply
                                </BaseButton>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div v-if="loading" class="admin-tableWrap">
                <div class="admin-loadingState">
                    <p>Loading categories...</p>
                </div>
            </div>

            <div
                v-else-if="filteredCategories.length === 0"
                class="admin-tableWrap"
            >
                <div class="admin-emptyState">
                    <p>No categories match the current filters.</p>
                </div>
            </div>

            <div v-else class="admin-tableWrap">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th class="admin-table__th">Company</th>
                            <th class="admin-table__th">Name</th>
                            <th class="admin-table__th">Source</th>
                            <th class="admin-table__th">Status</th>
                            <th class="admin-table__th">Created</th>
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
                            <td class="admin-table__td" data-label="Company">
                                {{ resolveCompanyName(category) }}
                            </td>
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
                            <td class="admin-table__td" data-label="Source">
                                <BaseBadge
                                    :variant="
                                        category.source === 'ai'
                                            ? 'info'
                                            : 'secondary'
                                    "
                                    size="sm"
                                >
                                    {{
                                        category.source === "ai"
                                            ? "AI"
                                            : "Manual"
                                    }}
                                </BaseBadge>
                            </td>
                            <td class="admin-table__td" data-label="Status">
                                <div class="admin-status__wrapper">
                                    <BaseBadge
                                        v-if="category.status === 'active'"
                                        variant="success"
                                        size="sm"
                                        class="admin-statusBadge admin-statusBadge--enabled"
                                    >
                                        <span class="admin-statusBadge__icon"
                                            >●</span
                                        >
                                        <span>Active</span>
                                    </BaseBadge>
                                    <BaseBadge
                                        v-else
                                        variant="warning"
                                        size="sm"
                                        class="admin-statusBadge admin-statusBadge--disabled"
                                    >
                                        <span class="admin-statusBadge__icon"
                                            >○</span
                                        >
                                        <span>Archived</span>
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
                            <td class="admin-table__td" data-label="Created">
                                {{ formatDate(category.created_at) }}
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
                                        @click="
                                            setCategoryStatus(
                                                category,
                                                category.status === 'active'
                                                    ? 'archived'
                                                    : 'active',
                                            )
                                        "
                                        size="sm"
                                        :variant="
                                            category.status === 'active'
                                                ? 'warning'
                                                : 'success'
                                        "
                                        class="admin-actionBtn"
                                        :disabled="
                                            statusUpdatingId === category.id
                                        "
                                        :loading="
                                            statusUpdatingId === category.id
                                        "
                                    >
                                        <span class="admin-actionBtn__icon">{{
                                            category.status === "active"
                                                ? "⊘"
                                                : "●"
                                        }}</span>
                                        <span
                                            v-if="
                                                statusUpdatingId !== category.id
                                            "
                                            class="admin-actionBtn__text"
                                            >{{
                                                category.status === "active"
                                                    ? "Archive"
                                                    : "Activate"
                                            }}</span
                                        >
                                        <span
                                            v-else
                                            class="admin-actionBtn__text"
                                            >{{
                                                category.status === "active"
                                                    ? "Archiving..."
                                                    : "Activating..."
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

            <div class="admin-callsFooter">
                <BasePagination
                    v-model:page="page"
                    v-model:pageSize="pageSize"
                    :total="meta.total"
                    :disabled="loading"
                    :page-size-options="[10, 25, 50, 100, 200]"
                    hint="Server-side pagination"
                    @change="fetchCategories"
                />
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

                            <!-- Company field -->
                            <div class="admin-field">
                                <label
                                    for="company_id"
                                    class="admin-field__label"
                                >
                                    Company *
                                </label>
                                <select
                                    id="company_id"
                                    v-model.number="formData.company_id"
                                    class="admin-input admin-input--select"
                                    :disabled="isEditing"
                                >
                                    <option value="" disabled>
                                        Select Company
                                    </option>
                                    <option
                                        v-for="company in companies"
                                        :key="company.id"
                                        :value="company.id"
                                    >
                                        {{ company.name }}
                                    </option>
                                </select>
                                <div
                                    v-if="validationErrors.company_id"
                                    class="admin-field__error"
                                >
                                    <div
                                        v-for="error in validationErrors.company_id"
                                        :key="error"
                                    >
                                        {{ error }}
                                    </div>
                                </div>
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

        <!-- AI Category Generation Modal -->
        <Teleport to="body">
            <Transition name="admin-modal">
                <div
                    v-if="showAiGenerate"
                    class="admin-modalOverlay"
                    @click="closeAiGenerateModal"
                >
                    <div class="admin-modal" @click.stop>
                        <div class="admin-modal__header">
                            <h2 class="admin-modal__title">
                                Generate categories for
                                {{ companyLabel || "this company" }}
                            </h2>
                            <button
                                type="button"
                                class="admin-modal__close"
                                @click="closeAiGenerateModal"
                            >
                                ✕
                            </button>
                        </div>

                        <div class="admin-modal__body">
                            <div
                                v-if="aiGenerateError"
                                class="admin-alert admin-alert--error"
                            >
                                {{ aiGenerateError }}
                            </div>

                            <div
                                v-if="aiGenerateSuccess"
                                class="admin-alert admin-alert--success"
                            >
                                {{ aiGenerateSuccess }}
                            </div>

                            <div class="admin-field">
                                <label
                                    class="admin-field__label"
                                    for="ai-range"
                                >
                                    Data Range
                                </label>
                                <select
                                    id="ai-range"
                                    v-model="aiGenerateRange"
                                    class="admin-input"
                                >
                                    <option value="last_30_days">
                                        Last 30 days (default)
                                    </option>
                                    <option value="last_60_days">
                                        Last 60 days
                                    </option>
                                    <option value="last_90_days">
                                        Last 90 days
                                    </option>
                                </select>
                                <p
                                    class="admin-card__hint"
                                    style="margin-top: 8px"
                                >
                                    Uses call summaries only (no transcripts).
                                </p>
                            </div>

                            <div class="admin-alert admin-alert--info">
                                <div
                                    style="font-weight: 600; margin-bottom: 4px"
                                >
                                    Summaries to be analyzed
                                </div>
                                <div v-if="aiPreviewLoading">Calculating…</div>
                                <div v-else-if="aiPreviewError">
                                    {{ aiPreviewError }}
                                </div>
                                <div v-else>
                                    {{ aiPreviewCount ?? 0 }} calls
                                </div>
                            </div>

                            <div
                                class="admin-alert admin-alert--info"
                                style="margin-top: 10px"
                            >
                                This regenerates the AI category structure using
                                call summaries. Existing calls will NOT be
                                re-categorized.
                            </div>

                            <div
                                class="admin-alert admin-alert--warning"
                                style="margin-top: 10px"
                            >
                                This will archive previous AI categories for
                                this company only. Manual categories remain
                                unchanged.
                            </div>
                        </div>

                        <div class="admin-modal__footer">
                            <BaseButton
                                @click="closeAiGenerateModal"
                                variant="secondary"
                                :disabled="aiGenerating"
                            >
                                Cancel
                            </BaseButton>
                            <BaseButton
                                @click="submitAiGenerate"
                                variant="primary"
                                :loading="aiGenerating"
                                :disabled="aiGenerating"
                            >
                                Generate
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

    <Teleport to="body">
        <Transition name="admin-modal">
            <div
                v-if="filtersOpen && !isDesktop"
                class="admin-modalOverlay"
                @click="filtersOpen = false"
            >
                <div class="admin-modal" @click.stop>
                    <div class="admin-modal__header">
                        <h2 class="admin-modal__title">Filter Options</h2>
                        <button
                            type="button"
                            class="admin-modal__close"
                            @click="filtersOpen = false"
                        >
                            ✕
                        </button>
                    </div>

                    <div class="admin-modal__body">
                        <div class="admin-filterGrid">
                            <div class="admin-field">
                                <label
                                    class="admin-field__label"
                                    for="filter-company-mobile"
                                >
                                    Company
                                </label>
                                <select
                                    id="filter-company-mobile"
                                    v-model="draftFilterCompany"
                                    class="admin-input admin-input--select"
                                >
                                    <option value="">All Companies</option>
                                    <option
                                        v-for="company in companies"
                                        :key="company.id"
                                        :value="company.id"
                                    >
                                        {{ company.name }}
                                    </option>
                                </select>
                            </div>

                            <div class="admin-field">
                                <label
                                    class="admin-field__label"
                                    for="filter-status-mobile"
                                >
                                    Status
                                </label>
                                <select
                                    id="filter-status-mobile"
                                    v-model="draftFilterStatus"
                                    class="admin-input admin-input--select"
                                >
                                    <option value="all">All</option>
                                    <option value="active">Active</option>
                                    <option value="archived">Archived</option>
                                </select>
                            </div>

                            <div class="admin-field">
                                <label
                                    class="admin-field__label"
                                    for="filter-source-mobile"
                                >
                                    Source
                                </label>
                                <select
                                    id="filter-source-mobile"
                                    v-model="draftFilterSource"
                                    class="admin-input admin-input--select"
                                >
                                    <option value="all">All</option>
                                    <option value="ai">AI Generated</option>
                                    <option value="admin">Manual</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="admin-modal__footer">
                        <BaseButton
                            variant="secondary"
                            @click="resetDraftFilters"
                        >
                            Reset
                        </BaseButton>
                        <BaseButton variant="primary" @click="applyFilters">
                            Apply
                        </BaseButton>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
<script setup>
import { ref, computed, onMounted, onBeforeUnmount, watch } from "vue";
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
const statusUpdatingId = ref(null);
const restoringId = ref(null);
const showSubCatsModal = ref(false);
const selectedCategory = ref(null);
const showAiGenerate = ref(false);
const aiGenerating = ref(false);
const aiGenerateError = ref("");
const aiGenerateSuccess = ref("");
const aiGenerateRange = ref("last_30_days");
const aiPreviewCount = ref(null);
const aiPreviewLoading = ref(false);
const aiPreviewError = ref("");
const filterStatus = ref("all");
const filterSource = ref("all");
const searchQuery = ref("");
const companyLabel = ref("");
const filterCompany = ref("");
const draftFilterCompany = ref("");
const draftFilterStatus = ref("all");
const draftFilterSource = ref("all");
const filtersOpen = ref(false);
const filterWrap = ref(null);
const isDesktop = ref(true);
const companies = ref([]);
const page = ref(1);
const pageSize = ref(25);
const meta = ref({
    current_page: 1,
    last_page: 1,
    per_page: 25,
    total: 0,
});

const formData = ref({
    name: "",
    description: "",
    is_enabled: true,
    company_id: "",
});

// Computed
const filteredCategories = computed(() => {
    let filtered = categories.value.slice();

    // Client-side search filtering only (status and source handled by backend)
    if (searchQuery.value.trim()) {
        const search = searchQuery.value.trim().toLowerCase();
        filtered = filtered.filter((category) =>
            String(category.name || "")
                .toLowerCase()
                .includes(search),
        );
    }

    return filtered;
});

const sortedCategories = computed(() => {
    return filteredCategories.value.slice().sort((a, b) => {
        if (a.status !== b.status) {
            return a.status === "active" ? -1 : 1;
        }
        return a.name.localeCompare(b.name);
    });
});

const isGeneralCategory = computed(() => {
    return isEditing.value && formData.value.name === "General";
});

const isFormValid = computed(() => {
    const hasName = formData.value.name.trim().length > 0;
    const hasCompany = isEditing.value || formData.value.company_id !== "";
    return hasName && hasCompany;
});

// Methods
const fetchCategories = async () => {
    try {
        loading.value = true;
        error.value = null;
        const params = {
            page: page.value,
            per_page: pageSize.value,
        };
        if (filterCompany.value) {
            params.company_id = filterCompany.value;
        }
        if (filterStatus.value && filterStatus.value !== "all") {
            params.status = filterStatus.value;
        }
        if (filterSource.value && filterSource.value !== "all") {
            params.source = filterSource.value;
        }
        const response = await adminApi.get("/categories", { params });
        categories.value = response.data.data;
        meta.value = response.data.meta ?? meta.value;
    } catch (err) {
        error.value = err.message || "Failed to load categories";
    } finally {
        loading.value = false;
    }
};

const fetchCompanies = async () => {
    try {
        const response = await adminApi.get("/companies");
        companies.value = response?.data?.data || [];
    } catch (err) {
        companies.value = [];
    }
};

const fetchCompanyLabel = async () => {
    try {
        const response = await adminApi.get("/me");
        companyLabel.value =
            response?.data?.company?.name ||
            response?.data?.company_name ||
            response?.data?.companyName ||
            "";
    } catch (err) {
        companyLabel.value = "";
    }
};

function resetDraftFilters() {
    draftFilterCompany.value = "";
    draftFilterStatus.value = "all";
    draftFilterSource.value = "all";
}

function syncDraftFilters() {
    draftFilterCompany.value = filterCompany.value;
    draftFilterStatus.value = filterStatus.value;
    draftFilterSource.value = filterSource.value;
}

function applyFilters() {
    filterCompany.value = draftFilterCompany.value;
    filterStatus.value = draftFilterStatus.value;
    filterSource.value = draftFilterSource.value;
    filtersOpen.value = false;
    page.value = 1; // Reset to first page when filters change
    fetchCategories();
}

function toggleFilters() {
    filtersOpen.value = !filtersOpen.value;
    if (filtersOpen.value) {
        syncDraftFilters();
    }
}

function updateViewport() {
    isDesktop.value = window.innerWidth >= 1024;
}

function onDocumentClick(event) {
    if (!filtersOpen.value || !isDesktop.value) return;
    const target = event.target;
    if (!filterWrap.value || !(target instanceof Node)) return;
    if (filterWrap.value.contains(target)) return;
    filtersOpen.value = false;
}

function resolveCompanyName(category) {
    if (category?.company?.name) return category.company.name;
    if (category?.company_name) return category.company_name;
    return companyLabel.value || "—";
}

function showToast(message, type = "success") {
    try {
        let container = document.getElementById("__category_toast_container");
        if (!container) {
            container = document.createElement("div");
            container.id = "__category_toast_container";
            Object.assign(container.style, {
                position: "fixed",
                top: "16px",
                right: "16px",
                zIndex: 9999,
                display: "flex",
                flexDirection: "column",
                gap: "8px",
            });
            document.body.appendChild(container);
        }

        const el = document.createElement("div");
        el.textContent = message;
        const bgColor = type === "error" ? "#dc3545" : "#0f5132";
        Object.assign(el.style, {
            background: bgColor,
            color: "white",
            padding: "10px 14px",
            borderRadius: "8px",
            boxShadow: "0 6px 18px rgba(16,24,40,0.12)",
            opacity: "0",
            transition: "opacity 200ms ease, transform 200ms ease",
            transform: "translateY(-6px)",
            fontSize: "14px",
            lineHeight: "20px",
        });

        container.appendChild(el);

        // animate in
        requestAnimationFrame(() => {
            el.style.opacity = "1";
            el.style.transform = "translateY(0)";
        });

        setTimeout(() => {
            // animate out
            el.style.opacity = "0";
            el.style.transform = "translateY(-6px)";
            setTimeout(() => el.remove(), 220);
        }, 3000);
    } catch (e) {
        // fallback
        // eslint-disable-next-line no-alert
        alert(message);
    }
}

const openAddForm = () => {
    isEditing.value = false;
    showForm.value = true;
    formData.value = {
        name: "",
        description: "",
        is_enabled: true,
        company_id: filterCompany.value || "",
    };
    validationErrors.value = {};
};

const openAiGenerateModal = () => {
    showAiGenerate.value = true;
    aiGenerateError.value = "";
    aiGenerateSuccess.value = "";
    fetchAiPreview();
};

const closeAiGenerateModal = () => {
    showAiGenerate.value = false;
};

const fetchAiPreview = async () => {
    aiPreviewError.value = "";
    aiPreviewLoading.value = true;

    try {
        const response = await adminApi.get("/categories/ai-generate/preview", {
            params: {
                range: aiGenerateRange.value,
            },
        });
        aiPreviewCount.value = response?.data?.data?.summary_count ?? 0;
    } catch (err) {
        aiPreviewError.value =
            err?.response?.data?.message ||
            err?.message ||
            "Failed to load preview.";
    } finally {
        aiPreviewLoading.value = false;
    }
};

const submitAiGenerate = async () => {
    aiGenerateError.value = "";
    aiGenerateSuccess.value = "";

    const label = companyLabel.value || "this company";
    const confirmed = window.confirm(
        `This will archive previous AI categories for ${label} only. Continue?`,
    );
    if (!confirmed) {
        return;
    }

    const payload = {
        range: aiGenerateRange.value,
    };

    try {
        aiGenerating.value = true;
        await adminApi.post("/categories/ai-generate", payload);
        aiGenerateSuccess.value = "AI category generation queued.";
        await fetchCategories();
    } catch (err) {
        aiGenerateError.value =
            err?.response?.data?.message ||
            err?.message ||
            "Failed to queue AI category generation.";
    } finally {
        aiGenerating.value = false;
    }
};

watch(aiGenerateRange, () => {
    if (showAiGenerate.value) {
        fetchAiPreview();
    }
});

const openEditForm = (category) => {
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
        company_id: "",
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
                company_id: formData.value.company_id,
            });
        }

        await fetchCategories();
        closeForm();
        showToast(
            isEditing.value
                ? "Category updated successfully."
                : "Category created successfully.",
        );
    } catch (err) {
        if (err.response?.data?.errors) {
            validationErrors.value = err.response.data.errors;
        } else if (err.response?.data?.message) {
            const errorMsg = err.response.data.message;
            // Show duplicate error in toast
            if (
                errorMsg.includes("already exists") ||
                errorMsg.includes("duplicate")
            ) {
                showToast(errorMsg, "error");
            }
            validationErrors.value.general = [errorMsg];
        } else {
            error.value = err.message || "Failed to save category";
            showToast("Failed to save category", "error");
        }
    } finally {
        submitting.value = false;
    }
};

const setCategoryStatus = async (category, status) => {
    try {
        statusUpdatingId.value = category.id;
        await adminApi.put(`/categories/${category.id}`, {
            status,
        });
        await fetchCategories();
        showToast(
            `Category ${status === "active" ? "activated" : "archived"} successfully.`,
        );
    } catch (err) {
        if (err.response?.data?.message) {
            showToast(err.response.data.message, "error");
            validationErrors.value.general = [err.response.data.message];
        } else {
            error.value = err.message || "Failed to toggle category";
        }
    } finally {
        statusUpdatingId.value = null;
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
        showToast("Category deleted successfully.");
    } catch (err) {
        if (err.response?.data?.message) {
            showToast(err.response.data.message, "error");
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

const formatDate = (dateValue) => {
    if (!dateValue) return "—";
    const date = new Date(dateValue);
    if (Number.isNaN(date.getTime())) return "—";
    return date.toLocaleDateString();
};

// Lifecycle
onMounted(() => {
    updateViewport();
    window.addEventListener("resize", updateViewport);
    document.addEventListener("click", onDocumentClick);
    fetchCompanies();
    fetchCategories();
    fetchCompanyLabel();
});

onBeforeUnmount(() => {
    window.removeEventListener("resize", updateViewport);
    document.removeEventListener("click", onDocumentClick);
});
</script>
