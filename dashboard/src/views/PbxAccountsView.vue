<script setup lang="ts">
import { onMounted, reactive, ref } from "vue";
import PageHeader from "../components/ui/PageHeader.vue";
import Card from "../components/ui/Card.vue";
import { adminApi } from "../api/adminApi";

interface Company {
    id: number;
    name: string;
}

interface Provider {
    id: number;
    name: string;
    type: string | null;
}

interface PbxAccount {
    id: number;
    server_id: string;
    status: string;
    company_id: number;
    company_name: string | null;
    pbx_provider_id: number;
    pbx_provider_name: string | null;
}

const accounts = ref<PbxAccount[]>([]);
const companies = ref<Company[]>([]);
const providers = ref<Provider[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);
const saving = ref(false);

const newAccount = reactive({
    company_id: "",
    pbx_provider_id: "",
    server_id: "",
});

const editingId = ref<number | null>(null);
const editAccount = reactive({
    company_id: "",
    pbx_provider_id: "",
    server_id: "",
});

async function fetchData() {
    loading.value = true;
    error.value = null;

    try {
        const [accountsRes, companiesRes, providersRes] = await Promise.all([
            adminApi.get("/pbx-accounts"),
            adminApi.get("/companies"),
            adminApi.get("/pbx-providers"),
        ]);

        accounts.value = accountsRes.data.data;
        companies.value = companiesRes.data.data;
        providers.value = providersRes.data.data;
    } catch (err: any) {
        error.value =
            err.response?.data?.message || "Failed to load PBX accounts";
        console.error("Failed to load PBX accounts:", err);
    } finally {
        loading.value = false;
    }
}

async function createAccount() {
    if (
        !newAccount.company_id ||
        !newAccount.pbx_provider_id ||
        !newAccount.server_id
    ) {
        error.value = "Company, provider, and server ID are required.";
        return;
    }

    saving.value = true;
    error.value = null;

    try {
        await adminApi.post("/pbx-accounts", {
            company_id: Number(newAccount.company_id),
            pbx_provider_id: Number(newAccount.pbx_provider_id),
            server_id: newAccount.server_id,
        });

        newAccount.company_id = "";
        newAccount.pbx_provider_id = "";
        newAccount.server_id = "";

        await fetchData();
    } catch (err: any) {
        error.value =
            err.response?.data?.message || "Failed to create PBX account";
        console.error("Failed to create PBX account:", err);
    } finally {
        saving.value = false;
    }
}

function startEdit(account: PbxAccount) {
    editingId.value = account.id;
    editAccount.company_id = String(account.company_id ?? "");
    editAccount.pbx_provider_id = String(account.pbx_provider_id ?? "");
    editAccount.server_id = account.server_id;
}

function cancelEdit() {
    editingId.value = null;
    editAccount.company_id = "";
    editAccount.pbx_provider_id = "";
    editAccount.server_id = "";
}

async function updateAccount(id: number) {
    if (
        !editAccount.company_id ||
        !editAccount.pbx_provider_id ||
        !editAccount.server_id
    ) {
        error.value = "Company, provider, and server ID are required.";
        return;
    }

    saving.value = true;
    error.value = null;

    try {
        await adminApi.put(`/pbx-accounts/${id}`, {
            company_id: Number(editAccount.company_id),
            pbx_provider_id: Number(editAccount.pbx_provider_id),
            server_id: editAccount.server_id,
        });

        editingId.value = null;
        await fetchData();
    } catch (err: any) {
        error.value =
            err.response?.data?.message || "Failed to update PBX account";
        console.error("Failed to update PBX account:", err);
    } finally {
        saving.value = false;
    }
}

async function deleteAccount(id: number) {
    if (!confirm("Delete this PBX account?")) {
        return;
    }

    saving.value = true;
    error.value = null;

    try {
        await adminApi.delete(`/pbx-accounts/${id}`);
        accounts.value = accounts.value.filter((account) => account.id !== id);
    } catch (err: any) {
        error.value =
            err.response?.data?.message || "Failed to delete PBX account";
        console.error("Failed to delete PBX account:", err);
    } finally {
        saving.value = false;
    }
}

onMounted(fetchData);
</script>

