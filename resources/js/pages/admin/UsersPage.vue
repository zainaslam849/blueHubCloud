<template>
    <div class="admin-container admin-page">

        <!-- Page Header -->
        <header class="admin-page__header uHeader">
            <div class="uHeader__left">
                <div class="uHeader__icon">
                    <svg viewBox="0 0 24 24" fill="none">
                        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        <circle cx="9" cy="7" r="4" stroke="currentColor" stroke-width="2"/>
                        <path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                </div>
                <div>
                    <p class="admin-page__kicker">SaaS</p>
                    <h1 class="admin-page__title">Users</h1>
                    <p class="admin-page__subtitle">Manage users and their call analysis limits.</p>
                </div>
            </div>
            <div class="uHeader__right">
                <div class="uHeader__stat">
                    <div class="uHeader__statVal">{{ meta.total }}</div>
                    <div class="uHeader__statLabel">Total Users</div>
                </div>
                <button class="uAddBtn" @click="openAddUser">
                    <svg viewBox="0 0 20 20" fill="none">
                        <path d="M10 4v12M4 10h12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                    Add User
                </button>
            </div>
        </header>

        <!-- Toolbar -->
        <section class="admin-card admin-card--glass">
            <div class="uToolbar">
                <div class="admin-field uToolbar__search">
                    <label class="admin-field__label" for="users-search">Search</label>
                    <div class="uSearch">
                        <svg class="uSearch__icon" viewBox="0 0 20 20" fill="none">
                            <path d="M17.5 17.5l-4.167-4.167m1.25-3.75a5 5 0 1 1-10 0 5 5 0 0 1 10 0z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                        </svg>
                        <input
                            id="users-search"
                            v-model="search"
                            class="admin-input uSearch__input"
                            type="search"
                            placeholder="Search by name or email…"
                            autocomplete="off"
                        />
                    </div>
                </div>
                <button class="uRefreshBtn" :class="{ 'uRefreshBtn--spinning': loading }" @click="fetchUsers()" title="Refresh">
                    <svg viewBox="0 0 20 20" fill="none">
                        <path d="M4 10a6 6 0 1 0 1.5-4M4 4v3h3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <span>Refresh</span>
                </button>
            </div>

            <div v-if="error" class="admin-alert admin-alert--error">{{ error }}</div>

            <!-- Skeleton rows -->
            <div v-if="loading" class="uTable">
                <div class="uTable__head">
                    <div class="uTable__hCell">User</div>
                    <div class="uTable__hCell uHide--sm">Company</div>
                    <div class="uTable__hCell uHide--md">Registered</div>
                    <div class="uTable__hCell">Actions</div>
                </div>
                <div v-for="i in 4" :key="i" class="uTable__row uTable__row--sk">
                    <div class="uCell--user">
                        <div class="uSk uSk--avatar"></div>
                        <div style="display:flex;flex-direction:column;gap:5px">
                            <div class="uSk uSk--name"></div>
                            <div class="uSk uSk--email"></div>
                        </div>
                    </div>
                    <div class="uCell uHide--sm"><div class="uSk uSk--badge"></div></div>
                    <div class="uCell uHide--md"><div class="uSk uSk--date"></div></div>
                    <div class="uCell"><div class="uSk uSk--actions"></div></div>
                </div>
            </div>

            <!-- Empty state -->
            <div v-else-if="filteredRows.length === 0" class="uEmpty">
                <div class="uEmpty__icon">
                    <svg viewBox="0 0 48 48" fill="none">
                        <circle cx="24" cy="24" r="22" stroke="currentColor" stroke-width="2"/>
                        <circle cx="24" cy="18" r="7" stroke="currentColor" stroke-width="2"/>
                        <path d="M8 42a16 16 0 0 1 32 0" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                </div>
                <p class="uEmpty__title">No users found</p>
                <p class="uEmpty__sub">{{ search ? 'No results for "' + search + '"' : 'Add users with the button above.' }}</p>
            </div>

            <!-- Data table -->
            <div v-else class="uTable">
                <div class="uTable__head">
                    <div class="uTable__hCell">User</div>
                    <div class="uTable__hCell uHide--sm">Company</div>
                    <div class="uTable__hCell uHide--md">Registered</div>
                    <div class="uTable__hCell uHide--sm">Status</div>
                    <div class="uTable__hCell">Actions</div>
                </div>

                <div v-for="user in filteredRows" :key="user.id" class="uTable__row">
                    <!-- User cell -->
                    <div class="uCell--user">
                        <div class="uAvatar" :style="{ background: avatarColor(user.name) }">
                            {{ initials(user.name) }}
                        </div>
                        <div class="uUserInfo">
                            <span class="uUserInfo__name">{{ user.name }}</span>
                            <span class="uUserInfo__email">{{ user.email }}</span>
                            <div class="uHide--sm-up" style="margin-top:4px">
                                <CompanyBadge :company="user.company" />
                            </div>
                        </div>
                    </div>

                    <!-- Company -->
                    <div class="uCell uHide--sm">
                        <CompanyBadge :company="user.company" />
                    </div>

                    <!-- Registered -->
                    <div class="uCell uHide--md">
                        <span class="uDate">{{ formatDate(user.created_at) }}</span>
                    </div>

                    <!-- Status -->
                    <div class="uCell uHide--sm">
                        <StatusBadge :status="user.account_status" />
                    </div>

                    <!-- Actions -->
                    <div class="uCell uCell--actions">
                        <RouterLink :to="{ name: 'admin.users.detail', params: { id: user.id } }" class="uActionBtn uActionBtn--view" title="View profile">
                            <svg viewBox="0 0 20 20" fill="none">
                                <path d="M10 4a6 6 0 1 0 0 12A6 6 0 0 0 10 4ZM2 10a8 8 0 1 1 16 0 8 8 0 0 1-16 0Z" fill="currentColor" opacity=".15"/>
                                <path d="M10 4a6 6 0 1 0 0 12A6 6 0 0 0 10 4Z" stroke="currentColor" stroke-width="1.4"/>
                                <circle cx="10" cy="8.5" r="1.5" fill="currentColor"/>
                                <path d="M8 13c0-1.1.9-2 2-2s2 .9 2 2" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
                            </svg>
                            <span>View</span>
                        </RouterLink>
                        <button class="uActionBtn uActionBtn--company" @click="openAssignCompany(user)" title="Assign Company">
                            <svg viewBox="0 0 20 20" fill="none">
                                <path d="M3 17V5a2 2 0 0 1 2-2h4v14" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>
                                <path d="M9 17V7a2 2 0 0 1 2-2h6v12" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>
                            </svg>
                            <span>Company</span>
                        </button>
                        <button
                            class="uActionBtn uActionBtn--delete"
                            title="Delete user"
                            @click="confirmDelete(user)"
                        >
                            <svg viewBox="0 0 20 20" fill="none">
                                <path d="M3 5h14M8 5V3h4v2M6 5l1 11h6l1-11" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                            <span>Delete</span>
                        </button>
                        <button
                            class="uActionBtn"
                            :class="user.account_status === 'suspended' ? 'uActionBtn--activate' : 'uActionBtn--block'"
                            :title="user.account_status === 'suspended' ? 'Reactivate account' : 'Suspend account'"
                            :disabled="togglingId === user.id"
                            @click="toggleStatus(user)"
                        >
                            <svg v-if="user.account_status === 'suspended'" viewBox="0 0 20 20" fill="none">
                                <circle cx="10" cy="10" r="8" stroke="currentColor" stroke-width="1.5"/>
                                <path d="M7 10l2 2 4-4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                            <svg v-else viewBox="0 0 20 20" fill="none">
                                <circle cx="10" cy="10" r="8" stroke="currentColor" stroke-width="1.5"/>
                                <path d="M7 7l6 6M13 7l-6 6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                            </svg>
                            <span>{{ user.account_status === 'suspended' ? 'Unblock' : 'Block' }}</span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Pagination -->
            <div v-if="!loading && meta.lastPage > 1" class="uPager">
                <button class="uPagerBtn" :disabled="meta.currentPage <= 1" @click="goToPage(meta.currentPage - 1)">
                    <svg viewBox="0 0 16 16" fill="currentColor"><path d="M10 3L5 8l5 5"/></svg>
                    Prev
                </button>
                <span class="uPager__info">{{ meta.currentPage }} / {{ meta.lastPage }} <span class="uPager__total">({{ meta.total }} users)</span></span>
                <button class="uPagerBtn" :disabled="meta.currentPage >= meta.lastPage" @click="goToPage(meta.currentPage + 1)">
                    Next
                    <svg viewBox="0 0 16 16" fill="currentColor"><path d="M6 3l5 5-5 5"/></svg>
                </button>
            </div>
        </section>

        <!-- ── Add User Modal ─────────────────────────────────────────────────── -->
        <Teleport to="body">
            <Transition name="admin-modal">
                <div v-if="showAddUser" class="admin-modalOverlay" @click="closeAddUser">
                    <div class="admin-modal uModal" @click.stop>
                        <div class="admin-modal__header uModal__header">
                            <div class="uModal__titleWrap">
                                <div class="uModal__iconWrap uModal__iconWrap--blue">
                                    <svg viewBox="0 0 20 20" fill="none">
                                        <circle cx="10" cy="7" r="4" stroke="currentColor" stroke-width="1.5"/>
                                        <path d="M2 18a8 8 0 0 1 16 0" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                                        <path d="M15 3v4M13 5h4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                                    </svg>
                                </div>
                                <div>
                                    <h2 class="admin-modal__title">Add User</h2>
                                    <p class="uModal__sub">Create a new SaaS user account</p>
                                </div>
                            </div>
                            <button class="admin-modal__close uModal__close" @click="closeAddUser">✕</button>
                        </div>
                        <div class="admin-modal__body">
                            <div v-if="addError" class="admin-alert admin-alert--error">{{ addError }}</div>

                            <div class="uFormGrid">
                                <div class="admin-field">
                                    <label class="admin-field__label">Full Name <span class="uRequired">*</span></label>
                                    <input v-model="addForm.name" class="admin-input uFormInput" type="text" placeholder="Jane Smith" autocomplete="off" />
                                    <span v-if="addFieldErrors.name" class="uFieldErr">{{ addFieldErrors.name }}</span>
                                </div>
                                <div class="admin-field">
                                    <label class="admin-field__label">Email <span class="uRequired">*</span></label>
                                    <input v-model="addForm.email" class="admin-input uFormInput" type="email" placeholder="jane@company.com" autocomplete="off" />
                                    <span v-if="addFieldErrors.email" class="uFieldErr">{{ addFieldErrors.email }}</span>
                                </div>
                                <div class="admin-field">
                                    <label class="admin-field__label">Password <span class="uRequired">*</span></label>
                                    <div class="uPassWrap">
                                        <input
                                            v-model="addForm.password"
                                            class="admin-input uFormInput uPassWrap__input"
                                            :type="showAddPassword ? 'text' : 'password'"
                                            placeholder="Min. 8 characters"
                                            autocomplete="new-password"
                                        />
                                        <button type="button" class="uPassWrap__toggle" @click="showAddPassword = !showAddPassword" tabindex="-1">
                                            {{ showAddPassword ? 'Hide' : 'Show' }}
                                        </button>
                                    </div>
                                    <span v-if="addFieldErrors.password" class="uFieldErr">{{ addFieldErrors.password }}</span>
                                </div>
                                <div class="admin-field uFormGrid__full">
                                    <label class="admin-field__label">Assign Company</label>
                                    <div v-if="loadingCompanies" class="uDropLoader">
                                        <div class="uDropLoader__spinner"></div>
                                        <span>Loading companies…</span>
                                    </div>
                                    <select v-else v-model="addForm.company_id" class="admin-input uFormInput admin-input--select">
                                        <option value="">— None —</option>
                                        <option v-for="c in companies" :key="c.id" :value="c.id">
                                            {{ c.name }}{{ c.server_id ? ' · Server ' + c.server_id : '' }}
                                        </option>
                                    </select>
                                    <span class="uFieldHint">The company's monthly call limit is set on the Companies page.</span>
                                </div>
                            </div>
                            <div class="uEmailNote">
                                <svg viewBox="0 0 20 20" fill="none"><rect x="2" y="5" width="16" height="12" rx="2" stroke="currentColor" stroke-width="1.4"/><path d="M2 8l8 5 8-5" stroke="currentColor" stroke-width="1.4"/></svg>
                                A welcome email with login credentials will be sent to the user's email address.
                            </div>
                        </div>
                        <div class="admin-modal__footer uModal__footer">
                            <button class="uBtn uBtn--ghost" @click="closeAddUser">Cancel</button>
                            <button class="uBtn uBtn--primary" :disabled="adding" @click="submitAddUser">
                                <span v-if="adding" class="uBtn__spin"></span>
                                Create User
                            </button>
                        </div>
                    </div>
                </div>
            </Transition>
        </Teleport>

        <!-- ── Delete User Modal ──────────────────────────────────────────────── -->
        <Teleport to="body">
            <Transition name="admin-modal">
                <div v-if="showDeleteConfirm" class="admin-modalOverlay" @click="closeDeleteConfirm">
                    <div class="admin-modal uModal uModal--sm" @click.stop>
                        <div class="admin-modal__header uModal__header">
                            <div class="uModal__titleWrap">
                                <div class="uModal__iconWrap uModal__iconWrap--red">
                                    <svg viewBox="0 0 20 20" fill="none">
                                        <path d="M3 5h14M8 5V3h4v2M6 5l1 11h6l1-11" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                                    </svg>
                                </div>
                                <div>
                                    <h2 class="admin-modal__title">Delete User</h2>
                                    <p class="uModal__sub">This action cannot be undone</p>
                                </div>
                            </div>
                            <button class="admin-modal__close uModal__close" @click="closeDeleteConfirm">✕</button>
                        </div>
                        <div class="admin-modal__body">
                            <div class="uDeleteInfo">
                                <div class="uDeleteInfo__avatar" :style="{ background: avatarColor(deleteTarget?.name) }">
                                    {{ initials(deleteTarget?.name) }}
                                </div>
                                <div>
                                    <div class="uDeleteInfo__name">{{ deleteTarget?.name }}</div>
                                    <div class="uDeleteInfo__email">{{ deleteTarget?.email }}</div>
                                </div>
                            </div>
                            <div class="uDeleteWarn">
                                <svg viewBox="0 0 20 20" fill="currentColor">
                                    <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                                </svg>
                                <div>
                                    <strong>Only this user account will be deleted.</strong>
                                    <p>Their company, calls, reports, and all other records will remain intact.</p>
                                </div>
                            </div>
                        </div>
                        <div class="admin-modal__footer uModal__footer">
                            <button class="uBtn uBtn--ghost" @click="closeDeleteConfirm">Cancel</button>
                            <button class="uBtn uBtn--danger" :disabled="deleting" @click="submitDelete">
                                <span v-if="deleting" class="uBtn__spin"></span>
                                Delete User
                            </button>
                        </div>
                    </div>
                </div>
            </Transition>
        </Teleport>

        <!-- ── Assign Company Modal ───────────────────────────────────────────── -->
        <Teleport to="body">
            <Transition name="admin-modal">
                <div v-if="showAssignCompany" class="admin-modalOverlay" @click="closeAssignCompany">
                    <div class="admin-modal uModal" @click.stop>
                        <div class="admin-modal__header uModal__header">
                            <div class="uModal__titleWrap">
                                <div class="uModal__iconWrap uModal__iconWrap--blue">
                                    <svg viewBox="0 0 20 20" fill="none">
                                        <path d="M3 17V5a2 2 0 0 1 2-2h4v14" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>
                                        <path d="M9 17V7a2 2 0 0 1 2-2h6v12" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>
                                    </svg>
                                </div>
                                <div>
                                    <h2 class="admin-modal__title">Assign Company</h2>
                                    <p class="uModal__sub">{{ assignTarget?.name }} · {{ assignTarget?.email }}</p>
                                </div>
                            </div>
                            <button class="admin-modal__close uModal__close" @click="closeAssignCompany">✕</button>
                        </div>
                        <div class="admin-modal__body">
                            <div v-if="assignError" class="admin-alert admin-alert--error">{{ assignError }}</div>
                            <div class="admin-field">
                                <label class="admin-field__label">Select Company</label>
                                <div v-if="loadingCompanies" class="uDropLoader">
                                    <div class="uDropLoader__spinner"></div>
                                    <span>Loading companies…</span>
                                </div>
                                <select v-else v-model="assignCompanyId" class="admin-input admin-input--select">
                                    <option value="">— Choose a company —</option>
                                    <option v-for="c in companies" :key="c.id" :value="c.id">
                                        {{ c.name }}{{ c.server_id ? ' · Server ' + c.server_id : '' }}
                                    </option>
                                </select>
                                <p class="uModal__hint">The user will only see calls and reports for the assigned company.</p>
                            </div>
                        </div>
                        <div class="admin-modal__footer uModal__footer">
                            <button class="uBtn uBtn--ghost" @click="closeAssignCompany">Cancel</button>
                            <button class="uBtn uBtn--primary" :disabled="!assignCompanyId || assigning || loadingCompanies" @click="submitAssignCompany">
                                <span v-if="assigning" class="uBtn__spin"></span>
                                Assign Company
                            </button>
                        </div>
                    </div>
                </div>
            </Transition>
        </Teleport>
    </div>
