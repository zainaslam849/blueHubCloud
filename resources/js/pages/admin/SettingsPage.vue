<template>
    <div class="admin-container admin-page settings-page">
        <header class="admin-page__header settings-hero">
            <div class="settings-hero__intro">
                <p class="admin-page__kicker">Settings</p>
                <h1 class="admin-page__title">General</h1>
                <p class="admin-page__subtitle">
                    Configure branding, access, and security for your admin
                    workspace.
                </p>
            </div>
            <div class="settings-hero__profileCard">
                <div class="settings-hero__avatar">
                    <img
                        v-if="logoPreviewUrl"
                        :src="logoPreviewUrl"
                        alt="Admin logo"
                    />
                    <span v-else>{{ siteInitials }}</span>
                </div>
                <div class="settings-hero__meta">
                    <div class="settings-hero__name">
                        {{ siteName || "BlueHubCloud" }}
                    </div>
                    <div class="settings-hero__role">Admin workspace</div>
                    <div class="settings-hero__tags">
                        <span class="settings-pill">Branding</span>
                        <span class="settings-pill settings-pill--soft"
                            >Security</span
                        >
                        <span class="settings-pill settings-pill--soft"
                            >Admin</span
                        >
                    </div>
                </div>
                <div class="settings-hero__preview">
                    <span class="settings-hero__favicon">
                        <img
                            v-if="faviconPreviewUrl"
                            :src="faviconPreviewUrl"
                            alt="Admin favicon"
                        />
                        <span v-else class="settings-hero__faviconDot" />
                    </span>
                </div>
            </div>
        </header>

        <div class="settings-layout">
            <section class="admin-card admin-card--glass settings-section">
                <div class="settings-section__head">
                    <div>
                        <h2 class="admin-card__headline">Branding</h2>
                        <p class="settings-section__subhead">
                            Upload brand assets and customize the admin name.
                        </p>
                    </div>
                </div>

                <div class="settings-section__body">
                    <div class="settings-form">
                        <div class="admin-form-row">
                            <label class="admin-label">Site name</label>
                            <input
                                v-model.trim="siteName"
                                class="admin-input"
                                type="text"
                                placeholder="BlueHubCloud"
                            />
                            <p class="admin-card__hint" style="margin-top: 8px">
                                Used for the admin header and browser title.
                            </p>
                        </div>

                        <div class="settings-upload-grid">
                            <div class="settings-upload">
                                <div class="settings-upload__label">
                                    Admin logo
                                </div>
                                <label class="settings-upload__drop">
                                    <input
                                        class="settings-upload__input"
                                        type="file"
                                        accept="image/png,image/jpeg,image/svg+xml,image/webp"
                                        @change="onLogoChange"
                                    />
                                    <span class="settings-upload__title">
                                        Drop file or browse
                                    </span>
                                    <span class="settings-upload__meta">
                                        PNG, SVG, WEBP · Max 2MB
                                    </span>
                                </label>
                                <div class="settings-upload__actions">
                                    <span class="settings-upload__filename">
                                        {{ logoFileName }}
                                    </span>
                                    <BaseButton
                                        v-if="logoPreviewUrl"
                                        variant="ghost"
                                        size="sm"
                                        @click="clearLogo"
                                    >
                                        Remove
                                    </BaseButton>
                                </div>
                            </div>

                            <div class="settings-upload">
                                <div class="settings-upload__label">
                                    Admin favicon
                                </div>
                                <label class="settings-upload__drop">
                                    <input
                                        class="settings-upload__input"
                                        type="file"
                                        accept="image/png,image/x-icon,image/svg+xml"
                                        @change="onFaviconChange"
                                    />
                                    <span class="settings-upload__title">
                                        Drop file or browse
                                    </span>
                                    <span class="settings-upload__meta">
                                        PNG, ICO, SVG · Max 1MB
                                    </span>
                                </label>
                                <div class="settings-upload__actions">
                                    <span class="settings-upload__filename">
                                        {{ faviconFileName }}
                                    </span>
                                    <BaseButton
                                        v-if="faviconPreviewUrl"
                                        variant="ghost"
                                        size="sm"
                                        @click="clearFavicon"
                                    >
                                        Remove
                                    </BaseButton>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="settings-preview-card">
                        <div class="settings-preview__tab">
                            <span class="settings-preview__favicon">
                                <img
                                    v-if="faviconPreviewUrl"
                                    :src="faviconPreviewUrl"
                                    alt="Admin favicon preview"
                                />
                                <span v-else class="settings-preview__dot" />
                            </span>
                            <span class="settings-preview__title">
                                {{ siteName || "BlueHubCloud" }} — Admin
                            </span>
                        </div>

                        <div class="settings-preview__body">
                            <div class="settings-preview__logo">
                                <img
                                    v-if="logoPreviewUrl"
                                    :src="logoPreviewUrl"
                                    alt="Admin logo preview"
                                />
                                <div
                                    v-else
                                    class="settings-preview__placeholder"
                                >
                                    Logo preview
                                </div>
                            </div>
                            <div class="settings-preview__meta">
                                <div class="settings-preview__name">
                                    {{ siteName || "BlueHubCloud" }}
                                </div>
                                <div class="settings-preview__subtitle">
                                    Admin console
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="settings-section__footer">
                    <BaseButton
                        variant="primary"
                        size="md"
                        :loading="saving"
                        :disabled="saving"
                        @click="saveSettings"
                    >
                        <template v-if="saving">Saving...</template>
                        <template v-else>Save branding</template>
                    </BaseButton>
                    <div class="settings-section__status">
                        <div
                            v-if="settingsError"
                            class="admin-alert admin-alert--error"
                        >
                            {{ settingsError }}
                        </div>
                        <div
                            v-if="settingsSuccess"
                            class="admin-alert admin-alert--success"
                        >
                            Branding saved.
                        </div>
                    </div>
                </div>
            </section>

            <section class="admin-card admin-card--glass settings-section">
                <div class="settings-section__head">
                    <div>
                        <h2 class="admin-card__headline">Security</h2>
                        <p class="settings-section__subhead">
                            Rotate your admin password and keep access secure.
                        </p>
                    </div>
                    <div class="settings-section__badge">Protected</div>
                </div>

                <div class="settings-security">
                    <div class="settings-security__form">
                        <div class="admin-form-row">
                            <label class="admin-label">Current password</label>
                            <input
                                v-model="currentPassword"
                                class="admin-input"
                                type="password"
                                autocomplete="current-password"
                            />
                        </div>

                        <div class="admin-form-row" style="margin-top: 12px">
                            <label class="admin-label">New password</label>
                            <input
                                v-model="newPassword"
                                class="admin-input"
                                type="password"
                                autocomplete="new-password"
                            />
                        </div>

                        <div class="admin-form-row" style="margin-top: 12px">
                            <label class="admin-label"
                                >Confirm new password</label
                            >
                            <input
                                v-model="newPasswordConfirm"
                                class="admin-input"
                                type="password"
                                autocomplete="new-password"
                            />
                        </div>
                    </div>

                    <div class="settings-security__tips">
                        <div class="settings-tip">
                            <div class="settings-tip__title">
                                Password requirements
                            </div>
                            <ul class="settings-tip__list">
                                <li>Minimum 8 characters.</li>
                                <li>Include a mix of letters and numbers.</li>
                                <li>Avoid reusing previous passwords.</li>
                            </ul>
                        </div>

                        <div class="settings-tip settings-tip--accent">
                            <div class="settings-tip__title">Best practice</div>
                            <p class="settings-tip__copy">
                                Rotate admin credentials quarterly and store in
                                a secure password manager.
                            </p>
                        </div>
                    </div>
                </div>

                <div class="settings-section__footer">
                    <BaseButton
                        variant="primary"
                        size="md"
                        :loading="passwordSaving"
                        :disabled="passwordSaving"
                        @click="savePassword"
                    >
                        <template v-if="passwordSaving">Updating...</template>
                        <template v-else>Update password</template>
                    </BaseButton>
                    <div class="settings-section__status">
                        <div
                            v-if="passwordError"
                            class="admin-alert admin-alert--error"
                        >
                            {{ passwordError }}
                        </div>
                        <div
                            v-if="passwordSuccess"
                            class="admin-alert admin-alert--success"
                        >
                            Password updated.
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </div>
</template>

