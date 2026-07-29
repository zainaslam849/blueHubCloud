<template>
    <div class="admin-container admin-page">
        <header class="admin-page__header">
            <div>
                <p class="admin-page__kicker">Infrastructure</p>
                <h1 class="admin-page__title">PBX Servers</h1>
                <p class="admin-page__subtitle">
                    Manage PBX servers and their API keys. Each server has its
                    own API key stored securely in AWS and its own set of
                    tenants.
                </p>
            </div>
            <BaseButton variant="primary" size="md" @click="openCreateModal">
                + Add Server
            </BaseButton>
        </header>

        <section class="admin-card admin-card--glass">
            <div v-if="error" class="admin-alert admin-alert--error">
                {{ error }}
            </div>
            <div v-if="notice" class="admin-alert admin-alert--success">
                {{ notice }}
            </div>

            <div v-if="loading" class="admin-tableWrap">
                <div class="admin-loadingState">
                    <p>Loading PBX servers...</p>
                </div>
            </div>

            <div v-else-if="rows.length === 0" class="admin-tableWrap">
                <div class="admin-emptyState">
                    <p>No PBX servers yet.</p>
                </div>
            </div>

            <div v-else class="admin-tableWrap">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th class="admin-table__th">Name</th>
                            <th class="admin-table__th">Hostname</th>
                            <th class="admin-table__th">Tenants</th>
                            <th class="admin-table__th">Accounts</th>
                            <th class="admin-table__th">Last Sync</th>
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
                            v-for="server in rows"
                            :key="server.id"
                            class="admin-table__tr"
                        >
                            <td class="admin-table__td" data-label="Name">
                                <div class="font-medium">
                                    {{ server.name }}
                                    <BaseBadge
                                        v-if="server.is_default"
                                        variant="info"
                                        size="sm"
                                    >
                                        default
                                    </BaseBadge>
                                </div>
                            </td>
                            <td class="admin-table__td" data-label="Hostname">
                                <code>{{ server.base_url || "from secret" }}</code>
                            </td>
                            <td class="admin-table__td" data-label="Tenants">
                                {{ server.tenants_count }}
                            </td>
                            <td class="admin-table__td" data-label="Accounts">
                                {{ server.accounts_count }}
                            </td>
                            <td class="admin-table__td" data-label="Last Sync">
                                {{ formatDate(server.last_synced_at) }}
                            </td>
                            <td class="admin-table__td" data-label="Status">
                                <BaseBadge
                                    :variant="
                                        server.status === 'active'
                                            ? 'success'
                                            : 'secondary'
                                    "
                                    size="sm"
                                >
                                    {{ server.status }}
                                </BaseBadge>
                            </td>
                            <td
                                class="admin-table__td admin-table__td--actions"
                                data-label="Actions"
                            >
                                <div class="admin-table__actions">
                                    <BaseButton
                                        @click="testConnection(server)"
                                        size="sm"
                                        variant="secondary"
                                        :loading="testingId === server.id"
                                        class="admin-actionBtn"
                                    >
                                        <span class="admin-actionBtn__text"
                                            >Test</span
                                        >
                                    </BaseButton>
                                    <BaseButton
                                        @click="openEditModal(server)"
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
                                        v-if="!server.is_default"
                                        @click="openDeleteConfirm(server)"
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
                                {{ isEditing ? "Edit" : "Add" }} PBX Server
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
                            <div class="admin-field">
                                <label for="server-name" class="admin-field__label">
                                    Name *
                                </label>
                                <input
                                    id="server-name"
                                    v-model="form.name"
                                    type="text"
                                    class="admin-input"
                                    placeholder="e.g., Bluehub Primary"
                                    required
                                />
                            </div>

                            <div class="admin-field">
                                <label for="server-base-url" class="admin-field__label">
                                    Hostname (base URL)
                                </label>
                                <input
                                    id="server-base-url"
                                    v-model="form.base_url"
                                    type="url"
                                    class="admin-input"
                                    placeholder="https://ip.pbxbluehub.com"
                                />
                                <p class="admin-field__hint">
                                    Required when entering an API key. Multiple
                                    servers may share the same hostname with
                                    different keys.
                                </p>
                            </div>

                            <div class="admin-field">
                                <label for="server-api-key" class="admin-field__label">
                                    API Key {{ isEditing ? "" : "*" }}
                                </label>
                                <input
                                    id="server-api-key"
                                    v-model="form.api_key"
                                    type="password"
                                    class="admin-input"
                                    autocomplete="new-password"
                                    :placeholder="
                                        isEditing
                                            ? 'Leave blank to keep the current key'
                                            : 'Paste the PBXware API key'
                                    "
                                />
                                <p class="admin-field__hint">
                                    Stored securely in AWS Secrets Manager. It
                                    is never displayed again after saving.
                                </p>
                            </div>

                            <div class="admin-field">
                                <label for="server-status" class="admin-field__label">
                                    Status
                                </label>
                                <select
                                    id="server-status"
                                    v-model="form.status"
                                    class="admin-input"
                                >
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                </select>
                                <p class="admin-field__hint">
                                    Inactive servers are skipped by tenant sync
                                    and weekly call ingestion.
                                </p>
                            </div>

                            <div class="admin-field">
                                <label for="server-secret-name" class="admin-field__label">
                                    AWS Secret Name (advanced)
                                </label>
                                <input
                                    id="server-secret-name"
                                    v-model="form.secret_name"
                                    type="text"
                                    class="admin-input"
                                    placeholder="Optional: use an existing secret instead of pasting a key"
                                />
                                <p class="admin-field__hint">
                                    Only needed if the secret was created in AWS
                                    manually. Leave empty otherwise.
                                </p>
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
                                @click="saveServer"
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
                                Delete PBX Server?
                            </h2>
                        </div>
                        <div class="admin-modal__body">
                            <p style="margin: 0">
                                Are you sure you want to delete
                                <strong>{{ deleteTarget?.name }}</strong>? If it
                                still has linked tenants or accounts, it will be
                                disabled instead of deleted, and tenant sync and
                                call ingestion will skip it.
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
                                Delete Server
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
const notice = ref("");
const rows = ref([]);