</template>

<script setup>
import { ref, computed, onMounted, h } from "vue";
import adminApi from "../../router/admin/api";
import { showAdminToast } from "../../admin/toast";

// ── Inline components ────────────────────────────────────────────────────────
const StatusBadge = {
    props: { status: String },
    setup(props) {
        return () => props.status === 'suspended'
            ? h('span', { class: 'uBadge uBadge--suspended' }, [h('span', { class: 'uBadge__dot' }), 'Suspended'])
            : h('span', { class: 'uBadge uBadge--active' }, [h('span', { class: 'uBadge__dot' }), 'Active']);
    },
};

const CompanyBadge = {
    props: { company: Object },
    setup(props) {
        return () => props.company
            ? h('span', { class: 'uBadge uBadge--assigned' }, [h('span', { class: 'uBadge__dot' }), props.company.name])
            : h('span', { class: 'uBadge uBadge--unassigned' }, [h('span', { class: 'uBadge__dot' }), 'Unassigned']);
    },
};

// ── State ────────────────────────────────────────────────────────────────────
const search   = ref("");
const page     = ref(1);
const rows     = ref([]);
const meta     = ref({ currentPage: 1, lastPage: 1, total: 0 });
const loading  = ref(true);
const error    = ref("");
const togglingId = ref(null);

