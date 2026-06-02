<script setup lang="ts">
import { ref, onMounted } from "vue";
import { adminApi } from "../api/adminApi";
import PageHeader from "../components/ui/PageHeader.vue";
import Card from "../components/ui/Card.vue";

interface SaasUser {
    id: number;
    name: string;
    email: string;
    company_id: number | null;
    company?: { id: number; name: string } | null;
    created_at: string;
}

interface Meta {
    currentPage: number;
    lastPage: number;
    total: number;
}

const users = ref<SaasUser[]>([]);
const meta = ref<Meta>({ currentPage: 1, lastPage: 1, total: 0 });
const loading = ref(true);
const error = ref<string | null>(null);

async function fetchUsers(page = 1) {
    loading.value = true;
    error.value = null;
    try {
        const res = await adminApi.get("/users", { params: { page } });
        users.value = res.data.data;
        meta.value = res.data.meta;
    } catch (err: unknown) {
        error.value =
            (err as { response?: { data?: { message?: string } } })?.response?.data
                ?.message ?? "Failed to load users.";
    } finally {
        loading.value = false;
    }
}

onMounted(() => fetchUsers());
</script>

<template>
    <div>
        <PageHeader
            title="Users"
            description="Registered SaaS users and their company assignments."
        />

        <Card v-if="loading">
            <div class="loading">Loading users...</div>
        </Card>

        <Card v-else-if="error">
            <div class="errText">{{ error }}</div>
        </Card>

        <Card v-else>
            <div class="tableWrap">
                <div class="row row--head">
                    <span>Name</span>
                    <span>Email</span>
                    <span>Company</span>
                    <span>Registered</span>
                </div>

                <div v-for="u in users" :key="u.id" class="row">
                    <span class="name">{{ u.name }}</span>
                    <span class="email">{{ u.email }}</span>
                    <span>
                        <span v-if="u.company" class="badge badge-success">
                            {{ u.company.name }}
                        </span>
                        <span v-else class="badge badge-warn">Unassigned</span>
                    </span>
                    <span class="date">{{ new Date(u.created_at).toLocaleDateString() }}</span>
                </div>

                <div v-if="users.length === 0" class="emptyState">
                    No registered users yet.
                </div>
            </div>

            <div v-if="meta.lastPage > 1" class="pager">
                <button
                    class="btn btn--ghost"
                    :disabled="meta.currentPage <= 1"
                    @click="fetchUsers(meta.currentPage - 1)"
                >Prev</button>
                <span class="pageInfo">{{ meta.currentPage }} / {{ meta.lastPage }}</span>
                <button
                    class="btn btn--ghost"
                    :disabled="meta.currentPage >= meta.lastPage"
                    @click="fetchUsers(meta.currentPage + 1)"
                >Next</button>
            </div>
        </Card>
    </div>
</template>

<style scoped>
.loading { padding: 2rem; text-align: center; }
.errText { color: #ef4444; }

.tableWrap { display: grid; gap: 0.5rem; }

.row {
    display: grid;
    grid-template-columns: 1.4fr 2fr 1.4fr 1fr;
    gap: var(--space-3);
    padding: 10px 14px;
    border: 1px solid var(--border);
    border-radius: 8px;
    align-items: center;
    font-size: 0.9rem;
}

.row--head {
    font-weight: 700;
    background: var(--surface-2);
}

.name { font-weight: 600; }
.email { opacity: 0.75; word-break: break-all; }
.date { opacity: 0.6; }

.badge {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 6px;
    font-size: 0.8rem;
    font-weight: 600;
}
.badge-success { background: #d1fae5; color: #065f46; }
.badge-warn { background: #fef3c7; color: #92400e; }

.emptyState {
    padding: 2rem;
    text-align: center;
    opacity: 0.6;
}

.pager {
    display: flex;
    align-items: center;
    gap: var(--space-3);
    justify-content: flex-end;
    margin-top: var(--space-4);
}
.pageInfo { opacity: 0.7; font-size: 0.9rem; }

@media (max-width: 768px) {
    .row { grid-template-columns: 1fr; }
    .row--head { display: none; }
}
</style>
