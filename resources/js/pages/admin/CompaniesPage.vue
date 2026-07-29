<template>
    <div class="admin-container admin-page">
        <header class="admin-page__header">
            <div>
                <p class="admin-page__kicker">Accounts</p>
                <h1 class="admin-page__title">Companies</h1>
                <p class="admin-page__subtitle">
                    Manage companies and PBXware server assignments.
                </p>
            </div>
            <div style="display: flex; gap: 10px; flex-wrap: wrap">
                <BaseButton
                    v-if="pbxProviders.length > 0"
                    variant="secondary"
                    size="md"
                    @click="openSyncModal"
                    :loading="syncing"
                >
                    🔄 Sync Tenants
                </BaseButton>
                <BaseButton variant="primary" size="md" @click="openAddForm">
                    + Add Company
                </BaseButton>
            </div>
        </header>

        <section class="admin-card admin-card--glass">
            <!-- Search and Filter Toolbar -->
            <div class="admin-companiesToolbar">
                <div
                    class="admin-field admin-companiesToolbar__search"
                    style="flex: 1"
                >
                    <label class="admin-field__label" for="companies-search">
                        Search
                    </label>
                    <input
                        id="companies-search"
                        v-model="search"
                        class="admin-input"
                        type="search"
                        autocomplete="off"
                        placeholder="Company name, server ID, tenant code…"
                    />
                </div>
                <div class="admin-field" style="min-width: 180px">
                    <label class="admin-field__label" for="company-server-filter">Server</label>
                    <select
                        id="company-server-filter"
                        v-model="serverFilter"
                        class="admin-input"
                        @change="onServerFilterChange"
                    >
                        <option value="">All servers</option>
                        <option
                            v-for="provider in pbxProviders"
                            :key="provider.id"
                            :value="provider.id"
                        >
                            {{ provider.name }}
                        </option>
                    </select>
                </div>
                <BaseButton
                    variant="secondary"
                    size="sm"
                    :loading="loading"
                    @click="refresh"
                    style="align-self: flex-end; margin-bottom: 8px"
                >
                    <span style="margin-right: 4px">↻</span>
                    Refresh
                </BaseButton>
            </div>

            <div v-if="error" class="admin-alert admin-alert--error">
                {{ error }}
            </div>

            <div v-if="loading" class="admin-tableWrap">
                <div class="admin-loadingState">
                    <p>Loading companies...</p>
                </div>
            </div>

            <div v-else-if="rows.length === 0" class="admin-tableWrap">
                <div class="admin-emptyState">
                    <p>No companies found.</p>
                </div>
            </div>

            <div v-else class="admin-tableWrap">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th class="admin-table__th">
                                <button
                                    type="button"
                                    class="admin-companiesSortBtn"
                                    @click="toggleSort('name')"
                                >
                                    Name
                                    <span
                                        class="admin-companiesSortBtn__chev"
                                        >{{ sortGlyph("name") }}</span
                                    >
                                </button>
                            </th>
                            <th class="admin-table__th">Server</th>
                            <th class="admin-table__th">Tenant ID</th>
                            <th class="admin-table__th">Tenant Code</th>
                            <th class="admin-table__th">Call Limit</th>
                            <th class="admin-table__th">Package</th>
                            <th class="admin-table__th">
                                <button
                                    type="button"
                                    class="admin-companiesSortBtn"
                                    @click="toggleSort('status')"
                                >
                                    Status
                                    <span
                                        class="admin-companiesSortBtn__chev"
                                        >{{ sortGlyph("status") }}</span
                                    >
                                </button>
                            </th>
                            <th
                                class="admin-table__th"
                                style="text-align: right"
                            >
                                Actions
                            </th>
                        </tr>
                    </thead>
                    <tbody class="admin-table__body">
                        <tr
                            v-for="company in rows"
                            :key="company.id"
                            class="admin-table__tr"
                        >
                            <td class="admin-table__td" data-label="Name">
                                <span class="font-medium">{{
                                    company.name
                                }}</span>
                            </td>
                            <td class="admin-table__td" data-label="Server">
                                <span
                                    v-if="company.pbx_provider_name"
                                    class="admin-status-badge"
                                >{{ company.pbx_provider_name }}</span>
                                <span v-else class="text-muted">—</span>
                            </td>
                            <td class="admin-table__td" data-label="Tenant ID">
                                <code v-if="company.server_id">{{
                                    company.server_id
                                }}</code>
                                <span v-else class="text-muted">—</span>
                            </td>
                            <td
                                class="admin-table__td"
                                data-label="Tenant Code"
                            >
                                <code v-if="company.tenant_code">{{
                                    company.tenant_code
                                }}</code>
                                <span v-else class="text-muted">—</span>
                            </td>
                            <td class="admin-table__td" data-label="Call Limit">
                                <template v-if="company.monthly_call_limit != null">
                                    <div class="cmpLimit">
                                        <span class="cmpLimit__nums">
                                            {{ Number(company.call_limit_used).toLocaleString() }}
                                            / {{ Number(company.monthly_call_limit).toLocaleString() }}
                                        </span>
                                        <span
                                            v-if="company.call_limit_period_completed"
                                            class="admin-status-badge admin-status-badge--inactive cmpLimit__badge"
                                        >Period ended</span>
                                        <span
                                            v-else-if="company.call_limit_expires_at"
                                            class="cmpLimit__exp"
                                        >exp {{ company.call_limit_expires_at }}</span>
                                    </div>
                                    <button
                                        v-if="!company.deleted_at"
                                        type="button"
                                        class="cmpLimit__renew"
                                        @click="openRenew(company)"
                                    >Renew</button>
                                </template>
                                <span v-else class="text-muted">Unlimited</span>
                            </td>
                            <td class="admin-table__td" data-label="Package">
                                <span v-if="company.package_name">{{
                                    company.package_name
                                }}</span>
                                <span v-else class="text-muted">—</span>
                            </td>
                            <td class="admin-table__td" data-label="Status">
                                <span
                                    :class="[
                                        'admin-status-badge',
                                        company.deleted_at
                                            ? 'admin-status-badge--inactive'
                                            : company.status === 'active'
                                              ? 'admin-status-badge--active'
                                              : 'admin-status-badge--inactive',
                                    ]"
                                >
                                    {{
                                        company.deleted_at
                                            ? "Deleted"
                                            : company.status === "active"
                                              ? "Active"
                                              : "Inactive"
                                    }}
                                </span>
                            </td>
                            <td
                                class="admin-table__td admin-table__td--actions"
                                data-label="Actions"
                            >
                                <div class="admin-table__actions">
                                    <BaseButton
                                        v-if="!company.deleted_at"
                                        @click="openEditForm(company)"
                                        size="sm"
                                        variant="secondary"
                                        class="admin-actionBtn admin-actionBtn--edit"
                                    >
                                        <span class="admin-actionBtn__icon"
                                            >✎</span
                                        >
                                        <span class="admin-actionBtn__text"
                                            >Edit</span
                                        >
                                    </BaseButton>
                                    <BaseButton
                                        v-if="!company.deleted_at"
                                        @click="openDeleteConfirm(company)"
                                        size="sm"
                                        variant="danger"
                                        class="admin-actionBtn admin-actionBtn--delete"
                                        :disabled="
                                            deleting &&
                                            deleteTarget?.id === company.id
                                        "
                                    >
                                        <span class="admin-actionBtn__icon"
                                            >🗑</span
                                        >
                                        <span class="admin-actionBtn__text"
                                            >Delete</span
                                        >
                                    </BaseButton>
                                    <BaseButton
                                        v-if="company.deleted_at"
                                        @click="
                                            openPermanentDeleteConfirm(company)
                                        "
                                        size="sm"
                                        variant="danger"
                                        class="admin-actionBtn admin-actionBtn--delete"
                                        :disabled="
                                            deleting &&
                                            deleteTarget?.id === company.id
                                        "
                                    >
                                        <span class="admin-actionBtn__icon"
                                            >⚠</span
                                        >
                                        <span class="admin-actionBtn__text"
                                            >Delete Permanently</span
                                        >
                                    </BaseButton>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagination Footer -->
            <div class="admin-companiesFooter">
                <BasePagination
                    v-model:page="page"
                    v-model:pageSize="pageSize"
                    :total="meta.total"
                    :disabled="loading"
                    :page-size-options="[10, 25, 50, 100, 200]"
                    hint="Server-side pagination"
                    @change="fetchCompanies"
                />
            </div>
        </section>

        <!-- Renew Call Limit Modal -->
        <Teleport to="body">
            <Transition name="admin-modal">
                <div v-if="showRenew" class="admin-modalOverlay" @click="closeRenew">
                    <div class="admin-modal" style="max-width: 460px" @click.stop>
                        <div class="admin-modal__header">
                            <h2 class="admin-modal__title">Renew Call Limit</h2>
                            <button class="admin-modal__close" @click="closeRenew">✕</button>
                        </div>
                        <div class="admin-modal__body">
                            <p class="admin-field__help" style="margin-bottom: 14px">
                                Renewing resets used calls to 0 for
                                <strong>{{ renewTarget?.name }}</strong> and sets the next expiry date.
                            </p>
                            <div v-if="renewError" class="admin-alert admin-alert--error">{{ renewError }}</div>

                            <div class="admin-field">
                                <label class="admin-field__label" for="renew-limit">Monthly Call Limit</label>
                                <input
                                    id="renew-limit"
                                    v-model="renewForm.monthly_call_limit"
                                    class="admin-input"
                                    type="number"
                                    min="0"
                                    placeholder="blank = unlimited"
                                />
                            </div>
                            <div class="admin-field">
                                <label class="admin-field__label" for="renew-expiry">Next Expiry Date</label>
                                <input
                                    id="renew-expiry"
                                    v-model="renewForm.expires_at"
                                    class="admin-input"
                                    type="date"
                                />
                                <p class="admin-field__help">Suggested: 30 days from today (editable).</p>
                            </div>
                        </div>
                        <div class="admin-modal__footer">
                            <BaseButton variant="secondary" size="sm" @click="closeRenew">Cancel</BaseButton>
                            <BaseButton variant="primary" size="sm" :disabled="renewing || !renewForm.expires_at" @click="submitRenew">
                                {{ renewing ? "Renewing…" : "Renew" }}
                            </BaseButton>
                        </div>
                    </div>
                </div>
            </Transition>
        </Teleport>

        <!-- Add/Edit Company Modal -->
        <Teleport to="body">
            <Transition name="admin-modal">
                <div
                    v-if="showForm"
                    class="admin-modalOverlay"
                    @click="closeForm"
                >
                    <div class="admin-modal" @click.stop>
                        <div class="admin-modal__header">
                            <h2 class="admin-modal__title">
                                {{
                                    isEditing
                                        ? "Edit Company"
                                        : "Add New Company"
                                }}
                            </h2>
                            <button
                                type="button"
                                class="admin-modal__close"
                                @click="closeForm"
                            >
                                ✕
                            </button>
                        </div>

                        <div
                            class="admin-modal__body"
                            style="max-height: 60vh; overflow-y: auto"
                        >
                            <div
                                v-if="validationErrors.general"
                                class="admin-alert admin-alert--error"
                            >
                                <div
                                    v-for="msg in validationErrors.general"
                                    :key="msg"
                                >
                                    {{ msg }}
                                </div>
                            </div>

                            <div class="admin-field">
                                <label
                                    class="admin-field__label"
                                    for="company-name"
                                >
                                    Company Name*
                                </label>
                                <input
                                    id="company-name"
                                    v-model="formData.name"
                                    class="admin-input"
                                    type="text"
                                    placeholder="Enter company name"
                                />
                                <span
                                    v-if="validationErrors.name"
                                    class="admin-field__error"
                                >
                                    {{ validationErrors.name[0] }}
                                </span>
                            </div>

                            <div class="admin-field">
                                <label
                                    class="admin-field__label"
                                    for="company-timezone"
                                >
                                    Timezone
                                </label>
                                <select
                                    id="company-timezone"
                                    v-model="formData.timezone"
                                    class="admin-input admin-input--select"
                                >
                                    <option value="UTC">
                                        UTC (Coordinated Universal Time)
                                    </option>
                                    <option value="America/New_York">
                                        America/New_York (EST/EDT)
                                    </option>
                                    <option value="America/Chicago">
                                        America/Chicago (CST/CDT)
                                    </option>
                                    <option value="America/Denver">
                                        America/Denver (MST/MDT)
                                    </option>
                                    <option value="America/Los_Angeles">
                                        America/Los_Angeles (PST/PDT)
                                    </option>
                                    <option value="America/Phoenix">
                                        America/Phoenix (MST)
                                    </option>
                                    <option value="America/Toronto">
                                        America/Toronto
                                    </option>
                                    <option value="America/Vancouver">
                                        America/Vancouver
                                    </option>
                                    <option value="Europe/London">
                                        Europe/London (GMT/BST)
                                    </option>
                                    <option value="Europe/Paris">
                                        Europe/Paris (CET/CEST)
                                    </option>
                                    <option value="Europe/Berlin">
                                        Europe/Berlin (CET/CEST)
                                    </option>
                                    <option value="Asia/Dubai">
                                        Asia/Dubai (GST)
                                    </option>
                                    <option value="Asia/Kolkata">
                                        Asia/Kolkata (IST)
                                    </option>
                                    <option value="Asia/Singapore">
                                        Asia/Singapore (SGT)
                                    </option>
                                    <option value="Asia/Tokyo">
                                        Asia/Tokyo (JST)
                                    </option>
                                    <option value="Australia/Sydney">
                                        Australia/Sydney (AEDT/AEST)
                                    </option>
                                    <option value="Pacific/Auckland">
                                        Pacific/Auckland (NZDT/NZST)
                                    </option>
                                </select>
                            </div>

                            <div class="admin-field">
                                <label
                                    class="admin-field__label"
                                    for="company-pbx-provider"
                                >
                                    PBX Provider
                                </label>
                                <select
                                    id="company-pbx-provider"
                                    v-model="formData.pbx_provider_id"
                                    class="admin-input admin-input--select"
                                >
                                    <option value="">
                                        — Select Provider —
                                    </option>
                                    <option
                                        v-for="provider in pbxProviders"
                                        :key="provider.id"
                                        :value="provider.id"
                                    >
                                        {{ provider.name }}
                                    </option>
                                </select>
                            </div>

                            <div
                                v-if="formData.pbx_provider_id"
                                class="admin-field"
                            >
                                <label
                                    class="admin-field__label"
                                    for="company-server"
                                >
                                    PBXware Server ID
                                </label>
                                <input
                                    id="company-server"
                                    v-model="formData.server_id"
                                    class="admin-input"
                                    type="text"
                                    placeholder="e.g., 3, 83, 23"
                                />
                                <p class="admin-field__help">
                                    Enter the Server ID from PBXware. This is
                                    automatically populated when you sync
                                    tenants.
                                </p>
                                <span
                                    v-if="validationErrors.server_id"
                                    class="admin-field__error"
                                >
                                    {{ validationErrors.server_id[0] }}
                                </span>
                            </div>

                            <div class="admin-field">
                                <label
                                    class="admin-field__label"
                                    for="company-tenant-code"
                                >
                                    Tenant Code
                                </label>
                                <input
                                    id="company-tenant-code"
                                    v-model="formData.tenant_code"
                                    class="admin-input"
                                    type="text"
                                    placeholder="e.g., 501"
                                />
                                <span
                                    v-if="validationErrors.tenant_code"
                                    class="admin-field__error"
                                >
                                    {{ validationErrors.tenant_code[0] }}
                                </span>
                            </div>

                            <!-- Monthly Call Limit -->
                            <div class="admin-field">
                                <label class="admin-field__label" for="company-call-limit">
                                    Monthly Call Limit
                                </label>
                                <input
                                    id="company-call-limit"
                                    v-model="formData.monthly_call_limit"
                                    class="admin-input"
                                    type="number"
                                    min="0"
                                    placeholder="e.g., 10000 (blank = unlimited)"
                                />
                                <p class="admin-field__help">
                                    Maximum analysed calls fetched per period. Leave blank for unlimited.
                                </p>
                            </div>

                            <!-- Limit Expiry Date -->
                            <div class="admin-field">
                                <label class="admin-field__label" for="company-limit-expiry">
                                    Limit Expiry Date
                                </label>
                                <input
                                    id="company-limit-expiry"
                                    v-model="formData.call_limit_expires_at"
                                    class="admin-input"
                                    type="date"
                                />
                                <p class="admin-field__help">
                                    On this date the limit period ends. Use Renew to reset usage and set the next date.
                                </p>
                            </div>

                            <!-- Assign User -->
                            <div class="admin-field">
                                <label class="admin-field__label" for="company-assign-user">
                                    Assign to User
                                </label>
                                <select
                                    id="company-assign-user"
                                    v-model="formData.user_id"
                                    class="admin-input admin-input--select"
                                >
                                    <option value="">— No user assigned —</option>
                                    <option
                                        v-for="u in registeredUsers"
                                        :key="u.id"
                                        :value="u.id"
                                    >
                                        {{ u.name }} ({{ u.email }})
                                    </option>
                                </select>
                                <p class="admin-field__help">
                                    Link this company to a registered SaaS user so they see only this company's calls and reports.
                                </p>
                            </div>

                            <div class="admin-field">
                                <label
                                    class="admin-field__label"
                                    for="company-status"
                                >
                                    Status
                                </label>
                                <div class="admin-toggle-wrapper">
                                    <label class="admin-toggle">
                                        <input
                                            id="company-status"
                                            type="checkbox"
                                            :checked="
                                                formData.status === 'active'
                                            "
                                            @change="
                                                formData.status =
                                                    formData.status === 'active'
                                                        ? 'inactive'
                                                        : 'active'
                                            "
                                        />
                                        <span
                                            class="admin-toggle__slider"
                                        ></span>
                                        <span class="admin-toggle__label">
                                            {{
                                                formData.status === "active"
                                                    ? "Active"
                                                    : "Inactive"
                                            }}
                                        </span>
                                    </label>
                                    <p
                                        class="admin-field__help"
                                        style="margin-top: 0.5rem"
                                    >
                                        Only active companies will process PBX
                                        call records.
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div class="admin-modal__footer">
                            <BaseButton variant="secondary" @click="closeForm">
                                Cancel
                            </BaseButton>
                            <BaseButton
                                variant="primary"
                                @click="submitForm"
                                :loading="submitting"
                            >
                                {{ isEditing ? "Update" : "Create" }}
                            </BaseButton>
                        </div>
                    </div>
                </div>
            </Transition>
        </Teleport>

        <!-- Sync Tenants Modal -->
        <Teleport to="body">
            <Transition name="admin-modal">
                <div
                    v-if="showSyncModal"
                    class="admin-modalOverlay"
                    @click="closeSyncModal"
                >
                    <div class="admin-modal" @click.stop>
                        <div class="admin-modal__header">
                            <h2 class="admin-modal__title">
                                Sync PBXware Tenants
                            </h2>
                            <button
                                type="button"
                                class="admin-modal__close"
                                @click="closeSyncModal"
                            >
                                ✕
                            </button>
                        </div>

                        <div
                            class="admin-modal__body"
                            style="max-height: 60vh; overflow-y: auto"
                        >
                            <div
                                v-if="syncError"
                                class="admin-alert admin-alert--error"
                            >
                                {{ syncError }}
                            </div>

                            <div v-if="syncing" class="admin-loadingState">
                                <p>Fetching tenants from PBXware...</p>
                            </div>

                            <div v-else-if="syncResult">
                                <div
                                    v-if="syncResult.new_count > 0"
                                    class="admin-alert admin-alert--success"
                                >
                                    Found
                                    <strong>{{ syncResult.new_count }}</strong>
                                    new tenant(s)
                                </div>
                                <div
                                    v-if="syncResult.existing_count > 0"
                                    class="admin-alert admin-alert--info"
                                >
                                    Updated
                                    <strong>{{
                                        syncResult.existing_count
                                    }}</strong>
                                    existing tenant(s)
                                </div>

                                <div
                                    v-if="
                                        syncResult.new_tenants &&
                                        syncResult.new_tenants.length > 0
                                    "
                                    style="margin-top: 16px"
                                >
                                    <h3
                                        style="
                                            font-size: 14px;
                                            font-weight: 600;
                                        "
                                    >
                                        New Tenants
                                    </h3>
                                    <ul
                                        style="
                                            list-style: none;
                                            padding: 0;
                                            margin-top: 8px;
                                        "
                                    >
                                        <li
                                            v-for="tenant in syncResult.new_tenants"
                                            :key="tenant.server_id"
                                            style="
                                                padding: 8px;
                                                border: 1px solid
                                                    var(--admin-border);
                                                border-radius: 6px;
                                                margin-bottom: 8px;
                                                font-size: 13px;
                                            "
                                        >
                                            <strong>{{ tenant.name }}</strong>
                                            ({{ tenant.tenant_code }}) -
                                            {{ tenant.package }}
                                        </li>
                                    </ul>
                                </div>
                            </div>

                            <div v-else class="admin-field">
                                <label
                                    class="admin-field__label"
                                    for="sync-pbx-provider"
                                >
                                    Select PBX Provider
                                </label>
                                <select
                                    id="sync-pbx-provider"
                                    v-model="syncFormData.pbx_provider_id"
                                    class="admin-input admin-input--select"
                                >
                                    <option value="">
                                        — Choose Provider —
                                    </option>
                                    <option
                                        v-for="provider in pbxProviders"
                                        :key="provider.id"
                                        :value="provider.id"
                                    >
                                        {{ provider.name }}
                                    </option>
                                </select>
                            </div>
                        </div>

                        <div class="admin-modal__footer">
                            <BaseButton
                                v-if="!syncResult"
                                variant="secondary"
                                @click="closeSyncModal"
                            >
                                Cancel
                            </BaseButton>
                            <BaseButton
                                v-if="!syncResult"
                                variant="primary"
                                @click="performSync"
                                :loading="syncing"
                                :disabled="
                                    !syncFormData.pbx_provider_id || syncing
                                "
                            >
                                Sync Now
                            </BaseButton>
                            <BaseButton
                                v-else
                                variant="primary"
                                @click="closeSyncModal"
                            >
                                Done
                            </BaseButton>
                        </div>
                    </div>
                </div>
            </Transition>
        </Teleport>

        <!-- Delete Confirmation Modal -->
        <Teleport to="body">
            <Transition name="admin-modal">
                <div
                    v-if="showDeleteConfirm"
                    class="admin-modalOverlay"
                    @click="closeDeleteConfirm"
                >
                    <div class="admin-modal" @click.stop>
                        <div class="admin-modal__header">
                            <h2 class="admin-modal__title">
                                {{
                                    deleteMode === "permanent"
                                        ? "Delete Company Permanently"
                                        : "Delete Company"
                                }}
                            </h2>
                            <button
                                type="button"
                                class="admin-modal__close"
                                @click="closeDeleteConfirm"
                            >
                                ✕
                            </button>
                        </div>

                        <div class="admin-modal__body">
                            <p v-if="deleteMode === 'soft'">
                                Are you sure you want to delete
                                <strong>{{ deleteTarget?.name }}</strong>
                                ? This will move the company to deleted state.
                            </p>
                            <div v-else>
                                <p>
                                    You are about to permanently delete
                                    <strong>{{ deleteTarget?.name }}</strong>
                                    . This action cannot be undone.
                                </p>
                                <p style="margin-top: 10px">
                                    This will permanently remove all company
                                    data including calls, transcriptions, weekly
                                    reports/files, PBX account mappings,
                                    categories and sub-categories.
                                </p>
                                <p style="margin-top: 10px; font-weight: 600">
                                    Do you want to continue?
                                </p>
                            </div>
                        </div>

                        <div class="admin-modal__footer">
                            <BaseButton
                                variant="secondary"
                                @click="closeDeleteConfirm"
                            >
                                Cancel
                            </BaseButton>
                            <BaseButton
                                variant="danger"
                                @click="confirmDelete"
                                :loading="deleting"
                            >
                                {{
                                    deleteMode === "permanent"
                                        ? "Yes, Delete Permanently"
                                        : "Delete"
                                }}
                            </BaseButton>
                        </div>
                    </div>
                </div>
            </Transition>
        </Teleport>
    </div>