// Add user
const showAddUser     = ref(false);
const adding          = ref(false);
const addError        = ref("");
const addFieldErrors  = ref({});
const showAddPassword = ref(false);
const addForm         = ref({ name: "", email: "", password: "", company_id: "" });

// Delete
const showDeleteConfirm = ref(false);
const deleteTarget      = ref(null);
const deleting          = ref(false);

// Assign company
const showAssignCompany = ref(false);
const assignTarget      = ref(null);
const assignCompanyId   = ref("");
const assigning         = ref(false);
const assignError       = ref("");
const companies         = ref([]);
const loadingCompanies  = ref(false);

// ── Computed ─────────────────────────────────────────────────────────────────
const filteredRows = computed(() => {
    const q = search.value.toLowerCase().trim();
    if (!q) return rows.value;
    return rows.value.filter(u =>
        u.name?.toLowerCase().includes(q) || u.email?.toLowerCase().includes(q)
    );
});

// ── Helpers ──────────────────────────────────────────────────────────────────
function initials(name) {
    if (!name) return "?";
    return name.trim().split(/\s+/).slice(0, 2).map(w => w[0]?.toUpperCase()).join("");
}
const AVATAR_COLORS = ["#3b82f6","#8b5cf6","#ec4899","#f59e0b","#10b981","#06b6d4","#f97316"];
function avatarColor(name) {
    let h = 0;
    for (let i = 0; i < (name?.length ?? 0); i++) h += name.charCodeAt(i);
    return AVATAR_COLORS[h % AVATAR_COLORS.length];
}
function formatDate(iso) {
    if (!iso) return "—";
    return new Date(iso).toLocaleDateString(undefined, { year: "numeric", month: "short", day: "numeric" });
}

