<template>
    <div class="admin-container admin-page">
        <header class="admin-page__header">
            <div>
                <p class="admin-page__kicker">Settings</p>
                <h1 class="admin-page__title">Tenant Sync</h1>
                <p class="admin-page__subtitle">
                    Configure automated PBXware tenant discovery and sync
                    scheduling.
                </p>
            </div>
        </header>

        <div v-if="error" class="admin-alert admin-alert--error">
            {{ error }}
        </div>
        <div v-if="success" class="admin-alert admin-alert--success">
            {{ success }}
        </div>

        <div v-if="loading" class="admin-card admin-card--glass">
            <p>Loading sync settings...</p>
        </div>

        <div
            v-else-if="providers.length === 0"
            class="admin-card admin-card--glass"
        >
            <p>No PBX providers configured.</p>
        </div>

        <div v-else class="admin-tenantSyncGrid">
            <div
                v-for="provider in providers"
                :key="provider.pbx_provider_id"
                class="admin-card admin-card--glass"
            >
                <div
                    style="
                        display: flex;
                        align-items: center;
                        justify-content: space-between;
                        gap: 16px;
                        margin-bottom: 20px;
                    "
                >
                    <div>
                        <h2 class="admin-card__headline">
                            {{ provider.pbx_provider_name }}
                        </h2>
                        <p class="admin-card__hint">
                            Automatic tenant discovery and linking.
                        </p>
                    </div>
                    <div style="display: flex; gap: 8px; align-items: center">
                        <div class="admin-toggle-wrapper">
                            <input
                                :id="`enabled-${provider.pbx_provider_id}`"
                                :checked="
                                    getSetting(provider.pbx_provider_id).enabled
                                "
                                type="checkbox"
                                class="admin-toggle"
                                @change="
                                    toggleEnabled(provider.pbx_provider_id)
                                "
                            />
                            <label
                                :for="`enabled-${provider.pbx_provider_id}`"
                                class="admin-toggle-label"
                            ></label>
                        </div>
                        <span class="text-sm">
                            {{
                                getSetting(provider.pbx_provider_id).enabled
                                    ? "Enabled"
                                    : "Disabled"
                            }}
                        </span>
                    </div>
                </div>

                <div class="admin-tenantSyncForm">
                    <!-- Frequency Selection -->
                    <div class="admin-field">
                        <label class="admin-field__label"> Frequency </label>
                        <select
                            :value="
                                getSetting(provider.pbx_provider_id).frequency
                            "
                            class="admin-input"
                            @change="
                                (e) =>
                                    updateSetting(
                                        provider.pbx_provider_id,
                                        'frequency',
                                        e.target.value,
                                    )
                            "
                        >
                            <option value="hourly">Hourly</option>
                            <option value="daily">Daily</option>
                            <option value="weekly">Weekly</option>
                        </select>
                    </div>

                    <!-- Scheduled Time -->
                    <div class="admin-field">
                        <label class="admin-field__label">
                            Scheduled Time (UTC)
                        </label>
                        <input
                            :value="
                                getSetting(provider.pbx_provider_id)
                                    .scheduled_time
                            "
                            type="time"
                            class="admin-input"
                            @change="
                                (e) =>
                                    updateSetting(
                                        provider.pbx_provider_id,
                                        'scheduled_time',
                                        e.target.value,
                                    )
                            "
                        />
                    </div>

                    <!-- Weekly Day Selection -->
                    <div
                        v-if="
                            getSetting(provider.pbx_provider_id).frequency ===
                            'weekly'
                        "
                        class="admin-field"
                    >
                        <label class="admin-field__label"> Day of Week </label>
                        <select
                            :value="
                                getSetting(provider.pbx_provider_id)
                                    .scheduled_day
                            "
                            class="admin-input"
                            @change="
                                (e) =>
                                    updateSetting(
                                        provider.pbx_provider_id,
                                        'scheduled_day',
                                        e.target.value,
                                    )
                            "
                        >
                            <option value="monday">Monday</option>
                            <option value="tuesday">Tuesday</option>
                            <option value="wednesday">Wednesday</option>
                            <option value="thursday">Thursday</option>
                            <option value="friday">Friday</option>
                            <option value="saturday">Saturday</option>
                            <option value="sunday">Sunday</option>
                        </select>
                    </div>

                    <!-- Last Sync Information -->
                    <div
                        class="admin-kvGrid"
                        style="
                            margin-top: 20px;
                            padding-top: 20px;
                            border-top: 1px solid rgba(255, 255, 255, 0.1);
                        "
                    >
                        <div class="admin-kv">
                            <div class="admin-kv__k">Last Synced</div>
                            <div class="admin-kv__v">
                                <span v-if="provider.last_synced_at">
                                    {{ formatDate(provider.last_synced_at) }}
                                </span>
                                <span v-else class="text-muted">Never</span>
                            </div>
                        </div>
                        <div class="admin-kv">
                            <div class="admin-kv__k">Tenants Found</div>
                            <div class="admin-kv__v">
                                {{ provider.last_sync_count || 0 }}
                            </div>
                        </div>
                        <div
                            v-if="provider.last_sync_log"
                            class="admin-kv"
                            style="grid-column: 1 / -1"
                        >
                            <div class="admin-kv__k">Last Result</div>
                            <div class="admin-kv__v">
                                <span
                                    v-if="
                                        typeof provider.last_sync_log ===
                                        'string'
                                    "
                                >
                                    {{ parseSyncLog(provider.last_sync_log) }}
                                </span>
                                <span v-else>
                                    {{
                                        JSON.stringify(
                                            provider.last_sync_log,
                                        ).substring(0, 100)
                                    }}
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- Manual Trigger Button -->
                    <div style="margin-top: 20px; display: flex; gap: 10px">
                        <BaseButton
                            variant="secondary"
                            size="sm"
                            @click="triggerSync(provider.pbx_provider_id)"
                            :loading="syncing === provider.pbx_provider_id"
                        >
                            🔄 Sync Now
                        </BaseButton>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { ref, onMounted, reactive } from "vue";