</template>

<script setup>
import { onMounted, ref, reactive, watch } from "vue";
import adminApi from "../../router/admin/api";
import { BaseButton, BasePagination } from "../../components/admin/base";
import { showAdminToast } from "../../admin/toast";

// Pagination and search state
const search = ref("");
const serverFilter = ref("");
const page = ref(1);
const pageSize = ref(25);
const sortBy = ref("name");
const sortDirection = ref("asc");
const rows = ref([]);
const meta = ref({
    currentPage: 1,
    perPage: 25,
    total: 0,
    lastPage: 1,
});

const loading = ref(true);
const error = ref("");
const pbxProviders = ref([]);

// Form state
const showForm = ref(false);
const isEditing = ref(false);
const submitting = ref(false);
const validationErrors = ref({});
const currentAvailableTenants = ref([]);

const formData = reactive({
    id: null,
    name: "",
    timezone: "UTC",
    status: "active",
    pbx_provider_id: "",
    server_id: "",
    tenant_code: "",
    user_id: "",
    monthly_call_limit: "",
    call_limit_expires_at: "",
});

const defaultFormData = {
    id: null,
    name: "",
    timezone: "UTC",
    status: "active",
    pbx_provider_id: "",
    server_id: "",
    tenant_code: "",
    user_id: "",
    monthly_call_limit: "",
    call_limit_expires_at: "",
};

