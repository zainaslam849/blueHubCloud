<template>
    <div class="admin-container admin-page">
        <header class="admin-page__header">
            <div>
                <p class="admin-page__kicker">Accounts</p>
                <h1 class="admin-page__title">Companies</h1>
                <p class="admin-page__subtitle">
                    Manage companies and PBXware server assignments.
                </p>
            </div>
            <div style="display: flex; gap: 10px; flex-wrap: wrap">
                <BaseButton
                    v-if="pbxProviders.length > 0"
                    variant="secondary"
                    size="md"
                    @click="openSyncModal"
                    :loading="syncing"
                >
                    🔄 Sync Tenants
                </BaseButton>
                <BaseButton variant="primary" size="md" @click="openAddForm">
                    + Add Company
                </BaseButton>
            </div>
        </header>

        <section class="admin-card admin-card--glass">
            <div v-if="error" class="admin-alert admin-alert--error">
                {{ error }}
            </div>

            <div v-if="loading" class="admin-tableWrap">
                <div class="admin-loadingState">
                    <p>Loading companies...</p>
                </div>
            </div>

            <div v-else-if="companies.length === 0" class="admin-tableWrap">
                <div class="admin-emptyState">
                    <p>No companies found.</p>
                </div>
            </div>

            <div v-else class="admin-tableWrap">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th class="admin-table__th">Name</th>
                            <th class="admin-table__th">Server ID</th>
                            <th class="admin-table__th">Tenant Code</th>
                            <th class="admin-table__th">Package</th>
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
                            v-for="company in companies"
                            :key="company.id"
                            class="admin-table__tr"
                        >
                            <td class="admin-table__td" data-label="Name">
                                <span class="font-medium">{{
                                    company.name
                                }}</span>
                            </td>
                            <td class="admin-table__td" data-label="Server ID">
                                <code v-if="company.server_id">{{
                                    company.server_id
                                }}</code>
                                <span v-else class="text-muted">—</span>
                            </td>
                            <td
                                class="admin-table__td"
                                data-label="Tenant Code"
                            >
                                <code v-if="company.tenant_code">{{
                                    company.tenant_code
                                }}</code>
                                <span v-else class="text-muted">—</span>
                            </td>
                            <td class="admin-table__td" data-label="Package">
                                <span v-if="company.package_name">{{
                                    company.package_name
                                }}</span>
                                <span v-else class="text-muted">—</span>
                            </td>
                            <td class="admin-table__td" data-label="Status">
                                <span
                                    :class="[
                                        'admin-status-badge',
                                        company.status === 'active'
                                            ? 'admin-status-badge--active'
                                            : 'admin-status-badge--inactive'
                                    ]"
                                >
                                    {{ company.status === 'active' ? 'Active' : 'Inactive' }}
                                </span>
                            </td>
                            <td
                                class="admin-table__td admin-table__td--actions"
                                data-label="Actions"
                            >
                                <div class="admin-table__actions">
                                    <BaseButton
                                        @click="openEditForm(company)"
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
                                        @click="openDeleteConfirm(company)"
                                        size="sm"
                                        variant="danger"
                                        class="admin-actionBtn admin-actionBtn--delete"
                                        :disabled="
                                            deleting &&
                                            deleteTarget?.id === company.id
                                        "
                                    >
                                        <span class="admin-actionBtn__icon"
                                            >🗑</span
                                        >
                                        <span class="admin-actionBtn__text"
                                            >Delete</span
                                        >
                                    </BaseButton>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Add/Edit Company Modal -->
        <Teleport to="body">
            <Transition name="admin-modal">
                <div
                    v-if="showForm"
                    class="admin-modalOverlay"
                    @click="closeForm"
                >
                    <div class="admin-modal" @click.stop>
                        <div class="admin-modal__header">
                            <h2 class="admin-modal__title">
                                {{
                                    isEditing
                                        ? "Edit Company"
                                        : "Add New Company"
                                }}
                            </h2>
                            <button
                                type="button"
                                class="admin-modal__close"
                                @click="closeForm"
                            >
                                ✕
                            </button>
                        </div>

                        <div
                            class="admin-modal__body"
                            style="max-height: 60vh; overflow-y: auto"
                        >
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

                            <div class="admin-field">
                                <label
                                    class="admin-field__label"
                                    for="company-name"
                                >
                                    Company Name*
                                </label>
                                <input
                                    id="company-name"
                                    v-model="formData.name"
                                    class="admin-input"
                                    type="text"
                                    placeholder="Enter company name"
                                />
                                <span
                                    v-if="validationErrors.name"
                                    class="admin-field__error"
                                >
                                    {{ validationErrors.name[0] }}
                                </span>
                            </div>

                            <div class="admin-field">
                                <label
                                    class="admin-field__label"
                                    for="company-timezone"
                                >
                                    Timezone
                                </label>
                                <select
                                    id="company-timezone"
                                    v-model="formData.timezone"
                                    class="admin-input admin-input--select"
                                >
                                    <option value="UTC">UTC (Coordinated Universal Time)</option>
                                    <option value="America/New_York">America/New_York (EST/EDT)</option>
                                    <option value="America/Chicago">America/Chicago (CST/CDT)</option>
                                    <option value="America/Denver">America/Denver (MST/MDT)</option>
                                    <option value="America/Los_Angeles">America/Los_Angeles (PST/PDT)</option>
                                    <option value="America/Phoenix">America/Phoenix (MST)</option>
                                    <option value="America/Toronto">America/Toronto</option>
                                    <option value="America/Vancouver">America/Vancouver</option>
                                    <option value="Europe/London">Europe/London (GMT/BST)</option>
                                    <option value="Europe/Paris">Europe/Paris (CET/CEST)</option>
                                    <option value="Europe/Berlin">Europe/Berlin (CET/CEST)</option>
                                    <option value="Asia/Dubai">Asia/Dubai (GST)</option>
                                    <option value="Asia/Kolkata">Asia/Kolkata (IST)</option>
                                    <option value="Asia/Singapore">Asia/Singapore (SGT)</option>
                                    <option value="Asia/Tokyo">Asia/Tokyo (JST)</option>
                                    <option value="Australia/Sydney">Australia/Sydney (AEDT/AEST)</option>
                                    <option value="Pacific/Auckland">Pacific/Auckland (NZDT/NZST)</option>
                                </select>
                            </div>

                            <div class="admin-field">
                                <label
                                    class="admin-field__label"
                                    for="company-pbx-provider"
                                >
                                    PBX Provider
                                </label>
                                <select
                                    id="company-pbx-provider"
                                    v-model="formData.pbx_provider_id"
                                    class="admin-input admin-input--select"
                                >
                                    <option value="">
                                        — Select Provider —
                                    </option>
                                    <option
                                        v-for="provider in pbxProviders"
                                        :key="provider.id"
                                        :value="provider.id"
                                    >
                                        {{ provider.name }}
                                    </option>
                                </select>
                            </div>

                            <div
                                v-if="formData.pbx_provider_id"
                                class="admin-field"
                            >
                                <label
                                    class="admin-field__label"
                                    for="company-server"
                                >
                                    PBXware Server ID
                                </label>
                                <input
                                    id="company-server"
                                    v-model="formData.server_id"
                                    class="admin-input"
                                    type="text"
                                    placeholder="e.g., 3, 83, 23"
                                />
                                <p class="admin-field__help">
                                    Enter the Server ID from PBXware. This is
                                    automatically populated when you sync
                                    tenants.
                                </p>
                                <span
                                    v-if="validationErrors.server_id"
                                    class="admin-field__error"
                                >
                                    {{ validationErrors.server_id[0] }}
                                </span>
                            </div>

                            <div class="admin-field">
                                <label
                                    class="admin-field__label"
                                    for="company-tenant-code"
                                >
                                    Tenant Code
                                </label>
                                <input
                                    id="company-tenant-code"
                                    v-model="formData.tenant_code"
                                    class="admin-input"
                                    type="text"
                                    placeholder="e.g., 501"
                                />
                                <span
                                    v-if="validationErrors.tenant_code"
                                    class="admin-field__error"
                                >
                                    {{ validationErrors.tenant_code[0] }}
                                </span>
                            </div>

                            <div class="admin-field">
                                <label
                                    class="admin-field__label"
                                    for="company-status"
                                >
                                    Status
                                </label>
                                <div class="admin-toggle-wrapper">
                                    <label class="admin-toggle">
                                        <input
                                            id="company-status"
                                            type="checkbox"
                                            :checked="
                                                formData.status === 'active'
                                            "
                                            @change="
                                                formData.status =
                                                    formData.status === 'active'
                                                        ? 'inactive'
                                                        : 'active'
                                            "
                                        />
                                        <span
                                            class="admin-toggle__slider"
                                        ></span>
                                        <span class="admin-toggle__label">
                                            {{
                                                formData.status === "active"
                                                    ? "Active"
                                                    : "Inactive"
                                            }}
                                        </span>
                                    </label>
                                    <p
                                        class="admin-field__help"
                                        style="margin-top: 0.5rem"
                                    >
                                        Only active companies will process PBX
                                        call records.
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div class="admin-modal__footer">
                            <BaseButton variant="secondary" @click="closeForm">
                                Cancel
                            </BaseButton>
                            <BaseButton
                                variant="primary"
                                @click="submitForm"
                                :loading="submitting"
                            >
                                {{ isEditing ? "Update" : "Create" }}
                            </BaseButton>
                        </div>
                    </div>
                </div>
            </Transition>
        </Teleport>

        <!-- Sync Tenants Modal -->
        <Teleport to="body">
            <Transition name="admin-modal">
                <div
                    v-if="showSyncModal"
                    class="admin-modalOverlay"
                    @click="closeSyncModal"
                >
                    <div class="admin-modal" @click.stop>
                        <div class="admin-modal__header">
                            <h2 class="admin-modal__title">
                                Sync PBXware Tenants
                            </h2>
                            <button
                                type="button"
                                class="admin-modal__close"
                                @click="closeSyncModal"
                            >
                                ✕
                            </button>
                        </div>

                        <div
                            class="admin-modal__body"
                            style="max-height: 60vh; overflow-y: auto"
                        >
                            <div
                                v-if="syncError"
                                class="admin-alert admin-alert--error"
                            >
                                {{ syncError }}
                            </div>

                            <div v-if="syncing" class="admin-loadingState">
                                <p>Fetching tenants from PBXware...</p>
                            </div>

                            <div v-else-if="syncResult">
                                <div
                                    v-if="syncResult.new_count > 0"
                                    class="admin-alert admin-alert--success"
                                >
                                    Found
                                    <strong>{{ syncResult.new_count }}</strong>
                                    new tenant(s)
                                </div>
                                <div
                                    v-if="syncResult.existing_count > 0"
                                    class="admin-alert admin-alert--info"
                                >
                                    Updated
                                    <strong>{{
                                        syncResult.existing_count
                                    }}</strong>
                                    existing tenant(s)
                                </div>

                                <div
                                    v-if="
                                        syncResult.new_tenants &&
                                        syncResult.new_tenants.length > 0
                                    "
                                    style="margin-top: 16px"
                                >
                                    <h3
                                        style="
                                            font-size: 14px;
                                            font-weight: 600;
                                        "
                                    >
                                        New Tenants
                                    </h3>
                                    <ul
                                        style="
                                            list-style: none;
                                            padding: 0;
                                            margin-top: 8px;
                                        "
                                    >
                                        <li
                                            v-for="tenant in syncResult.new_tenants"
                                            :key="tenant.server_id"
                                            style="
                                                padding: 8px;
                                                border: 1px solid
                                                    var(--admin-border);
                                                border-radius: 6px;
                                                margin-bottom: 8px;
                                                font-size: 13px;
                                            "
                                        >
                                            <strong>{{ tenant.name }}</strong>
                                            ({{ tenant.tenant_code }}) -
                                            {{ tenant.package }}
                                        </li>
                                    </ul>
                                </div>
                            </div>

                            <div v-else class="admin-field">
                                <label
                                    class="admin-field__label"
                                    for="sync-pbx-provider"
                                >
                                    Select PBX Provider
                                </label>
                                <select
                                    id="sync-pbx-provider"
                                    v-model="syncFormData.pbx_provider_id"
                                    class="admin-input admin-input--select"
                                >
                                    <option value="">
                                        — Choose Provider —
                                    </option>
                                    <option
                                        v-for="provider in pbxProviders"
                                        :key="provider.id"
                                        :value="provider.id"
                                    >
                                        {{ provider.name }}
                                    </option>
                                </select>
                            </div>
                        </div>

                        <div class="admin-modal__footer">
                            <BaseButton
                                v-if="!syncResult"
                                variant="secondary"
                                @click="closeSyncModal"
                            >
                                Cancel
                            </BaseButton>
                            <BaseButton
                                v-if="!syncResult"
                                variant="primary"
                                @click="performSync"
                                :loading="syncing"
                                :disabled="
                                    !syncFormData.pbx_provider_id || syncing
                                "
                            >
                                Sync Now
                            </BaseButton>
                            <BaseButton
                                v-else
                                variant="primary"
                                @click="closeSyncModal"
                            >
                                Done
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
                    @click="closeDeleteConfirm"
                >
                    <div class="admin-modal" @click.stop>
                        <div class="admin-modal__header">
                            <h2 class="admin-modal__title">Delete Company</h2>
                            <button
                                type="button"
                                class="admin-modal__close"
                                @click="closeDeleteConfirm"
                            >
                                ✕
                            </button>
                        </div>

                        <div class="admin-modal__body">
                            <p>
                                Are you sure you want to delete
                                <strong>{{ deleteTarget?.name }}</strong>
                                ? This action cannot be undone.
                            </p>
                        </div>

                        <div class="admin-modal__footer">
                            <BaseButton
                                variant="secondary"
                                @click="closeDeleteConfirm"
                            >
                                Cancel
                            </BaseButton>
                            <BaseButton
                                variant="danger"
                                @click="confirmDelete"
                                :loading="deleting"
                            >
                                Delete
                            </BaseButton>
                        </div>
                    </div>
                </div>
            </Transition>
        </Teleport>
    </div>
