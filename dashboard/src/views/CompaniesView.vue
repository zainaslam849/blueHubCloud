<script setup lang="ts">
import { ref, onMounted } from "vue";
import { adminApi } from "../api/adminApi";
import PageHeader from "../components/ui/PageHeader.vue";
import Card from "../components/ui/Card.vue";

interface Company {
    id: number;
    name: string;
    status: string;
    server_id?: string;
    tenant_code?: string;
}

interface UnassignedUser {
    id: number;
    name: string;
    email: string;
}

interface Plan {
    id: number;
    name: string;
    minute_limit: number;
    price: string;
}

const companies = ref<Company[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);

// Assign User modal
const showAssignUser = ref(false);
const assignUserCompany = ref<Company | null>(null);
const unassignedUsers = ref<UnassignedUser[]>([]);
const selectedUserId = ref<number | null>(null);
const assignUserLoading = ref(false);
const assignUserError = ref<string | null>(null);

// Assign Plan modal
const showAssignPlan = ref(false);
const assignPlanCompany = ref<Company | null>(null);
const plans = ref<Plan[]>([]);
const selectedPlanId = ref<number | null>(null);
const assignPlanLoading = ref(false);
const assignPlanError = ref<string | null>(null);
const assignPlanSuccess = ref<string | null>(null);

async function fetchCompanies() {
    loading.value = true;
    error.value = null;
    try {
        const response = await adminApi.get("/companies");
        companies.value = response.data.data;
    } catch (err: unknown) {
        error.value = (err as { response?: { data?: { message?: string } } })?.response?.data?.message || "Failed to load companies";
    } finally {
        loading.value = false;
    }
}

async function openAssignUser(company: Company) {
    assignUserCompany.value = company;
    selectedUserId.value = null;
    assignUserError.value = null;
    unassignedUsers.value = [];
    showAssignUser.value = true;
    try {
        const res = await adminApi.get("/users/unassigned");
        unassignedUsers.value = res.data.data;
    } catch {
        assignUserError.value = "Failed to load unassigned users.";
    }
}

async function submitAssignUser() {
    if (!assignUserCompany.value || !selectedUserId.value) return;
    assignUserLoading.value = true;
    assignUserError.value = null;
    try {
        await adminApi.post(`/companies/${assignUserCompany.value.id}/assign-user`, {
            user_id: selectedUserId.value,
        });
        showAssignUser.value = false;
    } catch (err: unknown) {
        assignUserError.value = (err as { response?: { data?: { message?: string } } })?.response?.data?.message || "Failed to assign user.";
    } finally {
        assignUserLoading.value = false;
    }
}

async function openAssignPlan(company: Company) {
    assignPlanCompany.value = company;
    selectedPlanId.value = null;
    assignPlanError.value = null;
    assignPlanSuccess.value = null;
    plans.value = [];
    showAssignPlan.value = true;
    try {
        const res = await adminApi.get("/plans");
        plans.value = res.data.data.filter((p: Plan & { is_active?: boolean }) => p.is_active !== false);
    } catch {
        assignPlanError.value = "Failed to load plans.";
    }
}

async function submitAssignPlan() {
    if (!assignPlanCompany.value || !selectedPlanId.value) return;
    assignPlanLoading.value = true;
    assignPlanError.value = null;
    assignPlanSuccess.value = null;
    try {
        const res = await adminApi.post(`/companies/${assignPlanCompany.value.id}/assign-plan`, {
            plan_id: selectedPlanId.value,
        });
        assignPlanSuccess.value = res.data.message ?? "Plan assigned successfully.";
        setTimeout(() => { showAssignPlan.value = false; }, 1500);
    } catch (err: unknown) {
        assignPlanError.value = (err as { response?: { data?: { message?: string } } })?.response?.data?.message || "Failed to assign plan.";
    } finally {
        assignPlanLoading.value = false;
    }
}

onMounted(fetchCompanies);
</script>

