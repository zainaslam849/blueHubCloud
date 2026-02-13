<script setup lang="ts">
import { ref, onMounted } from "vue";
import { adminApi } from "../api/adminApi";
import PageHeader from "../components/ui/PageHeader.vue";
import Card from "../components/ui/Card.vue";

interface Company {
    id: number;
    name: string;
    status: string;
}

const companies = ref<Company[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);

async function fetchCompanies() {
    loading.value = true;
    error.value = null;
    try {
        const response = await adminApi.get("/companies");
        companies.value = response.data.data;
    } catch (err: any) {
        error.value = err.response?.data?.message || "Failed to load companies";
        console.error("Failed to fetch companies:", err);
    } finally {
        loading.value = false;
    }
}

onMounted(() => {
    fetchCompanies();
});
</script>

<template>
    <div>
        <PageHeader title="Companies" subtitle="Manage company accounts" />

        <Card v-if="loading">
            <div class="loading">Loading companies...</div>
        </Card>

        <Card v-else-if="error">
            <div class="error">{{ error }}</div>
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
                        <span class="companyId">ID: {{ company.id }}</span>
                    </div>
                    <span
                        class="badge"
                        :class="{
                            'badge-success': company.status === 'active',
                            'badge-secondary': company.status !== 'active',
                        }"
                    >
                        {{ company.status }}
                    </span>
                </div>

                <div v-if="companies.length === 0" class="emptyState">
                    No companies found
                </div>
            </div>
        </Card>
    </div>
</template>

<style scoped>
.loading,
.error {
    padding: 2rem;
    text-align: center;
}

.error {
    color: #ef4444;
}

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
    border: 1px solid #e5e7eb;
    border-radius: 0.5rem;
    transition: all 0.2s;
}

.companyItem:hover {
    background-color: #f9fafb;
    border-color: #d1d5db;
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
    color: #111827;
}

.companyId {
    font-size: 0.875rem;
    color: #6b7280;
}

.badge {
    padding: 0.25rem 0.75rem;
    border-radius: 0.375rem;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
}

.badge-success {
    background-color: #d1fae5;
    color: #065f46;
}

.badge-secondary {
    background-color: #e5e7eb;
    color: #6b7280;
}

.emptyState {
    padding: 3rem;
    text-align: center;
    color: #9ca3af;
}
</style>