<script setup>
import { computed, onMounted, onBeforeUnmount, ref } from "vue";
import { BaseButton } from "../../components/admin/base";
import adminApi from "../../router/admin/api";

const siteName = ref("");
const logoFile = ref(null);
const faviconFile = ref(null);
const logoPreviewUrl = ref("");
const faviconPreviewUrl = ref("");
const logoClear = ref(false);
const faviconClear = ref(false);

const saving = ref(false);
const settingsError = ref("");
const settingsSuccess = ref(false);

const currentPassword = ref("");
const newPassword = ref("");
const newPasswordConfirm = ref("");
const passwordSaving = ref(false);
const passwordError = ref("");
const passwordSuccess = ref(false);

const logoFileName = computed(() => {
    if (logoFile.value?.name) return logoFile.value.name;
    if (logoPreviewUrl.value) return "Current logo";
    return "No file selected";
});

const faviconFileName = computed(() => {
    if (faviconFile.value?.name) return faviconFile.value.name;
    if (faviconPreviewUrl.value) return "Current favicon";
    return "No file selected";
});

const siteInitials = computed(() => {
    const name = (siteName.value || "BlueHubCloud").trim();
    if (!name) return "BH";
    const parts = name.split(/\s+/).filter(Boolean);
    const initials = parts.slice(0, 2).map((part) => part[0]?.toUpperCase());
    return initials.join("") || "BH";
});