const registeredUsers = ref([]);

// Sync modal state
const showSyncModal = ref(false);
const syncing = ref(false);
const syncError = ref("");
const syncResult = ref(null);
const syncFormData = reactive({ pbx_provider_id: "" });

// Delete confirmation state
const showDeleteConfirm = ref(false);
const deleteTarget = ref(null);
const deleting = ref(false);
const deleteMode = ref("soft");

// Renew call-limit state
const showRenew = ref(false);
const renewTarget = ref(null);
const renewing = ref(false);
const renewError = ref("");
const renewForm = reactive({ monthly_call_limit: "", expires_at: "" });

async function openRenew(company) {
    renewTarget.value = company;
    renewError.value = "";
    renewForm.monthly_call_limit = company.monthly_call_limit ?? "";
    renewForm.expires_at = "";
    showRenew.value = true;
    try {
        const res = await adminApi.get(`/companies/${company.id}/renew-suggestion`);
        renewForm.expires_at = res?.data?.data?.suggested_expires_at ?? "";
    } catch {
        // fallback handled by user
    }
}

function closeRenew() {
    showRenew.value = false;
    renewTarget.value = null;
    renewError.value = "";
}

async function submitRenew() {
    if (!renewTarget.value || !renewForm.expires_at) return;
    renewing.value = true;
    renewError.value = "";
    try {
        await adminApi.post(`/companies/${renewTarget.value.id}/renew-limit`, {
            expires_at: renewForm.expires_at,
            monthly_call_limit: renewForm.monthly_call_limit === "" || renewForm.monthly_call_limit === null
                ? null
                : Number(renewForm.monthly_call_limit),
        });
        showToast("Call limit renewed.");
        closeRenew();
        await fetchCompanies();
    } catch (e) {
        renewError.value = e?.response?.data?.message || "Failed to renew limit.";
    } finally {
        renewing.value = false;
    }
}

