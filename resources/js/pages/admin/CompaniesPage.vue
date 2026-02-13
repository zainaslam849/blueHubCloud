<template>
    <div class="admin-container admin-page">
        <header class="admin-page__header">
            <div>
                <p class="admin-page__kicker">Accounts</p>
                <h1 class="admin-page__title">Companies</h1>
                <p class="admin-page__subtitle">
                    Manage company records and status.
                </p>
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
                            <th class="admin-table__th">ID</th>
                            <th class="admin-table__th">Name</th>
                            <th class="admin-table__th">Status</th>
                        </tr>
                    </thead>
                    <tbody class="admin-table__body">
                        <tr
                            v-for="company in companies"
                            :key="company.id"
                            class="admin-table__tr"
                        >
                            <td class="admin-table__td" data-label="ID">
                                {{ company.id }}
                            </td>
                            <td class="admin-table__td" data-label="Name">
                                <span class="font-medium">{{
                                    company.name
                                }}</span>
                            </td>
                            <td class="admin-table__td" data-label="Status">
                                <BaseBadge
                                    :variant="
                                        company.status === 'active'
                                            ? 'success'
                                            : 'secondary'
                                    "
                                    size="sm"
                                >
                                    {{ company.status || "unknown" }}
                                </BaseBadge>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>

<script setup>
import { onMounted, ref } from "vue";
import adminApi from "../../router/admin/api";
import BaseBadge from "../../components/admin/base/BaseBadge.vue";

const companies = ref([]);
const loading = ref(true);
const error = ref("");

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

onMounted(loadCompanies);
</script>