<template>
    <div>
        <PageHeader title="Companies" description="Manage company accounts and SaaS assignments." />

        <Card v-if="loading">
            <div class="loading">Loading companies...</div>
        </Card>

        <Card v-else-if="error">
            <div class="errText">{{ error }}</div>
        </Card>

        <Card v-else>
            <div class="companiesList">
                <div
                    v-for="company in companies"
                    :key="company.id"
                    class="companyItem"
                >
                    <div class="companyInfo">
                        <h3 class="companyName">{{ company.name }}</h3>
                        <span class="companyMeta">
                            ID: {{ company.id }}
                            <template v-if="company.server_id"> · Server: {{ company.server_id }}</template>
                            <template v-if="company.tenant_code"> · Tenant: {{ company.tenant_code }}</template>
                        </span>
                    </div>

                    <div class="companyActions">
                        <span
                            class="badge"
                            :class="company.status === 'active' ? 'badge-success' : 'badge-secondary'"
                        >{{ company.status }}</span>

                        <button class="btn btn--ghost" type="button" @click="openAssignUser(company)">
                            Assign User
                        </button>
                        <button class="btn btn--ghost" type="button" @click="openAssignPlan(company)">
                            Assign Plan
                        </button>
                    </div>
                </div>

                <div v-if="companies.length === 0" class="emptyState">
                    No companies found.
                </div>
            </div>
        </Card>

        <!-- Assign User Modal -->
        <div v-if="showAssignUser" class="overlay" @click.self="showAssignUser = false">
            <div class="modal">
                <h2 class="modalTitle">Assign User — {{ assignUserCompany?.name }}</h2>

                <div v-if="assignUserError" class="errText">{{ assignUserError }}</div>

                <div v-if="unassignedUsers.length === 0 && !assignUserError" class="emptyState">
                    No unassigned users found.
                </div>

                <label v-else class="field">
                    <span>Select user</span>
                    <select v-model="selectedUserId" class="input">
                        <option :value="null" disabled>— choose —</option>
                        <option v-for="u in unassignedUsers" :key="u.id" :value="u.id">
                            {{ u.name }} ({{ u.email }})
                        </option>
                    </select>
                </label>

                <div class="modalActions">
                    <button class="btn btn--ghost" type="button" @click="showAssignUser = false">Cancel</button>
                    <button
                        class="btn btn--primary"
                        type="button"
                        :disabled="!selectedUserId || assignUserLoading"
                        @click="submitAssignUser"
                    >
                        {{ assignUserLoading ? 'Assigning…' : 'Assign' }}
                    </button>
                </div>
            </div>
        </div>

        <!-- Assign Plan Modal -->
        <div v-if="showAssignPlan" class="overlay" @click.self="showAssignPlan = false">
            <div class="modal">
                <h2 class="modalTitle">Assign Plan — {{ assignPlanCompany?.name }}</h2>

                <div v-if="assignPlanError" class="errText">{{ assignPlanError }}</div>
                <div v-if="assignPlanSuccess" class="successText">{{ assignPlanSuccess }}</div>

                <div v-if="plans.length === 0 && !assignPlanError" class="emptyState">
                    No active plans found. <router-link :to="{ name: 'plans' }">Create one first.</router-link>
                </div>

                <label v-else class="field">
                    <span>Select plan (minutes will be added)</span>
                    <select v-model="selectedPlanId" class="input">
                        <option :value="null" disabled>— choose —</option>
                        <option v-for="p in plans" :key="p.id" :value="p.id">
                            {{ p.name }} — {{ p.minute_limit.toLocaleString() }} min @ ${{ p.price }}
                        </option>
                    </select>
                </label>

                <div class="modalActions">
                    <button class="btn btn--ghost" type="button" @click="showAssignPlan = false">Cancel</button>
                    <button
                        class="btn btn--primary"
                        type="button"
                        :disabled="!selectedPlanId || assignPlanLoading"
                        @click="submitAssignPlan"
                    >
                        {{ assignPlanLoading ? 'Assigning…' : 'Add minutes' }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.loading { padding: 2rem; text-align: center; }
.errText { color: #ef4444; padding: 0.5rem 0; }
.successText { color: #1f9d55; padding: 0.5rem 0; }

.companiesList {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.companyItem {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 1rem;
    border: 1px solid var(--border);
    border-radius: 0.5rem;
    gap: 1rem;
    flex-wrap: wrap;
}

.companyInfo {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
}

.companyName {
    font-size: 1rem;
    font-weight: 600;
    margin: 0;
}

.companyMeta {
    font-size: 0.85rem;
    opacity: 0.65;
}

.companyActions {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    flex-wrap: wrap;
}

.badge {
    padding: 0.25rem 0.75rem;
    border-radius: 0.375rem;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
}

.badge-success { background: #d1fae5; color: #065f46; }
.badge-secondary { background: #e5e7eb; color: #6b7280; }

.emptyState {
    padding: 2rem;
    text-align: center;
    opacity: 0.6;
}

/* Modal */
.overlay {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.5);
    display: grid;
    place-items: center;
    z-index: 100;
}

.modal {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: var(--space-6);
    width: min(480px, 92vw);
    display: grid;
    gap: var(--space-4);
}

.modalTitle {
    margin: 0;
    font-size: 1.1rem;
    font-weight: 700;
}

.field {
    display: grid;
    gap: 6px;
    font-weight: 600;
}

.modalActions {
    display: flex;
    gap: var(--space-3);
    justify-content: flex-end;
}
</style>