async function fetchCompanies() {
    loading.value = true;
    error.value = "";
    try {
        const params = {
            page: page.value,
            per_page: pageSize.value,
            search: search.value || undefined,
            pbx_provider_id: serverFilter.value || undefined,
            sort: sortBy.value,
            direction: sortDirection.value,
            include_deleted: true,
        };

        const res = await adminApi.get("/companies", { params });
        const payload = res?.data;
        rows.value = Array.isArray(payload?.data) ? payload.data : [];
        meta.value = payload?.meta ?? meta.value;
    } catch (e) {
        rows.value = [];
        error.value = e?.response?.data?.message || "Failed to load companies.";
    } finally {
        loading.value = false;
    }
}

function sortGlyph(key) {
    if (sortBy.value !== key) return "";
    return sortDirection.value === "asc" ? "▲" : "▼";
}

function toggleSort(key) {
    if (sortBy.value === key) {
        sortDirection.value = sortDirection.value === "asc" ? "desc" : "asc";
    } else {
        sortBy.value = key;
        sortDirection.value = "asc";
    }
    page.value = 1;
    fetchCompanies();
}

function refresh() {
    fetchCompanies();
}

function onServerFilterChange() {
    page.value = 1;
    fetchCompanies();
}

async function loadPbxProviders() {
    try {
        const res = await adminApi.get("/pbx-providers");
        pbxProviders.value = res?.data?.data || [];
    } catch (e) {
        console.error("Failed to load PBX providers", e);
    }
}