async function loadSettings() {
    settingsError.value = "";
    try {
        const res = await adminApi.get("/settings");
        const data = res?.data?.data || {};
        siteName.value = data.site_name ?? "";
        logoPreviewUrl.value = data.admin_logo_url ?? "";
        faviconPreviewUrl.value = data.admin_favicon_url ?? "";
    } catch (e) {
        settingsError.value =
            e?.response?.data?.message || "Failed to load settings.";
    }
}

function revokePreview(url) {
    if (url && url.startsWith("blob:")) {
        URL.revokeObjectURL(url);
    }
}

function onLogoChange(event) {
    const file = event?.target?.files?.[0] || null;
    logoFile.value = file;
    logoClear.value = false;
    revokePreview(logoPreviewUrl.value);
    logoPreviewUrl.value = file ? URL.createObjectURL(file) : "";
}

function onFaviconChange(event) {
    const file = event?.target?.files?.[0] || null;
    faviconFile.value = file;
    faviconClear.value = false;
    revokePreview(faviconPreviewUrl.value);
    faviconPreviewUrl.value = file ? URL.createObjectURL(file) : "";
}

function clearLogo() {
    revokePreview(logoPreviewUrl.value);
    logoFile.value = null;
    logoPreviewUrl.value = "";
    logoClear.value = true;
}

function clearFavicon() {
    revokePreview(faviconPreviewUrl.value);
    faviconFile.value = null;
    faviconPreviewUrl.value = "";
    faviconClear.value = true;
}

async function saveSettings() {
    if (saving.value) return;
    saving.value = true;
    settingsError.value = "";
    settingsSuccess.value = false;

    try {
        const formData = new FormData();
        formData.append("site_name", siteName.value || "");
        if (logoFile.value) {
            formData.append("admin_logo", logoFile.value);
        }
        if (faviconFile.value) {
            formData.append("admin_favicon", faviconFile.value);
        }
        if (logoClear.value) {
            formData.append("admin_logo_clear", "1");
        }
        if (faviconClear.value) {
            formData.append("admin_favicon_clear", "1");
        }

        const res = await adminApi.post("/settings", formData, {
            headers: { "Content-Type": "multipart/form-data" },
        });
        const data = res?.data?.data || {};
        siteName.value = data.site_name ?? siteName.value;
        logoFile.value = null;
        faviconFile.value = null;
        revokePreview(logoPreviewUrl.value);
        revokePreview(faviconPreviewUrl.value);
        logoPreviewUrl.value = data.admin_logo_url ?? logoPreviewUrl.value;
        faviconPreviewUrl.value =
            data.admin_favicon_url ?? faviconPreviewUrl.value;
        logoClear.value = false;
        faviconClear.value = false;
        settingsSuccess.value = true;

        try {
            window.dispatchEvent(
                new CustomEvent("admin-settings-updated", {
                    detail: {
                        site_name: siteName.value,
                        admin_logo_url: logoPreviewUrl.value,
                        admin_favicon_url: faviconPreviewUrl.value,
                    },
                }),
            );
        } catch (e) {
            // ignore
        }
    } catch (e) {
        settingsError.value =
            e?.response?.data?.message || "Failed to save settings.";
    } finally {
        saving.value = false;
    }
}

