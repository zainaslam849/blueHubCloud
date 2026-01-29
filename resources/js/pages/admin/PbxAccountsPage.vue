<template>
    <div class="admin-container admin-page">
        <header class="admin-page__header">
            <div>
                <p class="admin-page__kicker">Infrastructure</p>
                <h1 class="admin-page__title">PBX Accounts</h1>
                <p class="admin-page__subtitle">
                    Manage company PBX server connections and credentials.
                </p>
            </div>
            <BaseButton variant="primary" size="md" @click="openCreateModal">
                + Add PBX Account
            </BaseButton>
        </header>

        <section class="admin-card admin-card--glass">
            <div v-if="error" class="admin-alert admin-alert--error">
                {{ error }}
            </div>

            <div v-if="loading" class="admin-tableWrap">
                <div class="admin-loadingState">
                    <p>Loading PBX accounts...</p>
                </div>
            </div>

            <div v-else-if="rows.length === 0" class="admin-tableWrap">
                <div class="admin-emptyState">
                    <p>No PBX accounts yet.</p>
                </div>
            </div>

            <div v-else class="admin-tableWrap">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th class="admin-table__th">Company</th>
                            <th class="admin-table__th">Server ID</th>
                            <th class="admin-table__th">PBX Provider</th>
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
                            v-for="account in rows"
                            :key="account.id"
                            class="admin-table__tr"
                        >
                            <td class="admin-table__td" data-label="Company">
                                <div class="font-medium">
                                    {{ account.company_name }}
                                </div>
                            </td>
                            <td class="admin-table__td" data-label="Server ID">
                                <code>{{ account.server_id }}</code>
                            </td>
                            <td
                                class="admin-table__td"
                                data-label="PBX Provider"
                            >
                                {{ account.pbx_provider_name || "—" }}
                            </td>
                            <td class="admin-table__td" data-label="Status">
                                <BaseBadge
                                    :variant="
                                        account.status === 'active'
                                            ? 'success'
                                            : 'secondary'
                                    "
                                    size="sm"
                                >
                                    {{ account.status }}
                                </BaseBadge>
                            </td>
                            <td
                                class="admin-table__td admin-table__td--actions"
                                data-label="Actions"
                            >
                                <div class="admin-table__actions">
                                    <BaseButton
                                        @click="openEditModal(account)"
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
                                        @click="openDeleteConfirm(account)"
                                        size="sm"
                                        variant="danger"
                                        class="admin-actionBtn admin-actionBtn--delete"
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

        <!-- Create/Edit Modal -->
        <Teleport to="body">
            <Transition name="admin-modal">
                <div
                    v-if="showModal"
                    class="admin-modalOverlay"
                    @click="closeModal"
                >
                    <div class="admin-modal" @click.stop>
                        <div class="admin-modal__header">
                            <h2 class="admin-modal__title">
                                {{ isEditing ? "Edit" : "Add" }} PBX Account
                            </h2>
                            <button
                                type="button"
                                class="admin-modal__close"
                                @click="closeModal"
                            >
                                ✕
                            </button>
                        </div>

                        <div class="admin-modal__body">
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
                                    v-model="form.company_id"
                                    class="admin-input"
                                    required
                                >
                                    <option value="">Select company...</option>
                                    <option
                                        v-for="company in companies"
                                        :key="company.id"
                                        :value="company.id"
                                    >
                                        {{ company.name }}
                                    </option>
                                </select>
                            </div>

                            <!-- Server ID field -->
                            <div class="admin-field">
                                <label
                                    for="server_id"
                                    class="admin-field__label"
                                >
                                    Server ID *
                                </label>
                                <input
                                    id="server_id"
                                    v-model="form.server_id"
                                    type="text"
                                    class="admin-input"
                                    placeholder="e.g., 79"
                                    required
                                />
                                <p class="admin-field__hint">
                                    Get this from pbxware.tenant.list API call
                                </p>
                            </div>

                            <!-- PBX Provider (Fixed/Display Only) -->
                            <div class="admin-field">
                                <label class="admin-field__label">
                                    PBX Provider
                                </label>
                                <div
                                    class="admin-input"
                                    style="
                                        background-color: var(
                                            --color-bg-secondary
                                        );
                                        cursor: default;
                                        display: flex;
                                        align-items: center;
                                        color: var(--color-text-secondary);
                                    "
                                >
                                    BHubcomms
                                </div>
                            </div>
                        </div>

                        <div class="admin-modal__footer">
                            <BaseButton
                                type="button"
                                variant="secondary"
                                size="md"
                                @click="closeModal"
                            >
                                Cancel
                            </BaseButton>
                            <BaseButton
                                type="button"
                                variant="primary"
                                size="md"
                                :loading="saving"
                                @click="saveAccount"
                            >
                                {{ isEditing ? "Update" : "Create" }}
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
                            <div class="admin-modal__headerIcon">⚠️</div>
                            <h2
                                class="admin-modal__title admin-modal__title--danger"
                            >
                                Delete PBX Account?
                            </h2>
                        </div>
                        <div class="admin-modal__body">
                            <p style="margin: 0">
                                Are you sure you want to delete the PBX account
                                for
                                <strong>{{ deleteTarget?.company_name }}</strong
                                >? This action cannot be undone.
                            </p>
                        </div>
                        <div
                            class="admin-modal__footer admin-modal__footer--confirm"
                        >
                            <BaseButton
                                variant="secondary"
                                size="md"
                                @click="showDeleteConfirm = false"
                            >
                                Cancel
                            </BaseButton>
                            <BaseButton
                                variant="danger"
                                size="md"
                                :loading="deleting"
                                @click="confirmDelete"
                            >
                                Delete Account
                            </BaseButton>
                        </div>
                    </div>
                </div>
            </Transition>
        </Teleport>
    </div>