async function loadAvailableTenants(providerId) {
    if (!providerId) {
        currentAvailableTenants.value = [];
        return;
    }
    try {
        const res = await adminApi.get("/companies/available-tenants", {
            params: { pbx_provider_id: providerId },
        });
        currentAvailableTenants.value = res?.data?.data || [];
    } catch (e) {
        currentAvailableTenants.value = [];
    }
}

watch(
    () => formData.server_id,
    (serverId) => {
        if (!serverId) return;
        const match = currentAvailableTenants.value.find(
            (tenant) => tenant.server_id === serverId,
        );
        if (match && match.tenant_code) {
            formData.tenant_code = match.tenant_code;
        }
    },
);

// Watch search and reset pagination
let searchTimeout;
watch(
    () => search.value,
    () => {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => {
            page.value = 1;
            fetchCompanies();
        }, 300);
    },
);

async function loadRegisteredUsers() {
    if (registeredUsers.value.length) return;
    try {
        const res = await adminApi.get("/users", { params: { per_page: 200 } });
        registeredUsers.value = res.data.data ?? [];
    } catch {
        // non-fatal
    }
}

function openAddForm() {
    isEditing.value = false;
    Object.assign(formData, defaultFormData);
    validationErrors.value = {};
    currentAvailableTenants.value = [];
    loadRegisteredUsers();
    showForm.value = true;
}

