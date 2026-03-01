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
                            <span
                                v-if="hasChanges(provider.pbx_provider_id)"
                                class="unsaved-badge"
                                >●</span
                            >
                        </h2>
                        <p class="admin-card__hint">
                            Automatic tenant discovery and linking.
                        </p>
                    </div>
                    <div style="display: flex; gap: 8px; align-items: center">
                        <div class="admin-toggle-wrapper">
                            <input
                                :id="`enabled-${provider.pbx_provider_id}`"
                                v-model="
                                    getSetting(provider.pbx_provider_id).enabled
                                "
                                type="checkbox"
                                class="admin-toggle"
                                @change="
                                    markAsChanged(provider.pbx_provider_id)
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
                            v-model="
                                getSetting(provider.pbx_provider_id).frequency
                            "
                            class="admin-input"
                            @change="markAsChanged(provider.pbx_provider_id)"
                        >
                            <option value="every_minutes">
                                Every X Minutes
                            </option>
                            <option value="hourly">Hourly</option>
                            <option value="daily">Daily</option>
                            <option value="weekly">Weekly</option>
                        </select>
                    </div>

                    <!-- Minute Interval -->
                    <div
                        v-if="usesMinuteInterval(provider.pbx_provider_id)"
                        class="admin-field"
                    >
                        <label class="admin-field__label">
                            Every (minutes)
                        </label>
                        <input
                            v-model.number="
                                getSetting(provider.pbx_provider_id)
                                    .interval_minutes
                            "
                            type="number"
                            min="1"
                            max="59"
                            class="admin-input"
                            @change="markAsChanged(provider.pbx_provider_id)"
                        />
                    </div>

                    <!-- Scheduled Time -->
                    <div
                        v-if="requiresScheduledTime(provider.pbx_provider_id)"
                        class="admin-field"
                    >
                        <label class="admin-field__label">
                            Scheduled Time (UTC)
                        </label>
                        <input
                            v-model="
                                getSetting(provider.pbx_provider_id)
                                    .scheduled_time
                            "
                            type="time"
                            class="admin-input"
                            @change="markAsChanged(provider.pbx_provider_id)"
                        />
                    </div>

                    <!-- Weekly Day Selection -->
                    <div
                        v-if="requiresScheduledDay(provider.pbx_provider_id)"
                        class="admin-field"
                    >
                        <label class="admin-field__label"> Day of Week </label>
                        <select
                            v-model="
                                getSetting(provider.pbx_provider_id)
                                    .scheduled_day
                            "
                            class="admin-input"
                            @change="markAsChanged(provider.pbx_provider_id)"
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

                    <!-- Action Buttons -->
                    <div
                        style="
                            margin-top: 20px;
                            display: flex;
                            gap: 10px;
                            flex-wrap: wrap;
                        "
                    >
                        <BaseButton
                            variant="primary"
                            size="sm"
                            @click="saveSettings(provider.pbx_provider_id)"
                            :loading="saving === provider.pbx_provider_id"
                            :disabled="!hasChanges(provider.pbx_provider_id)"
                        >
                            💾 Update Settings
                        </BaseButton>
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
    frequency: "every_minutes" | "hourly" | "daily" | "weekly";
    interval_minutes: number;
    scheduled_time: string;
    scheduled_day?: string;
}

interface Provider {
    pbx_provider_id: number;
    pbx_provider_name: string;
    enabled?: boolean;
    frequency?: "every_minutes" | "hourly" | "daily" | "weekly";
    interval_minutes?: number;
    scheduled_time?: string;
    scheduled_day?: string;
    last_synced_at: string | null;
    last_sync_count: number;
    last_sync_log: string | null;
}

const loading = ref(true);
const saving = ref<number | null>(null);
const syncing = ref<number | null>(null);
const error = ref<string | null>(null);
const success = ref<string | null>(null);

const providers = ref<Provider[]>([]);
const settings = reactive<Record<number, SyncSettings>>({});
const changedProviders = ref<Set<number>>(new Set());

onMounted(async () => {
    await loadSettings();
});

const getSetting = (providerId: number): SyncSettings => {
    if (!settings[providerId]) {
        settings[providerId] = {
            enabled: false,
            frequency: "daily",
            interval_minutes: 5,
            scheduled_time: "02:00",
            scheduled_day: "monday",
        };
    }
    return settings[providerId];
};

const markAsChanged = (providerId: number) => {
    changedProviders.value.add(providerId);
};

const hasChanges = (providerId: number): boolean => {
    return changedProviders.value.has(providerId);
};

const usesMinuteInterval = (providerId: number): boolean => {
    return getSetting(providerId).frequency === "every_minutes";
};

const requiresScheduledTime = (providerId: number): boolean => {
    const frequency = getSetting(providerId).frequency;
    return frequency === "daily" || frequency === "weekly";
};

const requiresScheduledDay = (providerId: number): boolean => {
    return getSetting(providerId).frequency === "weekly";
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
                interval_minutes: Number(provider.interval_minutes || 5),
                scheduled_time: provider.scheduled_time || "02:00",
                scheduled_day: provider.scheduled_day || "monday",
            };
        });

        // Clear changed tracking after load
        changedProviders.value.clear();
    } catch (err) {
        error.value =
            err instanceof Error ? err.message : "Failed to load settings";
    } finally {
        loading.value = false;
    }
};

const saveSettings = async (providerId: number) => {
    try {
        saving.value = providerId;
        error.value = null;
        const payload = settings[providerId];

        const response = await adminApi.put(
            `/tenant-sync-settings/${providerId}`,
            payload,
        );

        success.value = "Settings saved successfully";
        changedProviders.value.delete(providerId);
        setTimeout(() => {
            success.value = null;
        }, 3000);

        // Refresh to get updated data
        await loadSettings();
    } catch (err) {
        error.value =
            err instanceof Error ? err.message : "Failed to save settings";
    } finally {
        saving.value = null;
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

.unsaved-badge {
    display: inline-block;
    margin-left: 8px;
    color: #fbbf24;
    font-size: 1.2em;
    animation: pulse 2s ease-in-out infinite;
}

@keyframes pulse {
    0%,
    100% {
        opacity: 1;
    }
    50% {
        opacity: 0.5;
    }
}

@media (max-width: 768px) {
    .admin-tenantSyncGrid {
        grid-template-columns: 1fr;
    }
}
</style>