</template>

<script setup>
import { onMounted, ref } from "vue";
import adminApi from "../../router/admin/api";
import { BaseBadge, BaseButton } from "../../components/admin/base";

const loading = ref(true);
const error = ref("");
const rows = ref([]);
const companies = ref([]);

const showModal = ref(false);
const saving = ref(false);
const isEditing = ref(false);
const editingId = ref(null);

const form = ref({
    company_id: "",
    pbx_provider_id: 1, // BHubcomms (fixed)
    server_id: "",
});

const showDeleteConfirm = ref(false);
const deleteTarget = ref(null);
const deleting = ref(false);

async function fetchAccounts() {
    loading.value = true;
    error.value = "";

    try {
        const res = await adminApi.get("/pbx-accounts");
        rows.value = res?.data?.data || [];
    } catch (e) {
        rows.value = [];
        error.value = "Failed to load PBX accounts.";
    } finally {
        loading.value = false;
    }
}

async function fetchCompanies() {
    try {
        const res = await adminApi.get("/companies");
        companies.value = res?.data?.data || [];
    } catch (e) {
        console.error("Failed to load companies", e);
    }
}

function openCreateModal() {
    isEditing.value = false;
    editingId.value = null;
    form.value = {
        company_id: "",
        pbx_provider_id: 1, // BHubcomms
        server_id: "",
    };
    showModal.value = true;
}

function openEditModal(account) {
    isEditing.value = true;
    editingId.value = account.id;
    form.value = {
        company_id: account.company_id,
        pbx_provider_id: account.pbx_provider_id,
        server_id: account.server_id,
    };
    showModal.value = true;
}

function closeModal() {
    showModal.value = false;
}

async function saveAccount() {
    saving.value = true;
    error.value = "";

    try {
        if (isEditing.value) {
            await adminApi.put(`/pbx-accounts/${editingId.value}`, form.value);
        } else {
            await adminApi.post("/pbx-accounts", form.value);
        }

        await fetchAccounts();
        closeModal();
    } catch (e) {
        error.value =
            e?.response?.data?.message || "Failed to save PBX account.";
    } finally {
        saving.value = false;
    }
}

function openDeleteConfirm(account) {
    deleteTarget.value = account;
    showDeleteConfirm.value = true;
}

async function confirmDelete() {
    if (!deleteTarget.value) return;

    deleting.value = true;
    error.value = "";

    try {
        await adminApi.delete(`/pbx-accounts/${deleteTarget.value.id}`);
        await fetchAccounts();
        showDeleteConfirm.value = false;
        deleteTarget.value = null;
    } catch (e) {
        error.value =
            e?.response?.data?.message || "Failed to delete PBX account.";
    } finally {
        deleting.value = false;
    }
}

onMounted(() => {
    fetchAccounts();
    fetchCompanies();
});
</script>