function openEditForm(company) {
    isEditing.value = true;
    formData.id = company.id;
    formData.name = company.name;
    formData.timezone = company.timezone || "UTC";
    formData.status = company.status;
    formData.pbx_provider_id = company.pbx_provider_id || "";
    formData.server_id = company.server_id || "";
    formData.tenant_code = company.tenant_code || "";
    formData.user_id = "";
    formData.monthly_call_limit = company.monthly_call_limit ?? "";
    formData.call_limit_expires_at = company.call_limit_expires_at || "";
    validationErrors.value = {};
    loadAvailableTenants(formData.pbx_provider_id);
    loadRegisteredUsers();
    showForm.value = true;
}

function closeForm() {
    showForm.value = false;
    Object.assign(formData, defaultFormData);
    validationErrors.value = {};
    currentAvailableTenants.value = [];
}

async function submitForm() {
    validationErrors.value = {};
    submitting.value = true;

    try {
        const data = {
            name: formData.name,
            timezone: formData.timezone,
            status: formData.status,
            pbx_provider_id: formData.pbx_provider_id || null,
            server_id: formData.server_id || null,
            tenant_code: formData.tenant_code || null,
            monthly_call_limit: formData.monthly_call_limit === "" || formData.monthly_call_limit === null
                ? null
                : Number(formData.monthly_call_limit),
            call_limit_expires_at: formData.call_limit_expires_at || null,
        };

        let companyId;
        if (isEditing.value && formData.id) {
            await adminApi.put(`/companies/${formData.id}`, data);
            companyId = formData.id;
        } else {
            const res = await adminApi.post("/companies", data);
            companyId = res?.data?.data?.id;
        }

        // Assign user to company if selected
        if (companyId && formData.user_id) {
            try {
                await adminApi.post(`/companies/${companyId}/assign-user`, {
                    user_id: formData.user_id,
                });
            } catch {
                // Non-fatal: company saved, user assignment failed silently
            }
        }

        showToast(
            isEditing.value
                ? "Company updated successfully."
                : "Company created successfully.",
        );
        await fetchCompanies();
        closeForm();
    } catch (err) {
        if (err.response?.data?.errors) {
            validationErrors.value = err.response.data.errors;
        } else if (err.response?.data?.message) {
            validationErrors.value.general = [err.response.data.message];
        } else {
            error.value = err.message || "Failed to save company";
        }
    } finally {
        submitting.value = false;
    }
}