async function savePassword() {
    if (passwordSaving.value) return;
    passwordSaving.value = true;
    passwordError.value = "";
    passwordSuccess.value = false;

    try {
        await adminApi.post("/settings/password", {
            current_password: currentPassword.value,
            new_password: newPassword.value,
            new_password_confirmation: newPasswordConfirm.value,
        });
        passwordSuccess.value = true;
        currentPassword.value = "";
        newPassword.value = "";
        newPasswordConfirm.value = "";
    } catch (e) {
        passwordError.value =
            e?.response?.data?.message || "Failed to update password.";
    } finally {
        passwordSaving.value = false;
    }
}

onMounted(() => {
    loadSettings();
});

onBeforeUnmount(() => {
    revokePreview(logoPreviewUrl.value);
    revokePreview(faviconPreviewUrl.value);
});
</script>

<style scoped>
.settings-page {
    display: grid;
    gap: 24px;
}

.settings-hero {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 0.9fr);
    gap: 20px;
    align-items: center;
}

.settings-hero__intro {
    display: grid;
    gap: 6px;
}

.settings-hero__profileCard {
    display: grid;
    grid-template-columns: auto 1fr auto;
    gap: 16px;
    align-items: center;
    padding: 16px 18px;
    border-radius: 20px;
    border: 1px solid var(--border-soft);
    background: var(--bg-surface);
    box-shadow: var(--shadow-elev-1);
}

.settings-hero__avatar {
    width: 56px;
    height: 56px;
    border-radius: 16px;
    display: grid;
    place-items: center;
    background: linear-gradient(
        135deg,
        var(--admin-logo-grad-1),
        var(--admin-logo-grad-2)
    );
    color: var(--text-on-accent);
    font-weight: 700;
    font-size: 18px;
    overflow: hidden;
    border: 1px solid var(--border-soft);
}

.settings-hero__avatar img {
    width: 100%;
    height: 100%;
    object-fit: contain;
    background: transparent;
    padding: 8px;
    box-sizing: border-box;
    filter: drop-shadow(0 2px 4px rgba(15, 23, 42, 0.15));
}

.settings-hero__meta {
    display: grid;
    gap: 6px;
}

.settings-hero__name {
    font-size: 18px;
    font-weight: 700;
}

.settings-hero__role {
    font-size: 13px;
    color: var(--text-muted);
}

.settings-hero__tags {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.settings-pill {
    font-size: 11px;
    padding: 6px 10px;
    border-radius: 999px;
    background: var(--accent-hover-bg);
    color: var(--text-primary);
    border: 1px solid var(--accent-border);
    text-transform: uppercase;
    letter-spacing: 0.08em;
}

.settings-pill--soft {
    background: var(--bg-soft);
    border-color: var(--border-soft);
    color: var(--text-secondary);
}

.settings-hero__preview {
    display: flex;
    align-items: center;
    gap: 10px;
    padding-left: 16px;
    border-left: 1px solid var(--border-faint);
    color: var(--text-secondary);
    font-size: 12px;
    font-weight: 600;
}

.settings-hero__favicon {
    width: 28px;
    height: 28px;
    border-radius: 10px;
    display: grid;
    place-items: center;
    background: var(--bg-surface-2);
    border: 1px solid var(--border-soft);
}

.settings-hero__favicon img {
    width: 16px;
    height: 16px;
    object-fit: contain;
}

.settings-hero__faviconDot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    background: var(--text-muted);
}

.settings-hero__previewLabel {
    text-transform: uppercase;
    letter-spacing: 0.08em;
}

.settings-layout {
    display: grid;
    gap: 20px;
}

.settings-section {
    padding: 24px;
    display: grid;
    gap: 20px;
    border-radius: 22px;
    background: var(--bg-surface);
    border: 1px solid var(--border-soft);
    box-shadow: var(--shadow-elev-1);
}

.settings-section__head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
}

.settings-section__subhead {
    margin-top: 6px;
    opacity: 0.7;
    font-size: 14px;
}

.settings-section__badge {
    padding: 6px 12px;
    font-size: 12px;
    border-radius: 999px;
    background: var(--bg-soft);
    border: 1px solid var(--border-soft);
    text-transform: uppercase;
    letter-spacing: 0.08em;
}

.settings-section__body {
    display: grid;
    grid-template-columns: minmax(0, 1.1fr) minmax(0, 0.9fr);
    gap: 20px;
    align-items: start;
}