</template>

<script setup>
import { onMounted, ref, reactive, watch } from "vue";
import adminApi from "../../router/admin/api";
import { BaseBadge, BaseButton } from "../../components/admin/base";

const companies = ref([]);
const loading = ref(true);
const error = ref("");
const pbxProviders = ref([]);

// Form state
const showForm = ref(false);
const isEditing = ref(false);
const submitting = ref(false);
const validationErrors = ref({});
const currentAvailableTenants = ref([]);

const formData = reactive({
    id: null,
    name: "",
    timezone: "UTC",
    status: "active",
    pbx_provider_id: "",
    server_id: "",
    tenant_code: "",
});

const defaultFormData = {
    id: null,
    name: "",
    timezone: "UTC",
    status: "active",
    pbx_provider_id: "",
    server_id: "",
    tenant_code: "",
};

// Sync modal state
const showSyncModal = ref(false);
const syncing = ref(false);
const syncError = ref("");
const syncResult = ref(null);
const syncFormData = reactive({ pbx_provider_id: "" });

// Delete confirmation state
const showDeleteConfirm = ref(false);
const deleteTarget = ref(null);
const deleting = ref(false);

async function loadCompanies() {
    loading.value = true;
    error.value = "";
    try {
        const res = await adminApi.get("/companies");
        companies.value = res?.data?.data || [];
    } catch (e) {
        error.value = e?.response?.data?.message || "Failed to load companies.";
    } finally {
        loading.value = false;
    }
}