function openSyncModal() {
    showSyncModal.value = true;
    syncError.value = "";
    syncResult.value = null;
    syncFormData.pbx_provider_id = "";
}

function closeSyncModal() {
    showSyncModal.value = false;
    syncError.value = "";
    syncResult.value = null;
}

async function performSync() {
    if (!syncFormData.pbx_provider_id) return;

    syncing.value = true;
    syncError.value = "";

    try {
        const res = await adminApi.post("/companies/sync-tenants", {
            pbx_provider_id: syncFormData.pbx_provider_id,
        });
        syncResult.value = res?.data || {};
        showToast("Tenants synced successfully!");
        await fetchCompanies();
    } catch (err) {
        syncError.value =
            err?.response?.data?.message || "Failed to sync tenants";
    } finally {
        syncing.value = false;
    }
}

function openDeleteConfirm(company) {
    deleteTarget.value = company;
    deleteMode.value = "soft";
    showDeleteConfirm.value = true;
}

function openPermanentDeleteConfirm(company) {
    deleteTarget.value = company;
    deleteMode.value = "permanent";
    showDeleteConfirm.value = true;
}

function closeDeleteConfirm() {
    showDeleteConfirm.value = false;
    deleteTarget.value = null;
    deleteMode.value = "soft";
}

async function confirmDelete() {
    if (!deleteTarget.value || deleting.value) return;

    const targetId = deleteTarget.value.id;
    const isPermanentDelete = deleteMode.value === "permanent";

    deleting.value = true;
    try {
        if (isPermanentDelete) {
            await adminApi.delete(`/companies/${targetId}/force-delete`);
            showToast("Company permanently deleted successfully.");
        } else {
            await adminApi.delete(`/companies/${targetId}`);
            showToast("Company deleted successfully.");
        }

        closeDeleteConfirm();

        try {
            await fetchCompanies();
        } catch {
            // Keep success state even if refresh fails.
        }
    } catch (err) {
        showToast(
            err?.response?.data?.message || "Failed to delete company",
            "error",
        );
    } finally {
        deleting.value = false;
    }
}

function showToast(message, type = "success") {
    showAdminToast(message, type);
}

onMounted(async () => {
    await loadPbxProviders();
    await fetchCompanies();
});
</script>
