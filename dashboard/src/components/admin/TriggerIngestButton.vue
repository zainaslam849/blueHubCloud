<script setup lang="ts">
import { ref } from "vue";
import { http } from "../../api/http";

const loading = ref(false);
const success = ref<null | string>(null);
const error = ref<null | string>(null);

async function triggerIngest() {
    loading.value = true;
    success.value = null;
    error.value = null;

    try {
        const res = await http.post("/admin/api/pbx/ingest");
        success.value = `Dispatched ${res.data.dispatched} jobs`;
    } catch (err: any) {
        error.value =
            err?.response?.data?.message ?? err?.message ?? "Request failed";
    } finally {
        loading.value = false;
    }
}
</script>

<template>
    <div class="trigger-ingest">
        <button class="btn" :disabled="loading" @click="triggerIngest">
            <span v-if="loading">Triggering…</span>
            <span v-else>Trigger PBX Ingest</span>
        </button>

        <div v-if="success" class="muted success">{{ success }}</div>
        <div v-if="error" class="muted error">{{ error }}</div>
    </div>
</template>

<style scoped>
.trigger-ingest {
    display: flex;
    gap: 12px;
    align-items: center;
}

.muted {
    opacity: 0.9;
    font-size: 13px;
}

.success {
    color: var(--green-600);
}

.error {
    color: var(--red-600);
}
</style>
