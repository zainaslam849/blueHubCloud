<script setup lang="ts">
import { ref, onMounted } from "vue";
import { adminApi } from "../api/adminApi";
import PageHeader from "../components/ui/PageHeader.vue";
import Card from "../components/ui/Card.vue";

interface Plan {
    id: number;
    name: string;
    minute_limit: number;
    price: string;
    is_active: boolean;
}

const plans = ref<Plan[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);

// Form state
const showForm = ref(false);
const editingId = ref<number | null>(null);
const form = ref({ name: "", minute_limit: 500, price: "0.00", is_active: true });
const formError = ref<string | null>(null);
const formLoading = ref(false);

async function fetchPlans() {
    loading.value = true;
    error.value = null;
    try {
        const res = await adminApi.get("/plans");
        plans.value = res.data.data;
    } catch (err: unknown) {
        error.value = (err as { response?: { data?: { message?: string } } })?.response?.data?.message || "Failed to load plans.";
    } finally {
        loading.value = false;
    }
}

function openCreate() {
    editingId.value = null;
    form.value = { name: "", minute_limit: 500, price: "0.00", is_active: true };
    formError.value = null;
    showForm.value = true;
}

function openEdit(plan: Plan) {
    editingId.value = plan.id;
    form.value = {
        name: plan.name,
        minute_limit: plan.minute_limit,
        price: plan.price,
        is_active: plan.is_active,
    };
    formError.value = null;
    showForm.value = true;
}

async function submitForm() {
    formLoading.value = true;
    formError.value = null;
    try {
        if (editingId.value) {
            await adminApi.put(`/plans/${editingId.value}`, form.value);
        } else {
            await adminApi.post("/plans", form.value);
        }
        showForm.value = false;
        await fetchPlans();
    } catch (err: unknown) {
        formError.value = (err as { response?: { data?: { message?: string } } })?.response?.data?.message || "Failed to save plan.";
    } finally {
        formLoading.value = false;
    }
}

async function deactivate(id: number) {
    if (!confirm("Deactivate this plan? It won't be available for new assignments.")) return;
    try {
        await adminApi.delete(`/plans/${id}`);
        await fetchPlans();
    } catch {
        alert("Failed to deactivate plan.");
    }
}

onMounted(fetchPlans);
</script>

<template>
    <div>
        <PageHeader title="Plans" description="Manage minute plans for SaaS companies.">
            <template #actions>
                <button class="btn btn--primary" type="button" @click="openCreate">+ New plan</button>
            </template>
        </PageHeader>

        <Card v-if="loading">
            <div class="loading">Loading plans...</div>
        </Card>

        <Card v-else-if="error">
            <div class="errText">{{ error }}</div>
        </Card>

        <Card v-else>
            <div class="planList">
                <div class="planRow planRow--head">
                    <span>Name</span>
                    <span>Minute limit</span>
                    <span>Price</span>
                    <span>Status</span>
                    <span></span>
                </div>

                <div v-for="p in plans" :key="p.id" class="planRow">
                    <span class="planName">{{ p.name }}</span>
                    <span>{{ p.minute_limit.toLocaleString() }} min</span>
                    <span>${{ p.price }}</span>
                    <span :class="p.is_active ? 'ok' : 'muted'">
                        {{ p.is_active ? 'Active' : 'Inactive' }}
                    </span>
                    <span class="rowActions">
                        <button class="btn btn--ghost" type="button" @click="openEdit(p)">Edit</button>
                        <button v-if="p.is_active" class="btn btn--ghost" type="button" @click="deactivate(p.id)">
                            Deactivate
                        </button>
                    </span>
                </div>

                <div v-if="plans.length === 0" class="emptyState">
                    No plans yet. Create the first one.
                </div>
            </div>
        </Card>

        <!-- Create / Edit modal -->
        <div v-if="showForm" class="overlay" @click.self="showForm = false">
            <div class="modal">
                <h2 class="modalTitle">{{ editingId ? 'Edit plan' : 'New plan' }}</h2>

                <div v-if="formError" class="errText">{{ formError }}</div>

                <label class="field">
                    <span>Name</span>
                    <input v-model="form.name" class="input" type="text" required />
                </label>

                <label class="field">
                    <span>Minute limit</span>
                    <input v-model.number="form.minute_limit" class="input" type="number" min="1" required />
                </label>

                <label class="field">
                    <span>Price (USD)</span>
                    <input v-model="form.price" class="input" type="text" placeholder="0.00" required />
                </label>

                <label class="fieldInline">
                    <input v-model="form.is_active" type="checkbox" />
                    <span>Active</span>
                </label>

                <div class="modalActions">
                    <button class="btn btn--ghost" type="button" @click="showForm = false">Cancel</button>
                    <button
                        class="btn btn--primary"
                        type="button"
                        :disabled="formLoading"
                        @click="submitForm"
                    >
                        {{ formLoading ? 'Saving…' : (editingId ? 'Update' : 'Create') }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.loading { padding: 2rem; text-align: center; }
.errText { color: #ef4444; padding: 0.25rem 0; }
.ok { color: #1f9d55; font-weight: 600; }
.muted { opacity: 0.5; }

.planList {
    display: grid;
    gap: 0.5rem;
}

.planRow {
    display: grid;
    grid-template-columns: 2fr 1fr 1fr 1fr 1.4fr;
    gap: var(--space-3);
    padding: 10px 14px;
    border: 1px solid var(--border);
    border-radius: 8px;
    align-items: center;
}

.planRow--head {
    font-weight: 700;
    background: var(--surface-2);
}

.planName { font-weight: 600; }

.rowActions {
    display: flex;
    gap: 6px;
    justify-content: flex-end;
}

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
    width: min(420px, 92vw);
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

.fieldInline {
    display: flex;
    align-items: center;
    gap: 8px;
    font-weight: 600;
}

.modalActions {
    display: flex;
    gap: var(--space-3);
    justify-content: flex-end;
}

@media (max-width: 768px) {
    .planRow { grid-template-columns: 1fr; }
    .planRow--head { display: none; }
}
</style>