// ── Data ─────────────────────────────────────────────────────────────────────
async function fetchUsers(pg = page.value) {
    loading.value = true;
    error.value = "";
    try {
        const res = await adminApi.get("/users", { params: { page: pg, per_page: 25 } });
        rows.value = res.data.data ?? [];
        meta.value = res.data.meta ?? meta.value;
        page.value = pg;
    } catch (e) {
        error.value = e?.response?.data?.message ?? "Failed to load users.";
    } finally {
        loading.value = false;
    }
}
function goToPage(pg) { fetchUsers(pg); }

async function loadCompanies() {
    if (companies.value.length) return;
    loadingCompanies.value = true;
    try {
        const res = await adminApi.get("/companies/dropdown");
        companies.value = res.data.data ?? [];
    } catch {
        // ignore
    } finally {
        loadingCompanies.value = false;
    }
}

// ── Add User ─────────────────────────────────────────────────────────────────
function openAddUser() {
    addForm.value = { name: "", email: "", password: "", company_id: "" };
    addError.value = "";
    addFieldErrors.value = {};
    showAddPassword.value = false;
    showAddUser.value = true;
    loadCompanies();
}
function closeAddUser() { showAddUser.value = false; }

async function submitAddUser() {
    addError.value = "";
    addFieldErrors.value = {};
    adding.value = true;
    try {
        const payload = {
            name: addForm.value.name,
            email: addForm.value.email,
            password: addForm.value.password,
            company_id: addForm.value.company_id || null,
        };
        const res = await adminApi.post("/users", payload);
        showAdminToast(res.data.message ?? "User created successfully.");
        closeAddUser();
        await fetchUsers();
    } catch (e) {
        const errors = e?.response?.data?.errors;
        if (errors) {
            const flat = {};
            for (const [field, msgs] of Object.entries(errors)) {
                flat[field] = Array.isArray(msgs) ? msgs[0] : msgs;
            }
            addFieldErrors.value = flat;
            addError.value = e?.response?.data?.message ?? "Please fix the errors below.";
        } else {
            addError.value = e?.response?.data?.message ?? "Failed to create user.";
        }
    } finally {
        adding.value = false;
    }
}