const showModal = ref(false);
const saving = ref(false);
const isEditing = ref(false);
const editingId = ref(null);
const testingId = ref(null);

const form = ref(emptyForm());

const showDeleteConfirm = ref(false);
const deleteTarget = ref(null);
const deleting = ref(false);

function emptyForm() {
    return {
        name: "",
        base_url: "",
        api_key: "",
        secret_name: "",
        status: "active",
    };
}

function formatDate(value) {
    if (!value) return "—";
    return new Date(value).toLocaleString();
}

async function fetchServers() {
    loading.value = true;
    error.value = "";

    try {
        const res = await adminApi.get("/pbx-servers");
        rows.value = res?.data?.data || [];
    } catch (e) {
        rows.value = [];
        error.value = "Failed to load PBX servers.";
    } finally {
        loading.value = false;
    }
}

function openCreateModal() {
    isEditing.value = false;
    editingId.value = null;
    form.value = emptyForm();
    showModal.value = true;
}

function openEditModal(server) {
    isEditing.value = true;
    editingId.value = server.id;
    form.value = {
        name: server.name,
        base_url: server.base_url || "",
        api_key: "",
        secret_name: "",
        status: server.status,
    };
    showModal.value = true;
}

function closeModal() {
    showModal.value = false;
}

function buildPayload() {
    const payload = {
        name: form.value.name,
        status: form.value.status,
    };
    if (form.value.base_url) payload.base_url = form.value.base_url;
    if (form.value.api_key) payload.api_key = form.value.api_key;
    if (form.value.secret_name) payload.secret_name = form.value.secret_name;
    return payload;
}

async function saveServer() {
    saving.value = true;
    error.value = "";
    notice.value = "";

    try {
        if (isEditing.value) {
            await adminApi.put(`/pbx-servers/${editingId.value}`, buildPayload());
        } else {
            await adminApi.post("/pbx-servers", buildPayload());
        }

        await fetchServers();
        closeModal();
    } catch (e) {
        error.value =
            e?.response?.data?.message || "Failed to save PBX server.";
    } finally {
        saving.value = false;
    }
}

async function testConnection(server) {
    testingId.value = server.id;
    error.value = "";
    notice.value = "";

    try {
        const res = await adminApi.post(`/pbx-servers/${server.id}/test-connection`);
        if (res?.data?.data?.ok) {
            notice.value = res?.data?.message || "Connection successful.";
        } else {
            error.value = res?.data?.message || "Connection test failed.";
        }
    } catch (e) {
        error.value =
            e?.response?.data?.message || "Connection test failed.";
    } finally {
        testingId.value = null;
    }
}

function openDeleteConfirm(server) {
    deleteTarget.value = server;
    showDeleteConfirm.value = true;
}

async function confirmDelete() {
    if (!deleteTarget.value) return;

    deleting.value = true;
    error.value = "";
    notice.value = "";

    try {
        const res = await adminApi.delete(`/pbx-servers/${deleteTarget.value.id}`);
        notice.value = res?.data?.message || "";
        await fetchServers();
        showDeleteConfirm.value = false;
        deleteTarget.value = null;
    } catch (e) {
        error.value =
            e?.response?.data?.message || "Failed to delete PBX server.";
    } finally {
        deleting.value = false;
    }
}

onMounted(() => {
    fetchServers();
});
</script>