async function loadPbxProviders() {
    try {
        const res = await adminApi.get("/pbx-providers");
        pbxProviders.value = res?.data?.data || [];
    } catch (e) {
        console.error("Failed to load PBX providers", e);
    }
}

async function loadAvailableTenants(providerId) {
    if (!providerId) {
        currentAvailableTenants.value = [];
        return;
    }
    try {
        const res = await adminApi.get("/companies/available-tenants", {
            params: { pbx_provider_id: providerId },
        });
        currentAvailableTenants.value = res?.data?.data || [];
    } catch (e) {
        currentAvailableTenants.value = [];
    }
}

watch(
    () => formData.server_id,
    (serverId) => {
        if (!serverId) return;
        const match = currentAvailableTenants.value.find(
            (tenant) => tenant.server_id === serverId,
        );
        if (match && match.tenant_code) {
            formData.tenant_code = match.tenant_code;
        }
    },
);

function openAddForm() {
    isEditing.value = false;
    Object.assign(formData, defaultFormData);
    validationErrors.value = {};
    currentAvailableTenants.value = [];
    showForm.value = true;
}

function openEditForm(company) {
    isEditing.value = true;
    formData.id = company.id;
    formData.name = company.name;
    formData.timezone = company.timezone || "UTC";
    formData.status = company.status;
    formData.pbx_provider_id = company.pbx_provider_id || "";
    formData.server_id = company.server_id || "";
    formData.tenant_code = company.tenant_code || "";
    validationErrors.value = {};
    loadAvailableTenants(formData.pbx_provider_id);
    showForm.value = true;
}