<template>
    <div>
        <PageHeader
            title="PBX Accounts"
            subtitle="Assign PBX server IDs to companies"
        />

        <Card class="createCard">
            <div class="formRow">
                <label class="formLabel">Company</label>
                <select v-model="newAccount.company_id" class="formControl">
                    <option value="" disabled>Select company</option>
                    <option v-for="c in companies" :key="c.id" :value="c.id">
                        {{ c.name }} (ID {{ c.id }})
                    </option>
                </select>
            </div>

            <div class="formRow">
                <label class="formLabel">PBX Provider</label>
                <select
                    v-model="newAccount.pbx_provider_id"
                    class="formControl"
                >
                    <option value="" disabled>Select provider</option>
                    <option v-for="p in providers" :key="p.id" :value="p.id">
                        {{ p.name }}
                    </option>
                </select>
            </div>

            <div class="formRow">
                <label class="formLabel">Server ID</label>
                <input
                    v-model="newAccount.server_id"
                    class="formControl"
                    type="text"
                    placeholder="server-id"
                />
            </div>

            <div class="formActions">
                <button
                    class="btn"
                    type="button"
                    :disabled="saving"
                    @click="createAccount"
                >
                    {{ saving ? "Saving..." : "Add PBX Account" }}
                </button>
            </div>
        </Card>

        <Card v-if="loading">
            <div class="loading">Loading PBX accounts...</div>
        </Card>

        <Card v-else-if="error">
            <div class="error">{{ error }}</div>
        </Card>

        <Card v-else>
            <div class="accountsList">
                <div v-if="accounts.length === 0" class="emptyState">
                    No PBX accounts found
                </div>

                <div
                    v-for="account in accounts"
                    :key="account.id"
                    class="accountItem"
                >
                    <div class="accountInfo">
                        <div class="accountTitle">
                            <span class="accountCompany">{{
                                account.company_name ?? "Company"
                            }}</span>
                            <span class="accountMeta"
                                >ID {{ account.company_id }}</span
                            >
                        </div>
                        <div class="accountMetaRow">
                            <span class="pill"
                                >Provider:
                                {{ account.pbx_provider_name ?? "-" }}</span
                            >
                            <span class="pill"
                                >Server ID: {{ account.server_id }}</span
                            >
                        </div>
                    </div>

                    <div class="accountActions">
                        <button
                            v-if="editingId !== account.id"
                            class="btn btn--secondary"
                            type="button"
                            @click="startEdit(account)"
                        >
                            Edit
                        </button>
                        <button
                            v-if="editingId !== account.id"
                            class="btn btn--danger"
                            type="button"
                            @click="deleteAccount(account.id)"
                        >
                            Delete
                        </button>
                    </div>

                    <div v-if="editingId === account.id" class="editPanel">
                        <div class="formRow">
                            <label class="formLabel">Company</label>
                            <select
                                v-model="editAccount.company_id"
                                class="formControl"
                            >
                                <option value="" disabled>
                                    Select company
                                </option>
                                <option
                                    v-for="c in companies"
                                    :key="c.id"
                                    :value="c.id"
                                >
                                    {{ c.name }} (ID {{ c.id }})
                                </option>
                            </select>
                        </div>

                        <div class="formRow">
                            <label class="formLabel">PBX Provider</label>
                            <select
                                v-model="editAccount.pbx_provider_id"
                                class="formControl"
                            >
                                <option value="" disabled>
                                    Select provider
                                </option>
                                <option
                                    v-for="p in providers"
                                    :key="p.id"
                                    :value="p.id"
                                >
                                    {{ p.name }}
                                </option>
                            </select>
                        </div>

                        <div class="formRow">
                            <label class="formLabel">Server ID</label>
                            <input
                                v-model="editAccount.server_id"
                                class="formControl"
                                type="text"
                            />
                        </div>

                        <div class="formActions">
                            <button
                                class="btn"
                                type="button"
                                :disabled="saving"
                                @click="updateAccount(account.id)"
                            >
                                {{ saving ? "Saving..." : "Save" }}
                            </button>
                            <button
                                class="btn btn--secondary"
                                type="button"
                                :disabled="saving"
                                @click="cancelEdit"
                            >
                                Cancel
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </Card>
    </div>
</template>

<style scoped>
.createCard {
    margin-bottom: var(--space-4);
    display: grid;
    gap: var(--space-3);
}

.formRow {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.formLabel {
    font-size: 0.85rem;
    font-weight: 600;
    color: #334155;
}

.formControl {
    padding: 0.6rem 0.75rem;
    border: 1px solid #e5e7eb;
    border-radius: 0.5rem;
    font-size: 0.95rem;
}

.formActions {
    display: flex;
    gap: var(--space-2);
    justify-content: flex-end;
}

.loading,
.error {
    padding: 2rem;
    text-align: center;
}

.error {
    color: #ef4444;
}

.accountsList {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
}

.accountItem {
    padding: 1rem;
    border: 1px solid #e5e7eb;
    border-radius: 0.75rem;
    display: grid;
    gap: var(--space-2);
}

.accountInfo {
    display: flex;
    flex-direction: column;
    gap: 0.35rem;
}

.accountTitle {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-weight: 600;
}

.accountCompany {
    color: #0f172a;
}

.accountMeta {
    font-size: 0.8rem;
    color: #6b7280;
}

.accountMetaRow {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
}

.pill {
    padding: 0.25rem 0.6rem;
    background: #f1f5f9;
    border-radius: 999px;
    font-size: 0.75rem;
}

.accountActions {
    display: flex;
    gap: var(--space-2);
}

.editPanel {
    padding-top: 0.5rem;
    border-top: 1px solid #e5e7eb;
    display: grid;
    gap: var(--space-2);
}

.emptyState {
    padding: 2rem;
    text-align: center;
    color: #9ca3af;
}

.btn {
    padding: 0.55rem 1rem;
    border-radius: 0.5rem;
    border: none;
    background: #111827;
    color: white;
    font-weight: 600;
    cursor: pointer;
}

.btn--secondary {
    background: #e5e7eb;
    color: #111827;
}

.btn--danger {
    background: #fee2e2;
    color: #b91c1c;
}
</style>
