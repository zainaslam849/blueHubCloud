<script setup lang="ts">
import Card from "../components/ui/Card.vue";
import PageHeader from "../components/ui/PageHeader.vue";
import { auth } from "../composables/useAuth";
import { logout as apiLogout } from "../api/auth";
import { useRouter } from "vue-router";

const router = useRouter();
const user = auth.state;

async function handleLogout() {
    try {
        await apiLogout();
    } finally {
        auth.logout();
        await router.push({ name: "login" });
    }
}
</script>

<template>
    <div>
        <PageHeader
            title="Account"
            description="Your account settings and details."
        />

        <section class="grid">
            <Card title="Profile">
                <div class="kv">
                    <div class="k">Name</div>
                    <div class="v">{{ user.user?.name ?? '—' }}</div>

                    <div class="k">Email</div>
                    <div class="v">{{ user.user?.email ?? '—' }}</div>

                    <div class="k">Company</div>
                    <div class="v">{{ user.user?.company_name ?? 'Not assigned yet' }}</div>

                    <div class="k">Role</div>
                    <div class="v">{{ user.user?.role ?? '—' }}</div>
                </div>
            </Card>

            <Card title="Security" subtitle="Session management">
                <p class="muted">
                    You are signed in. Click below to sign out of your account.
                </p>
                <div class="actions">
                    <button class="btn btn--secondary" type="button" @click="handleLogout">
                        Sign out
                    </button>
                </div>
            </Card>
        </section>
    </div>
</template>

<style scoped>
.grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: var(--space-4);
}

.kv {
    display: grid;
    grid-template-columns: 160px 1fr;
    gap: 10px;
}

.k { opacity: 0.75; }
.v { font-weight: 750; }

.muted {
    margin: 0;
    opacity: 0.75;
}

.actions {
    margin-top: var(--space-4);
    display: flex;
    gap: var(--space-3);
    flex-wrap: wrap;
}

@media (max-width: 960px) {
    .grid { grid-template-columns: 1fr; }
    .kv { grid-template-columns: 1fr; }
}
</style>