// ── Delete User ───────────────────────────────────────────────────────────────
function confirmDelete(user) {
    deleteTarget.value = user;
    showDeleteConfirm.value = true;
}
function closeDeleteConfirm() {
    showDeleteConfirm.value = false;
    deleteTarget.value = null;
}
async function submitDelete() {
    if (!deleteTarget.value) return;
    deleting.value = true;
    try {
        const res = await adminApi.delete(`/users/${deleteTarget.value.id}`);
        showAdminToast(res.data.message);
        closeDeleteConfirm();
        await fetchUsers();
    } catch (e) {
        showAdminToast(e?.response?.data?.message ?? 'Failed to delete user.', 'error');
    } finally {
        deleting.value = false;
    }
}

// ── Toggle Status ─────────────────────────────────────────────────────────────
async function toggleStatus(user) {
    if (togglingId.value) return;
    const isSuspended = user.account_status === 'suspended';
    if (!confirm(`${isSuspended ? 'Reactivate' : 'Suspend'} ${user.name}?`)) return;
    togglingId.value = user.id;
    try {
        const res = await adminApi.patch(`/users/${user.id}/toggle-status`);
        user.account_status = res.data.account_status;
        showAdminToast(res.data.message);
    } catch (e) {
        showAdminToast(e?.response?.data?.message ?? 'Failed to update status.', 'error');
    } finally {
        togglingId.value = null;
    }
}

// ── Assign Company ────────────────────────────────────────────────────────────
async function openAssignCompany(user) {
    assignTarget.value    = user;
    assignCompanyId.value = user.company?.id ?? "";
    assignError.value     = "";
    showAssignCompany.value = true;
    loadCompanies();
}
function closeAssignCompany() {
    showAssignCompany.value = false;
    assignTarget.value      = null;
    assignCompanyId.value   = "";
    assignError.value       = "";
}
async function submitAssignCompany() {
    if (!assignTarget.value || !assignCompanyId.value) return;
    assigning.value = true;
    try {
        await adminApi.post(`/companies/${assignCompanyId.value}/assign-user`, {
            user_id: assignTarget.value.id,
        });
        showAdminToast("Company assigned successfully.");
        closeAssignCompany();
        await fetchUsers();
    } catch (e) {
        assignError.value = e?.response?.data?.message ?? "Failed to assign company.";
    } finally {
        assigning.value = false;
    }
}

onMounted(() => fetchUsers());
</script>

<style scoped>
/* ── Header ──────────────────────────────────────────────────────────────── */
.uHeader { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; flex-wrap: wrap; }
.uHeader__left { display: flex; align-items: flex-start; gap: 16px; }
.uHeader__right { display: flex; align-items: center; gap: 16px; flex-shrink: 0; }
.uHeader__icon {
    width: 48px; height: 48px; border-radius: 14px; flex-shrink: 0;
    background: linear-gradient(135deg, rgba(59,130,246,.18), rgba(99,102,241,.18));
    border: 1px solid rgba(59,130,246,.25);
    display: flex; align-items: center; justify-content: center;
    color: #60a5fa;
}
.uHeader__icon svg { width: 24px; height: 24px; }
.uHeader__stat { text-align: right; }
.uHeader__statVal { font-size: 28px; font-weight: 800; line-height: 1; }
.uHeader__statLabel { font-size: 12px; opacity: .6; margin-top: 2px; }