import { BaseButton } from "../../components/admin/base";
import adminApi from "../../router/admin/api";

interface SyncSettings {
    enabled: boolean;
    frequency: "hourly" | "daily" | "weekly";
    scheduled_time: string;
    scheduled_day?: string;
}

interface Provider {
    pbx_provider_id: number;
    pbx_provider_name: string;
    last_synced_at: string | null;
    last_sync_count: number;
    last_sync_log: string | null;
}

const loading = ref(true);
const syncing = ref<number | null>(null);
const error = ref<string | null>(null);
const success = ref<string | null>(null);

const providers = ref<Provider[]>([]);
const settings = reactive<Record<number, SyncSettings>>({});

onMounted(async () => {
    await loadSettings();
});

const getSetting = (providerId: number): SyncSettings => {
    if (!settings[providerId]) {
        settings[providerId] = {
            enabled: false,
            frequency: "daily",
            scheduled_time: "02:00",
            scheduled_day: "monday",
        };
    }
    return settings[providerId];
};

const updateSetting = async (
    providerId: number,
    key: keyof SyncSettings,
    value: any,
) => {
    const setting = getSetting(providerId);
    (setting as any)[key] = value;
    await saveSettings(providerId);
};

const toggleEnabled = async (providerId: number) => {
    const setting = getSetting(providerId);
    setting.enabled = !setting.enabled;
    await saveSettings(providerId);
};

const loadSettings = async () => {
    try {
        loading.value = true;
        const response = await adminApi.get("/tenant-sync-settings");
        const data = response.data;
        providers.value = data;

        // Initialize settings object
        data.forEach((provider: Provider) => {
            settings[provider.pbx_provider_id] = {
                enabled: provider.enabled || false,
                frequency: provider.frequency || "daily",
                scheduled_time: provider.scheduled_time || "02:00",
                scheduled_day: provider.scheduled_day || "monday",
            };
        });
    } catch (err) {
        error.value =
            err instanceof Error ? err.message : "Failed to load settings";
    } finally {
        loading.value = false;
    }
};

const saveSettings = async (providerId: number) => {
    try {
        error.value = null;
        const payload = settings[providerId];

        const response = await adminApi.put(
            `/tenant-sync-settings/${providerId}`,
            payload,
        );

        success.value = "Settings saved successfully";
        setTimeout(() => {
            success.value = null;
        }, 3000);

        // Refresh to get updated data
        await loadSettings();
    } catch (err) {
        error.value =
            err instanceof Error ? err.message : "Failed to save settings";
    }
};

const triggerSync = async (providerId: number) => {
    try {
        syncing.value = providerId;
        error.value = null;

        const response = await adminApi.post(
            `/tenant-sync-settings/${providerId}/trigger`,
        );

        success.value = "Sync triggered successfully";
        setTimeout(() => {
            success.value = null;
        }, 3000);

        // Refresh to get updated data
        await loadSettings();
    } catch (err) {
        error.value =
            err instanceof Error ? err.message : "Failed to trigger sync";
    } finally {
        syncing.value = null;
    }
};

const formatDate = (dateString: string) => {
    if (!dateString) return "—";
    const date = new Date(dateString);
    return date.toLocaleString();
};

const parseSyncLog = (logString: string) => {
    try {
        const log = JSON.parse(logString);
        if (log.error) return `Error: ${log.error}`;
        if (log.created_companies !== undefined) {
            return `Created: ${log.created_companies}, Linked: ${log.linked_companies}, Skipped: ${log.skipped_companies}`;
        }
        return JSON.stringify(log).substring(0, 100);
    } catch {
        return logString.substring(0, 100);
    }
};
</script>

<style scoped>
.admin-tenantSyncGrid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(400px, 1fr));
    gap: 20px;
}

.admin-tenantSyncForm {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.admin-toggle-wrapper {
    position: relative;
    display: inline-flex;
    width: 44px;
    height: 24px;
}

.admin-toggle {
    display: none;
}

.admin-toggle-label {
    position: absolute;
    width: 100%;
    height: 100%;
    background-color: rgba(255, 255, 255, 0.1);
    border: 1px solid rgba(255, 255, 255, 0.2);
    border-radius: 12px;
    cursor: pointer;
    transition: all 0.3s ease;
}

.admin-toggle-label::after {
    content: "";
    position: absolute;
    width: 20px;
    height: 20px;
    background-color: white;
    border-radius: 10px;
    top: 2px;
    left: 2px;
    transition: all 0.3s ease;
}

.admin-toggle:checked + .admin-toggle-label {
    background-color: #4ade80;
    border-color: #22c55e;
}

.admin-toggle:checked + .admin-toggle-label::after {
    left: 22px;
}

.text-sm {
    font-size: 0.875rem;
}

.text-muted {
    color: rgba(255, 255, 255, 0.5);
}

@media (max-width: 768px) {
    .admin-tenantSyncGrid {
        grid-template-columns: 1fr;
    }
}
</style>