function closeForm() {
    showForm.value = false;
    Object.assign(formData, defaultFormData);
    validationErrors.value = {};
    currentAvailableTenants.value = [];
}

async function submitForm() {
    validationErrors.value = {};
    submitting.value = true;

    try {
        const data = {
            name: formData.name,
            timezone: formData.timezone,
            status: formData.status,
            pbx_provider_id: formData.pbx_provider_id || null,
            server_id: formData.server_id || null,
            tenant_code: formData.tenant_code || null,
        };

        if (isEditing.value && formData.id) {
            await adminApi.put(`/companies/${formData.id}`, data);
        } else {
            await adminApi.post("/companies", data);
        }

        showToast(
            isEditing.value
                ? "Company updated successfully."
                : "Company created successfully.",
        );
        await loadCompanies();
        closeForm();
    } catch (err) {
        if (err.response?.data?.errors) {
            validationErrors.value = err.response.data.errors;
        } else if (err.response?.data?.message) {
            validationErrors.value.general = [err.response.data.message];
        } else {
            error.value = err.message || "Failed to save company";
        }
    } finally {
        submitting.value = false;
    }
}

function openSyncModal() {
    showSyncModal.value = true;
    syncError.value = "";
    syncResult.value = null;
    syncFormData.pbx_provider_id = "";
}