.settings-section__footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    flex-wrap: wrap;
}

.settings-section__status {
    display: grid;
    gap: 8px;
}

.settings-form {
    display: grid;
    gap: 18px;
}

.settings-upload-grid {
    display: grid;
    gap: 16px;
}

.settings-upload {
    display: grid;
    gap: 10px;
    padding: 16px;
    border-radius: 16px;
    border: 1px solid var(--border-soft);
    background: var(--bg-surface-2);
}

.settings-upload__label {
    font-size: 13px;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    opacity: 0.75;
}

.settings-upload__drop {
    display: grid;
    gap: 6px;
    padding: 16px;
    border-radius: 14px;
    border: 1px dashed var(--border-soft);
    background: var(--bg-faint);
    cursor: pointer;
    transition:
        border-color 0.2s ease,
        background 0.2s ease;
}

.settings-upload__drop:hover {
    border-color: var(--accent-border);
    background: var(--accent-hover-bg);
}

.settings-upload__input {
    display: none;
}

.settings-upload__title {
    font-weight: 600;
}

.settings-upload__meta {
    font-size: 12px;
    opacity: 0.65;
}

.settings-upload__actions {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
}

.settings-upload__filename {
    font-size: 13px;
    opacity: 0.7;
}

.settings-preview-card {
    padding: 18px;
    border-radius: 18px;
    border: 1px solid var(--border-soft);
    background: linear-gradient(
        150deg,
        color-mix(in srgb, var(--bg-surface-2) 80%, transparent),
        var(--bg-surface)
    );
    display: grid;
    gap: 16px;
}

.settings-preview__tab {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px 12px;
    border-radius: 999px;
    background: var(--bg-soft);
    border: 1px solid var(--border-soft);
    font-size: 12px;
}

.settings-preview__favicon {
    width: 18px;
    height: 18px;
    border-radius: 6px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--bg-surface-2);
}

.settings-preview__favicon img {
    width: 14px;
    height: 14px;
    object-fit: contain;
}

.settings-preview__dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    background: var(--text-muted);
}

.settings-preview__title {
    opacity: 0.8;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.settings-preview__body {
    display: grid;
    gap: 16px;
    justify-items: center;
}

.settings-preview__logo {
    width: 100%;
    height: 140px;
    border-radius: 16px;
    border: 1px dashed var(--border-soft);
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--bg-faint);
}

.settings-preview__logo img {
    max-height: 90px;
    max-width: 70%;
    object-fit: contain;
}

.settings-preview__placeholder {
    font-size: 13px;
    opacity: 0.6;
}

.settings-preview__meta {
    text-align: center;
}

.settings-preview__name {
    font-size: 18px;
    font-weight: 600;
}

.settings-preview__subtitle {
    font-size: 13px;
    opacity: 0.7;
}

.settings-security {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 0.8fr);
    gap: 20px;
    align-items: start;
}

.settings-security__form {
    display: grid;
    gap: 12px;
}

.settings-security__tips {
    display: grid;
    gap: 12px;
}

.settings-tip {
    padding: 16px;
    border-radius: 16px;
    border: 1px solid var(--border-soft);
    background: var(--bg-surface-2);
}

.settings-tip--accent {
    background: var(--accent-hover-bg);
    border-color: var(--accent-border);
}

.settings-tip__title {
    font-weight: 600;
    margin-bottom: 8px;
}

.settings-tip__list {
    margin: 0;
    padding-left: 18px;
    display: grid;
    gap: 6px;
    font-size: 13px;
    opacity: 0.75;
}

.settings-tip__copy {
    font-size: 13px;
    opacity: 0.75;
    margin: 0;
}

@media (max-width: 1024px) {
    .settings-section__body,
    .settings-security {
        grid-template-columns: 1fr;
    }

    .settings-hero {
        grid-template-columns: 1fr;
        align-items: stretch;
    }

    .settings-hero__profileCard {
        grid-template-columns: auto 1fr;
        grid-template-rows: auto auto;
    }

    .settings-hero__preview {
        border-left: none;
        padding-left: 0;
        grid-column: 1 / -1;
    }
}

@media (max-width: 640px) {
    .settings-hero__profileCard {
        grid-template-columns: 1fr;
    }

    .settings-hero__avatar {
        width: 48px;
        height: 48px;
        border-radius: 14px;
    }
}
</style>