.uAddBtn {
    display: inline-flex; align-items: center; gap: 7px;
    height: 40px; padding: 0 18px;
    background: linear-gradient(135deg, #3b82f6, #2563eb);
    color: #fff; border: none; border-radius: 10px;
    font-size: 14px; font-weight: 700; cursor: pointer;
    box-shadow: 0 2px 10px rgba(59,130,246,.35);
    transition: box-shadow .15s, transform .1s;
    white-space: nowrap;
}
.uAddBtn:hover { box-shadow: 0 4px 16px rgba(59,130,246,.45); transform: translateY(-1px); }
.uAddBtn svg { width: 16px; height: 16px; }

/* ── Toolbar ─────────────────────────────────────────────────────────────── */
.uToolbar { display: flex; gap: 12px; align-items: flex-end; margin-bottom: 20px; flex-wrap: wrap; }
.uToolbar__search { flex: 1; min-width: 200px; }
.uSearch { position: relative; }
.uSearch__icon { position: absolute; left: 11px; top: 50%; transform: translateY(-50%); width: 16px; height: 16px; opacity: .45; pointer-events: none; }
.uSearch__input { padding-left: 34px !important; }

.uRefreshBtn {
    display: inline-flex; align-items: center; gap: 6px;
    height: 38px; padding: 0 14px;
    background: var(--bg-faint, rgba(0,0,0,.04));
    border: 1px solid var(--border-soft, rgba(0,0,0,.1));
    border-radius: 8px; font-size: 13px; font-weight: 600;
    cursor: pointer; white-space: nowrap; color: var(--text-secondary);
    transition: background .15s, border-color .15s;
}
.uRefreshBtn:hover { background: var(--accent-hover-bg); border-color: var(--accent-border); color: var(--accent); }
.uRefreshBtn svg { width: 16px; height: 16px; transition: transform .5s; }
.uRefreshBtn--spinning svg { animation: rotateSpin .8s linear infinite; }
@keyframes rotateSpin { to { transform: rotate(360deg); } }

/* ── Table ───────────────────────────────────────────────────────────────── */
.uTable { display: flex; flex-direction: column; gap: 0; border: 1px solid var(--border-soft, rgba(0,0,0,.08)); border-radius: 12px; overflow: hidden; }

.uTable__head {
    display: grid;
    grid-template-columns: 2fr 1.4fr 1fr 1fr 1.5fr;
    gap: 0;
    background: var(--bg-faint, rgba(0,0,0,.03));
    border-bottom: 1px solid var(--border-soft, rgba(0,0,0,.08));
    padding: 0 16px;
}
.uTable__hCell {
    padding: 10px 8px;
    font-size: 11px; font-weight: 700; letter-spacing: .06em;
    text-transform: uppercase; opacity: .55;
}

.uTable__row {
    display: grid;
    grid-template-columns: 2fr 1.4fr 1fr 1fr 1.5fr;
    gap: 0; align-items: center;
    padding: 0 16px;
    border-bottom: 1px solid var(--border-soft, rgba(0,0,0,.05));
    transition: background .12s;
}
.uTable__row:last-child { border-bottom: none; }
.uTable__row:hover { background: var(--bg-faint, rgba(0,0,0,.02)); }

.uCell { padding: 14px 8px; }
.uCell--user { display: flex; align-items: center; gap: 12px; padding: 14px 8px 14px 0; }
.uCell--actions { display: flex; gap: 6px; padding: 14px 8px; flex-wrap: wrap; }

/* ── Avatar ──────────────────────────────────────────────────────────────── */
.uAvatar {
    width: 36px; height: 36px; border-radius: 10px; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center;
    color: #fff; font-size: 13px; font-weight: 700;
}
.uUserInfo { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
.uUserInfo__name { font-weight: 700; font-size: 14px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.uUserInfo__email { font-size: 12px; opacity: .55; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

/* ── Badge ───────────────────────────────────────────────────────────────── */
.uBadge {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 3px 10px 3px 7px; border-radius: 999px;
    font-size: 12px; font-weight: 600; white-space: nowrap;
}
.uBadge__dot { width: 6px; height: 6px; border-radius: 50%; flex-shrink: 0; }
.uBadge--assigned { background: rgba(16,185,129,.1); color: #059669; border: 1px solid rgba(16,185,129,.2); }
.uBadge--assigned .uBadge__dot { background: #10b981; box-shadow: 0 0 5px rgba(16,185,129,.6); }
.uBadge--unassigned { background: rgba(245,158,11,.1); color: #d97706; border: 1px solid rgba(245,158,11,.2); }
.uBadge--unassigned .uBadge__dot { background: #f59e0b; }
.uBadge--active { background: rgba(59,130,246,.08); color: #2563eb; border: 1px solid rgba(59,130,246,.2); }
.uBadge--active .uBadge__dot { background: #3b82f6; box-shadow: 0 0 5px rgba(59,130,246,.6); }
.uBadge--suspended { background: rgba(239,68,68,.08); color: #dc2626; border: 1px solid rgba(239,68,68,.2); }
.uBadge--suspended .uBadge__dot { background: #ef4444; }

/* ── Call limit ──────────────────────────────────────────────────────────── */
.uCallLimit { font-size: 13px; font-weight: 700; color: var(--text-primary); }
.uCallLimit__unit { font-size: 11px; font-weight: 500; opacity: .55; margin-left: 2px; }

.uDate { font-size: 13px; opacity: .6; white-space: nowrap; }
.uMuted { font-size: 13px; opacity: .4; }

/* ── Action buttons ──────────────────────────────────────────────────────── */
.uActionBtn {
    display: inline-flex; align-items: center; gap: 5px;
    height: 32px; padding: 0 10px;
    border-radius: 8px; font-size: 12px; font-weight: 600;
    cursor: pointer; border: 1px solid transparent;
    transition: background .15s, border-color .15s, transform .1s;
    white-space: nowrap;
}
.uActionBtn svg { width: 14px; height: 14px; flex-shrink: 0; }
.uActionBtn:active { transform: scale(.97); }
.uActionBtn--view { background: rgba(139,92,246,.08); color: #7c3aed; border-color: rgba(139,92,246,.2); text-decoration: none; }
.uActionBtn--view:hover { background: rgba(139,92,246,.16); border-color: rgba(139,92,246,.4); }
.uActionBtn--company { background: rgba(59,130,246,.08); color: #3b82f6; border-color: rgba(59,130,246,.2); }
.uActionBtn--company:hover { background: rgba(59,130,246,.16); border-color: rgba(59,130,246,.4); }
.uActionBtn--delete { background: rgba(239,68,68,.07); color: #dc2626; border-color: rgba(239,68,68,.18); }
.uActionBtn--delete:hover { background: rgba(239,68,68,.14); border-color: rgba(239,68,68,.35); }
.uActionBtn--block { background: rgba(239,68,68,.07); color: #dc2626; border-color: rgba(239,68,68,.18); }
.uActionBtn--block:hover { background: rgba(239,68,68,.14); border-color: rgba(239,68,68,.35); }
.uActionBtn--activate { background: rgba(59,130,246,.07); color: #2563eb; border-color: rgba(59,130,246,.18); }
.uActionBtn--activate:hover { background: rgba(59,130,246,.14); border-color: rgba(59,130,246,.35); }
.uActionBtn:disabled { opacity: .45; cursor: not-allowed; }

/* ── Skeleton ────────────────────────────────────────────────────────────── */
.uTable__row--sk { pointer-events: none; }
.uSk {
    border-radius: 6px;
    background-image: linear-gradient(90deg, transparent 0%, color-mix(in srgb, currentColor 10%, transparent) 50%, transparent 100%);
    background-size: 300% 100%;
    animation: skShimmer 1.5s ease-in-out infinite;
    color: inherit;
}
@keyframes skShimmer { 0% { background-position: 100% 0; } 100% { background-position: -100% 0; } }
.uSk--avatar  { width: 36px; height: 36px; border-radius: 10px; flex-shrink: 0; }
.uSk--name    { height: 14px; width: 110px; }
.uSk--email   { height: 11px; width: 160px; }
.uSk--badge   { height: 22px; width: 90px; border-radius: 99px; }
.uSk--min     { height: 14px; width: 100px; }
.uSk--date    { height: 13px; width: 80px; }
.uSk--actions { height: 32px; width: 150px; border-radius: 8px; }

/* ── Empty ───────────────────────────────────────────────────────────────── */
.uEmpty { display: flex; flex-direction: column; align-items: center; padding: 56px 20px; gap: 10px; text-align: center; }
.uEmpty__icon { width: 52px; height: 52px; opacity: .25; }
.uEmpty__icon svg { width: 100%; height: 100%; }
.uEmpty__title { font-size: 16px; font-weight: 700; margin: 0; }
.uEmpty__sub { margin: 0; opacity: .5; font-size: 14px; }

/* ── Pagination ──────────────────────────────────────────────────────────── */
.uPager { display: flex; align-items: center; justify-content: flex-end; gap: 10px; margin-top: 16px; flex-wrap: wrap; }
.uPagerBtn {
    display: inline-flex; align-items: center; gap: 4px;
    height: 34px; padding: 0 12px; border-radius: 8px; font-size: 13px; font-weight: 600;
    background: var(--bg-faint); border: 1px solid var(--border-soft);
    cursor: pointer; transition: background .15s;
}
.uPagerBtn:disabled { opacity: .4; cursor: not-allowed; }
.uPagerBtn:not(:disabled):hover { background: var(--accent-hover-bg); }
.uPagerBtn svg { width: 14px; height: 14px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
.uPager__info { font-size: 13px; opacity: .65; }
.uPager__total { font-size: 12px; opacity: .6; }

/* ── Modals ──────────────────────────────────────────────────────────────── */
.uModal { max-width: 620px; width: 100%; }
.uModal--sm { max-width: 420px; }
.uModal__header { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; }
.uModal__titleWrap { display: flex; align-items: center; gap: 12px; }
.uModal__iconWrap {
    width: 40px; height: 40px; border-radius: 12px; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center;
}
.uModal__iconWrap svg { width: 20px; height: 20px; }
.uModal__iconWrap--blue { background: rgba(59,130,246,.12); border: 1px solid rgba(59,130,246,.2); color: #3b82f6; }
.uModal__iconWrap--red  { background: rgba(239,68,68,.12);  border: 1px solid rgba(239,68,68,.2);  color: #dc2626; }
.uModal__close {
    width: 32px !important; height: 32px !important; font-size: 15px !important;
    display: flex !important; align-items: center !important; justify-content: center !important;
    padding: 0 !important; flex-shrink: 0;
}
.uModal__sub  { font-size: 12px; opacity: .55; margin: 2px 0 0; }
.uModal__hint { font-size: 12px; opacity: .55; margin: 6px 0 0; line-height: 1.5; }
.uModal__footer { display: flex; gap: 10px; justify-content: flex-end; }

/* ── Form ────────────────────────────────────────────────────────────────── */
.uFormGrid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; align-items: start; }
.uFormGrid__full { grid-column: 1 / -1; }
.uRequired { color: #ef4444; }
.uFieldErr  { font-size: 12px; color: #ef4444; margin-top: 4px; }
.uFieldHint { font-size: 12px; opacity: .55; margin-top: 4px; }
.uFormInput { height: 42px !important; font-size: 14px !important; box-sizing: border-box; }

/* Password with show/hide */
.uPassWrap { position: relative; display: flex; align-items: center; }
.uPassWrap__input { flex: 1; padding-right: 58px !important; }
.uPassWrap__toggle {
    position: absolute; right: 10px;
    top: 0; bottom: 0; margin: auto 0;
    height: 26px;
    display: flex; align-items: center;
    background: none; border: none; cursor: pointer;
    font-size: 12px; font-weight: 600; color: var(--accent, #3b82f6);
    padding: 0 6px; border-radius: 4px;
    transition: background .12s; white-space: nowrap;
}
.uPassWrap__toggle:hover { background: var(--accent-hover-bg, rgba(59,130,246,.1)); }

/* Email note */
.uEmailNote {
    display: flex; align-items: center; gap: 9px;
    padding: 11px 14px;
    background: color-mix(in srgb, #3b82f6 8%, transparent);
    border: 1px solid color-mix(in srgb, #3b82f6 20%, transparent);
    border-radius: 8px;
    font-size: 13px; color: var(--text-secondary);
    line-height: 1.4; margin-top: 4px;
}
.uEmailNote svg { width: 16px; height: 16px; flex-shrink: 0; color: #3b82f6; }

/* ── Delete modal ────────────────────────────────────────────────────────── */
.uDeleteInfo {
    display: flex; align-items: center; gap: 12px;
    padding: 12px 14px;
    background: var(--bg-faint); border: 1px solid var(--border-soft);
    border-radius: 10px; margin-bottom: 14px;
}
.uDeleteInfo__avatar {
    width: 40px; height: 40px; border-radius: 10px; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center;
    color: #fff; font-size: 14px; font-weight: 700;
}
.uDeleteInfo__name  { font-weight: 700; font-size: 14px; }
.uDeleteInfo__email { font-size: 12px; opacity: .55; margin-top: 2px; }
.uDeleteWarn {
    display: flex; align-items: flex-start; gap: 10px;
    background: rgba(245,158,11,.07); border: 1px solid rgba(245,158,11,.22);
    border-radius: 10px; padding: 12px 14px; font-size: 13px; color: #d97706; line-height: 1.5;
}
.uDeleteWarn svg { width: 18px; height: 18px; flex-shrink: 0; margin-top: 1px; }
.uDeleteWarn strong { display: block; margin-bottom: 3px; font-weight: 700; }
.uDeleteWarn p { margin: 0; opacity: .85; }

/* ── Dropdown loader ─────────────────────────────────────────────────────── */
.uDropLoader {
    display: flex; align-items: center; gap: 10px;
    padding: 11px 14px; background: var(--bg-faint);
    border: 1px solid var(--border-soft); border-radius: 8px; font-size: 13px; opacity: .7;
}
.uDropLoader__spinner {
    width: 16px; height: 16px; flex-shrink: 0;
    border: 2px solid var(--border-soft); border-top-color: var(--accent, #3b82f6);
    border-radius: 50%; animation: dropSpin .7s linear infinite;
}
@keyframes dropSpin { to { transform: rotate(360deg); } }

/* ── Buttons ─────────────────────────────────────────────────────────────── */
.uBtn {
    display: inline-flex; align-items: center; gap: 6px;
    height: 38px; padding: 0 18px; border-radius: 8px;
    font-size: 13.5px; font-weight: 600; cursor: pointer; border: none;
    transition: background .15s, box-shadow .15s, transform .1s;
}
.uBtn:disabled { opacity: .5; cursor: not-allowed; }
.uBtn--ghost   { background: var(--bg-faint); border: 1px solid var(--border-soft); color: var(--text-secondary); }
.uBtn--ghost:not(:disabled):hover { background: var(--accent-hover-bg); }
.uBtn--primary { background: linear-gradient(135deg, #3b82f6, #2563eb); color: #fff; box-shadow: 0 2px 8px rgba(59,130,246,.3); }
.uBtn--primary:not(:disabled):hover { box-shadow: 0 4px 14px rgba(59,130,246,.4); transform: translateY(-1px); }
.uBtn--danger  { background: linear-gradient(135deg, #ef4444, #dc2626); color: #fff; box-shadow: 0 2px 8px rgba(239,68,68,.3); }
.uBtn--danger:not(:disabled):hover { box-shadow: 0 4px 14px rgba(239,68,68,.4); transform: translateY(-1px); }
.uBtn__spin {
    width: 14px; height: 14px; border: 2px solid rgba(255,255,255,.35);
    border-top-color: #fff; border-radius: 50%; animation: dropSpin .7s linear infinite;
}

/* ── Responsive ──────────────────────────────────────────────────────────── */
@media (max-width: 1100px) {
    .uHide--md { display: none !important; }
    .uTable__head, .uTable__row { grid-template-columns: 2fr 1.4fr 1fr 1.8fr; }
}
@media (max-width: 720px) {
    .uHide--sm { display: none !important; }
    .uHide--sm-up { display: block !important; }
    .uTable__head, .uTable__row { grid-template-columns: 1fr auto; }
    .uTable__head .uTable__hCell:not(:first-child):not(:last-child) { display: none; }
    .uHeader__stat { display: none; }
    .uActionBtn span { display: none; }
    .uActionBtn { padding: 0 8px; }
    .uFormGrid { grid-template-columns: 1fr; }
}
@media (min-width: 601px) {
    .uHide--sm-up { display: none !important; }
}
</style>