function closeSyncModal() {
    showSyncModal.value = false;
    syncError.value = "";
    syncResult.value = null;
}

async function performSync() {
    if (!syncFormData.pbx_provider_id) return;

    syncing.value = true;
    syncError.value = "";

    try {
        const res = await adminApi.post("/companies/sync-tenants", {
            pbx_provider_id: syncFormData.pbx_provider_id,
        });
        syncResult.value = res?.data || {};
        showToast("Tenants synced successfully!");
        await loadCompanies();
    } catch (err) {
        syncError.value =
            err?.response?.data?.message || "Failed to sync tenants";
    } finally {
        syncing.value = false;
    }
}

function openDeleteConfirm(company) {
    deleteTarget.value = company;
    showDeleteConfirm.value = true;
}

function closeDeleteConfirm() {
    showDeleteConfirm.value = false;
    deleteTarget.value = null;
}

async function confirmDelete() {
    if (!deleteTarget.value) return;

    deleting.value = true;
    try {
        await adminApi.delete(`/companies/${deleteTarget.value.id}`);
        showToast("Company deleted successfully.");
        await loadCompanies();
        closeDeleteConfirm();
    } catch (err) {
        showToast(
            err?.response?.data?.message || "Failed to delete company",
            "error",
        );
    } finally {
        deleting.value = false;
    }
}

function showToast(message, type = "success") {
    try {
        let container = document.getElementById("__company_toast_container");
        if (!container) {
            container = document.createElement("div");
            container.id = "__company_toast_container";
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

        requestAnimationFrame(() => {
            el.style.opacity = "1";
            el.style.transform = "translateY(0)";
        });

        setTimeout(() => {
            el.style.opacity = "0";
            el.style.transform = "translateY(-6px)";
            setTimeout(() => el.remove(), 220);
        }, 3000);
    } catch (e) {
        alert(message);
    }
}

onMounted(async () => {
    await loadPbxProviders();
    await loadCompanies();
});
</script>
