<template>
    <div class="admin-container admin-page">

        <!-- Page Header -->
        <header class="admin-page__header">
            <div>
                <p class="admin-page__kicker">SaaS</p>
                <h1 class="admin-page__title">Plans</h1>
                <p class="admin-page__subtitle">
                    Create and manage subscription plans with pricing and minute limits.
                </p>
            </div>
            <button class="pBtn pBtn--primary" @click="openCreate">
                <svg viewBox="0 0 20 20" fill="currentColor" class="pBtn__icon">
                    <path d="M10 3a1 1 0 011 1v5h5a1 1 0 110 2h-5v5a1 1 0 11-2 0v-5H4a1 1 0 110-2h5V4a1 1 0 011-1z"/>
                </svg>
                New Plan
            </button>
        </header>

        <!-- Error -->
        <div v-if="error" class="pAlert">{{ error }}</div>

        <!-- Loading Skeleton -->
        <div v-if="loading" class="planGrid">
            <div v-for="i in 3" :key="i" class="planCard planCard--skeleton">
                <div class="sk sk--title"></div>
                <div class="sk sk--price"></div>
                <div class="sk sk--meta"></div>
                <div class="sk sk--btn"></div>
            </div>
        </div>

        <!-- Empty -->
        <div v-else-if="rows.length === 0" class="pEmpty">
            <div class="pEmpty__icon">
                <svg viewBox="0 0 24 24" fill="none">
                    <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.5"/>
                    <path d="M12 7v1.5m0 7V17m0-7.5a2 2 0 0 0-2 2c0 1.1.9 2 2 2a2 2 0 0 1 2 2 2 2 0 0 1-2 2m0-8a2 2 0 0 1 2 2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                </svg>
            </div>
            <p class="pEmpty__title">No plans yet</p>
            <p class="pEmpty__sub">Create your first plan to start offering subscriptions.</p>
            <button class="pBtn pBtn--primary" @click="openCreate">Create first plan</button>
        </div>

        <!-- Plan Cards Grid -->
        <div v-else class="planGrid">
            <div
                v-for="plan in rows"
                :key="plan.id"
                class="planCard"
                :class="{ 'planCard--inactive': !plan.is_active }"
            >
                <!-- Sale ribbon -->
                <div v-if="plan.has_sale" class="planCard__ribbon">
                    {{ plan.discount_percent }}% OFF
                </div>

                <!-- Header row -->
                <div class="planCard__head">
                    <div class="planCard__nameWrap">
                        <h3 class="planCard__name">{{ plan.name }}</h3>
                        <StatusBadge :active="plan.is_active" />
                    </div>
                    <span v-if="plan.is_featured" class="planCard__featured">★ Most Popular</span>
                </div>

                <!-- Pricing -->
                <div class="planCard__pricing">
                    <template v-if="plan.has_sale">
                        <span class="planCard__priceOriginal">${{ plan.price }}</span>
                        <span class="planCard__priceSale">${{ plan.sale_price }}</span>
                        <span class="planCard__pricePer">/purchase</span>
                    </template>
                    <template v-else>
                        <span class="planCard__priceRegular">${{ plan.price }}</span>
                        <span class="planCard__pricePer">/purchase</span>
                    </template>
                </div>

                <!-- Minutes pill -->
                <div class="planCard__minutes">
                    <div class="minutePill">
                        <svg class="minutePill__icon" viewBox="0 0 20 20" fill="none">
                            <circle cx="10" cy="10" r="8" stroke="currentColor" stroke-width="1.5"/>
                            <path d="M10 6v4l2.5 2.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                        </svg>
                        <span class="minutePill__val">{{ Number(plan.credits ?? 0).toLocaleString() }}</span>
                        <span class="minutePill__label">credits</span>
                    </div>
                </div>

                <!-- Meta -->
                <p class="planCard__meta">Created {{ formatDate(plan.created_at) }}</p>

                <!-- Actions -->
                <div class="planCard__actions">
                    <button class="actionBtn actionBtn--edit" @click="openEdit(plan)" title="Edit plan">
                        <svg viewBox="0 0 20 20" fill="none">
                            <path d="M13.586 3.586a2 2 0 112.828 2.828l-.793.793-2.828-2.828.793-.793zM11.379 5.793L3 14.172V17h2.828l8.38-8.379-2.83-2.828z" fill="currentColor"/>
                        </svg>
                        Edit
                    </button>
                    <button
                        v-if="plan.is_active"
                        class="actionBtn actionBtn--deactivate"
                        @click="deactivate(plan)"
                        title="Deactivate plan"
                    >
                        <svg viewBox="0 0 20 20" fill="none">
                            <circle cx="10" cy="10" r="8" stroke="currentColor" stroke-width="1.5"/>
                            <path d="M8 8l4 4M12 8l-4 4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                        </svg>
                        Deactivate
                    </button>
                    <button
                        v-else
                        class="actionBtn actionBtn--activate"
                        @click="activate(plan)"
                        title="Activate plan"
                    >
                        <svg viewBox="0 0 20 20" fill="none">
                            <circle cx="10" cy="10" r="8" stroke="currentColor" stroke-width="1.5"/>
                            <path d="M7 10l2 2 4-4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                        Activate
                    </button>
                </div>
            </div>
        </div>

        <!-- Create / Edit Modal -->
        <Teleport to="body">
            <Transition name="admin-modal">
                <div v-if="showForm" class="admin-modalOverlay" @click="closeForm">
                    <div class="admin-modal pModal" @click.stop>
                        <div class="admin-modal__header">
                            <div class="pModal__titleWrap">
                                <div class="pModal__titleIcon">
                                    <svg viewBox="0 0 20 20" fill="none">
                                        <circle cx="10" cy="10" r="8" stroke="currentColor" stroke-width="1.5"/>
                                        <path d="M10 6.5v1.2m0 4.3V13m0-4.3a1.7 1.7 0 0 0-1.7 1.7c0 .94.76 1.7 1.7 1.7a1.7 1.7 0 0 1 1.7 1.7 1.7 1.7 0 0 1-1.7 1.7m0-6.8a1.7 1.7 0 0 1 1.7 1.7" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
                                    </svg>
                                </div>
                                <h2 class="admin-modal__title">{{ isEditing ? 'Edit Plan' : 'Create New Plan' }}</h2>
                            </div>
                            <button type="button" class="admin-modal__close pModal__close" @click="closeForm">✕</button>
                        </div>

                        <div class="admin-modal__body pModal__body">
                            <div v-if="formErrors.general" class="admin-alert admin-alert--error">{{ formErrors.general[0] }}</div>

                            <!-- Name -->
                            <div class="pField">
                                <label class="pField__label" for="plan-name">
                                    Plan Name <span class="pField__req">*</span>
                                </label>
                                <input
                                    id="plan-name"
                                    v-model="form.name"
                                    class="pField__input"
                                    :class="{ 'pField__input--err': formErrors.name }"
                                    type="text"
                                    placeholder="e.g. Starter, Pro, Business"
                                />
                                <p v-if="formErrors.name" class="pField__errMsg">{{ formErrors.name[0] }}</p>
                            </div>

                            <!-- Credits -->
                            <div class="pField">
                                <label class="pField__label" for="plan-credits">
                                    Credits Included <span class="pField__req">*</span>
                                </label>
                                <div class="pField__inputWrap">
                                    <input
                                        id="plan-credits"
                                        v-model.number="form.credits"
                                        class="pField__input"
                                        :class="{ 'pField__input--err': formErrors.credits }"
                                        type="number"
                                        min="1"
                                        placeholder="100"
                                    />
                                    <span class="pField__unit">cr</span>
                                </div>
                                <p v-if="formErrors.credits" class="pField__errMsg">{{ formErrors.credits[0] }}</p>
                                <p v-else class="pField__hint">Credits added to the company balance when this plan is purchased.</p>
                            </div>

                            <!-- Pricing row -->
                            <div class="pField__row">
                                <!-- Regular price -->
                                <div class="pField">
                                    <label class="pField__label" for="plan-price">
                                        Regular Price <span class="pField__req">*</span>
                                    </label>
                                    <div class="pField__inputWrap">
                                        <span class="pField__prefix">$</span>
                                        <input
                                            id="plan-price"
                                            v-model="form.price"
                                            class="pField__input pField__input--prefixed"
                                            :class="{ 'pField__input--err': formErrors.price }"
                                            type="text"
                                            placeholder="10.00"
                                        />
                                    </div>
                                    <p v-if="formErrors.price" class="pField__errMsg">{{ formErrors.price[0] }}</p>
                                </div>

                                <!-- Sale price -->
                                <div class="pField">
                                    <label class="pField__label" for="plan-sale-price">
                                        Sale Price
                                        <span class="pField__optional">optional</span>
                                    </label>
                                    <div class="pField__inputWrap">
                                        <span class="pField__prefix pField__prefix--sale">$</span>
                                        <input
                                            id="plan-sale-price"
                                            v-model="form.sale_price"
                                            class="pField__input pField__input--prefixed pField__input--sale"
                                            :class="{ 'pField__input--err': formErrors.sale_price || salePriceError }"
                                            type="text"
                                            placeholder="7.00"
                                        />
                                    </div>
                                    <p v-if="formErrors.sale_price" class="pField__errMsg">{{ formErrors.sale_price[0] }}</p>
                                    <p v-else class="pField__hint">Leave blank for no sale. Must be less than regular price.</p>
                                </div>
                            </div>

                            <!-- Sale price error alert (real-time) -->
                            <div v-if="salePriceError" class="pSaleAlert">
                                <svg class="pSaleAlert__icon" viewBox="0 0 20 20" fill="currentColor">
                                    <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                                </svg>
                                <span>{{ salePriceError }}</span>
                            </div>

                            <!-- Sale preview (only when valid) -->
                            <div v-else-if="salePricePreview" class="pSalePreview">
                                <div class="pSalePreview__badge">SALE</div>
                                <div class="pSalePreview__text">
                                    <span class="pSalePreview__original">${{ form.price }}</span>
                                    <span class="pSalePreview__arrow">→</span>
                                    <span class="pSalePreview__sale">${{ form.sale_price }}</span>
                                    <span class="pSalePreview__pct">({{ salePricePreview }}% off)</span>
                                </div>
                            </div>

                            <!-- Description -->
                            <div class="pField">
                                <label class="pField__label" for="plan-description">
                                    Description
                                    <span class="pField__optional">optional</span>
                                </label>
                                <textarea
                                    id="plan-description"
                                    v-model="form.description"
                                    class="pField__input pField__textarea"
                                    rows="2"
                                    maxlength="500"
                                    placeholder="e.g. About 2 months of analysis. Most teams your size pick this."
                                ></textarea>
                                <p class="pField__hint">Shown under the credit count on the customer's Buy Credits page.</p>
                            </div>

                            <!-- Status toggle -->
                            <div class="pToggleField">
                                <label class="pToggle" :class="{ 'pToggle--on': form.is_active }">
                                    <input v-model="form.is_active" type="checkbox" class="pToggle__input" />
                                    <span class="pToggle__track">
                                        <span class="pToggle__thumb"></span>
                                    </span>
                                    <span class="pToggle__label">
                                        <strong>{{ form.is_active ? 'Active' : 'Inactive' }}</strong>
                                        <span>— {{ form.is_active ? 'Available for assignment to companies' : 'Hidden from assignment' }}</span>
                                    </span>
                                </label>
                            </div>

                            <!-- Featured toggle -->
                            <div class="pToggleField">
                                <label class="pToggle" :class="{ 'pToggle--on': form.is_featured }">
                                    <input v-model="form.is_featured" type="checkbox" class="pToggle__input" />
                                    <span class="pToggle__track">
                                        <span class="pToggle__thumb"></span>
                                    </span>
                                    <span class="pToggle__label">
                                        <strong>{{ form.is_featured ? 'Most Popular' : 'Not Featured' }}</strong>
                                        <span>— {{ form.is_featured ? 'Highlighted as the recommended plan' : 'Shown as a regular plan' }}</span>
                                    </span>
                                </label>
                            </div>
                        </div>

                        <div class="admin-modal__footer pModal__footer">
                            <button class="pBtn pBtn--ghost" @click="closeForm">Cancel</button>
                            <button class="pBtn pBtn--primary" :disabled="submitting || !!salePriceError" @click="submitForm">
                                <span v-if="submitting" class="pBtn__spinner"></span>
                                {{ isEditing ? 'Save Changes' : 'Create Plan' }}
                            </button>
                        </div>
                    </div>
                </div>
            </Transition>
        </Teleport>

        <!-- Deactivate Confirm -->
        <Teleport to="body">
            <Transition name="admin-modal">
                <div v-if="showDeactivateConfirm" class="admin-modalOverlay" @click="showDeactivateConfirm = false">
                    <div class="admin-modal pModal pModal--sm" @click.stop>
                        <div class="admin-modal__header">
                            <h2 class="admin-modal__title">Deactivate Plan</h2>
                            <button type="button" class="admin-modal__close pModal__close" @click="showDeactivateConfirm = false">✕</button>
                        </div>
                        <div class="admin-modal__body">
                            <p>Deactivate <strong>{{ deactivateTarget?.name }}</strong>?</p>
                            <p class="pModal__confirmNote">Existing companies keep their current minute balance. This plan won't be assignable to new companies.</p>
                        </div>
                        <div class="admin-modal__footer pModal__footer">
                            <button class="pBtn pBtn--ghost" @click="showDeactivateConfirm = false">Cancel</button>
                            <button class="pBtn pBtn--danger" :disabled="deactivating" @click="confirmDeactivate">
                                <span v-if="deactivating" class="pBtn__spinner"></span>
                                Deactivate
                            </button>
                        </div>
                    </div>
                </div>
            </Transition>
        </Teleport>
    </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from "vue";
import adminApi from "../../router/admin/api";
import { showAdminToast } from "../../admin/toast";

// ─── Status badge inline component ───────────────────────────────────────────
const StatusBadge = {
    props: { active: Boolean },
    template: `
        <span class="sBadge" :class="active ? 'sBadge--active' : 'sBadge--inactive'">
            <span class="sBadge__dot"></span>
            {{ active ? 'Active' : 'Inactive' }}
        </span>
    `,
};

// ─── State ────────────────────────────────────────────────────────────────────
const rows = ref([]);
const loading = ref(true);
const error = ref("");

const showForm = ref(false);
const isEditing = ref(false);
const submitting = ref(false);
const formErrors = ref({});
const form = reactive({
    id: null,
    name: "",
    credits: 100,
    price: "",
    sale_price: "",
    description: "",
    is_active: true,
    is_featured: false,
});

const showDeactivateConfirm = ref(false);
const deactivateTarget = ref(null);
const deactivating = ref(false);

// ─── Real-time sale price validation ─────────────────────────────────────────
const salePriceError = computed(() => {
    if (!form.sale_price && form.sale_price !== 0) return null;

    const regular = parseFloat(form.price);
    const sale = parseFloat(form.sale_price);

    if (isNaN(sale) || String(form.sale_price).trim() === "") return null;
    if (sale < 0) return "Sale price cannot be negative.";
    if (isNaN(regular) || String(form.price).trim() === "") return null; // regular price not entered yet
    if (sale === regular) return "Sale price must be less than the regular price — they can't be equal.";
    if (sale > regular) return `Sale price ($${sale.toFixed(2)}) cannot be greater than the regular price ($${regular.toFixed(2)}). Please enter a discounted price.`;
    return null;
});

// ─── Sale preview (only shown when sale is valid) ─────────────────────────────
const salePricePreview = computed(() => {
    if (salePriceError.value) return null;
    const regular = parseFloat(form.price);
    const sale = parseFloat(form.sale_price);
    if (!form.sale_price || isNaN(regular) || isNaN(sale) || sale >= regular || regular === 0) {
        return null;
    }
    return Math.round((1 - sale / regular) * 100);
});

// ─── Data ─────────────────────────────────────────────────────────────────────
async function fetchPlans() {
    loading.value = true;
    error.value = "";
    try {
        const res = await adminApi.get("/plans");
        rows.value = res.data.data ?? [];
    } catch (e) {
        error.value = e?.response?.data?.message ?? "Failed to load plans.";
    } finally {
        loading.value = false;
    }
}

function formatDate(iso) {
    if (!iso) return "—";
    return new Date(iso).toLocaleDateString(undefined, { year: "numeric", month: "short", day: "numeric" });
}

// ─── Form ─────────────────────────────────────────────────────────────────────
function resetForm() {
    form.id = null;
    form.name = "";
    form.credits = 100;
    form.price = "";
    form.sale_price = "";
    form.description = "";
    form.is_active = true;
    form.is_featured = false;
    formErrors.value = {};
}

function openCreate() {
    isEditing.value = false;
    resetForm();
    showForm.value = true;
}

function openEdit(plan) {
    isEditing.value = true;
    form.id = plan.id;
    form.name = plan.name;
    form.credits = Number(plan.credits ?? 0);
    form.price = plan.price ?? "";
    form.sale_price = plan.sale_price ?? "";
    form.description = plan.description ?? "";
    form.is_active = plan.is_active;
    form.is_featured = !!plan.is_featured;
    formErrors.value = {};
    showForm.value = true;
}

function closeForm() {
    showForm.value = false;
    resetForm();
}

// ─── Client-side validation ───────────────────────────────────────────────────
function validateForm() {
    const errors = {};

    // Name
    if (!form.name || !String(form.name).trim()) {
        errors.name = ["Plan name is required."];
    } else if (String(form.name).trim().length < 2) {
        errors.name = ["Plan name must be at least 2 characters."];
    } else if (String(form.name).trim().length > 255) {
        errors.name = ["Plan name cannot exceed 255 characters."];
    }

    // Credits
    const credits = Number(form.credits);
    if (!form.credits && form.credits !== 0) {
        errors.credits = ["Credits included is required."];
    } else if (isNaN(credits) || credits < 1) {
        errors.credits = ["Credits must be a number greater than 0."];
    } else if (credits > 1_000_000) {
        errors.credits = ["Credits cannot exceed 1,000,000."];
    }

    // Regular price
    const price = parseFloat(form.price);
    if (!String(form.price).trim()) {
        errors.price = ["Regular price is required."];
    } else if (isNaN(price)) {
        errors.price = ["Please enter a valid price (e.g. 10.00)."];
    } else if (price < 0) {
        errors.price = ["Price cannot be negative."];
    } else if (price > 99999) {
        errors.price = ["Price seems unreasonably large. Please check."];
    }

    // Sale price (only validate if provided)
    const saleTrimmed = String(form.sale_price ?? "").trim();
    if (saleTrimmed !== "") {
        const saleVal = parseFloat(saleTrimmed);
        if (isNaN(saleVal)) {
            errors.sale_price = ["Please enter a valid sale price (e.g. 7.00)."];
        } else if (saleVal < 0) {
            errors.sale_price = ["Sale price cannot be negative."];
        } else if (!isNaN(price) && saleVal >= price) {
            errors.sale_price = [
                saleVal === price
                    ? "Sale price must be less than the regular price — they can't be equal."
                    : `Sale price ($${saleVal.toFixed(2)}) must be less than the regular price ($${price.toFixed(2)}).`,
            ];
        }
    }

    return errors;
}

async function submitForm() {
    // Run client-side validation first
    const clientErrors = validateForm();
    if (Object.keys(clientErrors).length > 0) {
        formErrors.value = clientErrors;
        return;
    }

    formErrors.value = {};
    submitting.value = true;
    try {
        const saleTrimmed = String(form.sale_price ?? "").trim();
        const payload = {
            name: String(form.name).trim(),
            credits: Number(form.credits),
            price: form.price,
            sale_price: saleTrimmed !== "" ? saleTrimmed : null,
            description: String(form.description ?? "").trim() || null,
            is_active: form.is_active,
            is_featured: form.is_featured,
        };

        if (isEditing.value && form.id) {
            await adminApi.put(`/plans/${form.id}`, payload);
            showAdminToast("Plan updated successfully.");
        } else {
            await adminApi.post("/plans", payload);
            showAdminToast("Plan created successfully.");
        }
        closeForm();
        await fetchPlans();
    } catch (e) {
        if (e?.response?.data?.errors) {
            formErrors.value = e.response.data.errors;
        } else {
            formErrors.value = { general: [e?.response?.data?.message ?? "Failed to save plan."] };
        }
    } finally {
        submitting.value = false;
    }
}

// ─── Activate / Deactivate ────────────────────────────────────────────────────
function deactivate(plan) {
    deactivateTarget.value = plan;
    showDeactivateConfirm.value = true;
}

async function confirmDeactivate() {
    if (!deactivateTarget.value) return;
    deactivating.value = true;
    try {
        await adminApi.delete(`/plans/${deactivateTarget.value.id}`);
        showAdminToast("Plan deactivated.");
        showDeactivateConfirm.value = false;
        deactivateTarget.value = null;
        await fetchPlans();
    } catch (e) {
        showAdminToast(e?.response?.data?.message ?? "Failed to deactivate.", "error");
    } finally {
        deactivating.value = false;
    }
}

async function activate(plan) {
    try {
        await adminApi.put(`/plans/${plan.id}`, { is_active: true });
        showAdminToast("Plan activated.");
        await fetchPlans();
    } catch (e) {
        showAdminToast(e?.response?.data?.message ?? "Failed to activate.", "error");
    }
}

onMounted(fetchPlans);
</script>

<style scoped>
/* ── Buttons ─────────────────────────────────────────────────────────────── */
.pBtn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 0 16px;
    height: 38px;
    border-radius: 8px;
    font-size: 13.5px;
    font-weight: 600;
    cursor: pointer;
    border: none;
    transition: all 0.15s;
    white-space: nowrap;
}
.pBtn__icon { width: 16px; height: 16px; flex-shrink: 0; }
.pBtn--primary {
    background: linear-gradient(135deg, #3b82f6, #2563eb);
    color: #fff;
    box-shadow: 0 2px 8px rgba(59, 130, 246, 0.35);
}
.pBtn--primary:hover { background: linear-gradient(135deg, #2563eb, #1d4ed8); box-shadow: 0 4px 12px rgba(59, 130, 246, 0.45); transform: translateY(-1px); }
.pBtn--primary:disabled { opacity: 0.55; cursor: not-allowed; transform: none; box-shadow: none; }
.pBtn--ghost { background: transparent; color: var(--admin-text-muted, #94a3b8); border: 1px solid var(--admin-border, #334155); }
.pBtn--ghost:hover { background: var(--admin-surface-2, #1e293b); color: var(--admin-text, #e2e8f0); }
.pBtn--danger { background: linear-gradient(135deg, #ef4444, #dc2626); color: #fff; box-shadow: 0 2px 8px rgba(239,68,68,0.3); }
.pBtn--danger:hover { background: linear-gradient(135deg, #dc2626, #b91c1c); }
.pBtn--danger:disabled { opacity: 0.55; cursor: not-allowed; }
.pBtn__spinner {
    width: 14px; height: 14px;
    border: 2px solid rgba(255,255,255,0.35);
    border-top-color: #fff;
    border-radius: 50%;
    animation: spin 0.7s linear infinite;
    flex-shrink: 0;
}
@keyframes spin { to { transform: rotate(360deg); } }

/* ── Alert ───────────────────────────────────────────────────────────────── */
.pAlert {
    background: rgba(239,68,68,0.1);
    border: 1px solid rgba(239,68,68,0.4);
    color: #f87171;
    border-radius: 10px;
    padding: 12px 16px;
    font-size: 14px;
    margin-bottom: 20px;
}

/* ── Grid ────────────────────────────────────────────────────────────────── */
.planGrid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 20px;
}

/* ── Card ────────────────────────────────────────────────────────────────── */
.planCard {
    position: relative;
    background: var(--admin-surface, #0f172a);
    border: 1px solid var(--admin-border, #1e293b);
    border-radius: 16px;
    padding: 24px;
    display: flex;
    flex-direction: column;
    gap: 14px;
    transition: border-color 0.2s, box-shadow 0.2s, transform 0.2s;
    overflow: hidden;
}
.planCard:hover { border-color: #3b82f6; box-shadow: 0 0 0 1px #3b82f6, 0 8px 24px rgba(59,130,246,0.12); transform: translateY(-2px); }
.planCard--inactive { opacity: 0.6; }
.planCard--inactive:hover { border-color: var(--admin-border, #334155); box-shadow: none; transform: none; }

/* Ribbon */
.planCard__ribbon {
    position: absolute;
    top: 14px;
    right: -24px;
    background: linear-gradient(135deg, #f59e0b, #d97706);
    color: #fff;
    font-size: 10px;
    font-weight: 800;
    letter-spacing: 0.08em;
    padding: 4px 32px;
    transform: rotate(35deg);
    box-shadow: 0 2px 6px rgba(245,158,11,0.4);
}

/* Head */
.planCard__head { display: flex; flex-direction: column; gap: 6px; }
.planCard__nameWrap { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
.planCard__name { font-size: 18px; font-weight: 700; margin: 0; color: var(--admin-text, #e2e8f0); }
.planCard__featured { font-size: 11.5px; font-weight: 700; color: #f59e0b; letter-spacing: 0.02em; }

/* Status badge */
.sBadge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 3px 10px 3px 7px;
    border-radius: 999px;
    font-size: 11.5px;
    font-weight: 600;
    flex-shrink: 0;
}
.sBadge__dot { width: 6px; height: 6px; border-radius: 50%; }
.sBadge--active { background: rgba(16,185,129,0.12); color: #34d399; border: 1px solid rgba(16,185,129,0.25); }
.sBadge--active .sBadge__dot { background: #10b981; box-shadow: 0 0 5px #10b981; }
.sBadge--inactive { background: rgba(100,116,139,0.12); color: #94a3b8; border: 1px solid rgba(100,116,139,0.2); }
.sBadge--inactive .sBadge__dot { background: #64748b; }

/* Pricing */
.planCard__pricing { display: flex; align-items: baseline; gap: 6px; flex-wrap: wrap; }
.planCard__priceOriginal {
    font-size: 15px;
    font-weight: 500;
    color: var(--admin-text-muted, #64748b);
    text-decoration: line-through;
}
.planCard__priceSale {
    font-size: 28px;
    font-weight: 800;
    color: #34d399;
    line-height: 1;
}
.planCard__priceRegular {
    font-size: 28px;
    font-weight: 800;
    color: var(--admin-text, #e2e8f0);
    line-height: 1;
}
.planCard__pricePer { font-size: 13px; color: var(--admin-text-muted, #64748b); font-weight: 500; }

/* Minutes pill */
.planCard__minutes { }
.minutePill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: rgba(59,130,246,0.1);
    border: 1px solid rgba(59,130,246,0.2);
    border-radius: 8px;
    padding: 6px 12px;
}
.minutePill__icon { width: 15px; height: 15px; color: #60a5fa; flex-shrink: 0; }
.minutePill__val { font-size: 14px; font-weight: 700; color: #93c5fd; }
.minutePill__label { font-size: 12px; color: #64748b; font-weight: 500; }

/* Meta */
.planCard__meta { font-size: 12px; color: var(--admin-text-muted, #475569); margin: 0; }

/* Action buttons */
.planCard__actions { display: flex; gap: 8px; margin-top: auto; flex-wrap: wrap; }

.actionBtn {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 0 12px;
    height: 34px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    border: none;
    transition: all 0.15s;
}
.actionBtn svg { width: 14px; height: 14px; flex-shrink: 0; }

.actionBtn--edit {
    background: rgba(59,130,246,0.1);
    color: #60a5fa;
    border: 1px solid rgba(59,130,246,0.2);
}
.actionBtn--edit:hover { background: rgba(59,130,246,0.2); border-color: rgba(59,130,246,0.4); }

.actionBtn--deactivate {
    background: rgba(239,68,68,0.08);
    color: #f87171;
    border: 1px solid rgba(239,68,68,0.15);
}
.actionBtn--deactivate:hover { background: rgba(239,68,68,0.15); border-color: rgba(239,68,68,0.3); }

.actionBtn--activate {
    background: rgba(16,185,129,0.08);
    color: #34d399;
    border: 1px solid rgba(16,185,129,0.15);
}
.actionBtn--activate:hover { background: rgba(16,185,129,0.15); border-color: rgba(16,185,129,0.3); }

/* ── Skeleton ────────────────────────────────────────────────────────────── */
.planCard--skeleton {
    pointer-events: none;
    background: var(--bg-faint, #f1f5f9);
    border-color: var(--border-soft, #e2e8f0);
}
.planCard--skeleton:hover {
    transform: none;
    box-shadow: none;
    border-color: var(--border-soft, #e2e8f0);
}
.sk {
    border-radius: 8px;
    /* theme-aware base: visible in both light and dark */
    background: color-mix(in srgb, currentColor 8%, transparent);
    background-image: linear-gradient(
        90deg,
        transparent 0%,
        color-mix(in srgb, currentColor 12%, transparent) 50%,
        transparent 100%
    );
    background-size: 300% 100%;
    animation: shimmer 1.5s ease-in-out infinite;
    color: inherit;
}
@keyframes shimmer {
    0%   { background-position: 100% 0; }
    100% { background-position: -100% 0; }
}
.sk--title  { height: 20px; width: 50%; }
.sk--price  { height: 38px; width: 38%; margin-top: 6px; border-radius: 6px; }
.sk--meta   { height: 28px; width: 65%; border-radius: 20px; }
.sk--btn    { height: 34px; width: 85%; margin-top: 6px; }

/* ── Empty ───────────────────────────────────────────────────────────────── */
.pEmpty {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 60px 20px;
    text-align: center;
    border: 1px dashed var(--admin-border, #1e293b);
    border-radius: 16px;
    gap: 12px;
}
.pEmpty__icon { width: 48px; height: 48px; color: #334155; }
.pEmpty__icon svg { width: 100%; height: 100%; }
.pEmpty__title { font-size: 17px; font-weight: 700; margin: 0; color: var(--admin-text, #e2e8f0); }
.pEmpty__sub { margin: 0; opacity: 0.5; font-size: 14px; }

/* ── Modal ───────────────────────────────────────────────────────────────── */
.pModal { max-width: 620px; width: 100%; }
.pModal--sm { max-width: 440px; }

/* Fix close button — global class has no font-size set */
.pModal__close {
    width: 34px !important;
    height: 34px !important;
    font-size: 16px !important;
    line-height: 1 !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    padding: 0 !important;
    flex-shrink: 0;
}
.pModal__titleWrap { display: flex; align-items: center; gap: 10px; }
.pModal__titleIcon {
    width: 32px; height: 32px;
    background: rgba(59,130,246,0.12);
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #60a5fa;
    flex-shrink: 0;
}
.pModal__titleIcon svg { width: 18px; height: 18px; }
.pModal__body { display: flex; flex-direction: column; gap: 18px; }
.pModal__footer { display: flex; gap: 10px; justify-content: flex-end; }
.pModal__confirmNote { margin-top: 8px; font-size: 13px; opacity: 0.65; line-height: 1.5; }

/* ── Fields ──────────────────────────────────────────────────────────────── */
.pField { display: flex; flex-direction: column; gap: 4px; }
.pField__row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.pField__label { font-size: 13px; font-weight: 600; color: var(--admin-text, #e2e8f0); }
.pField__req { color: #f87171; }
.pField__optional {
    font-size: 11px;
    font-weight: 500;
    color: var(--admin-text-muted, #64748b);
    background: rgba(100,116,139,0.12);
    border-radius: 4px;
    padding: 1px 6px;
    margin-left: 4px;
}
.pField__inputWrap { position: relative; display: flex; align-items: center; }
.pField__prefix {
    position: absolute;
    left: 11px;
    font-size: 14px;
    font-weight: 600;
    color: var(--text-secondary, var(--admin-text-muted, #64748b));
    pointer-events: none;
    z-index: 1;
}
.pField__prefix--sale { color: #34d399; }
.pField__unit {
    position: absolute;
    right: 11px;
    font-size: 13px;
    color: var(--admin-text-muted, #64748b);
    pointer-events: none;
}
.pField__input {
    width: 100%;
    padding: 9px 12px;
    background: var(--bg-faint, var(--admin-surface-2, #0f172a));
    border: 1px solid var(--border-soft, var(--admin-border, #1e293b));
    border-radius: 8px;
    color: var(--text-primary, var(--admin-text, #e2e8f0));
    font-size: 14px;
    transition: border-color 0.15s, box-shadow 0.15s;
    box-sizing: border-box;
}

/* Hide number input spin buttons — they inherit background and look broken in light mode */
.pField__input[type="number"]::-webkit-inner-spin-button,
.pField__input[type="number"]::-webkit-outer-spin-button {
    -webkit-appearance: none;
    margin: 0;
}
.pField__input[type="number"] { -moz-appearance: textfield; }
.pField__input:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59,130,246,0.15); }
.pField__textarea { resize: vertical; font-family: inherit; line-height: 1.5; min-height: 60px; }
.pField__input--prefixed { padding-left: 26px; }
.pField__input--sale:focus { border-color: #10b981; box-shadow: 0 0 0 3px rgba(16,185,129,0.12); }
.pField__input--err { border-color: #ef4444 !important; }
.pField__errMsg { font-size: 12px; color: #f87171; margin: 0; line-height: 1.4; }
.pField__hint { font-size: 12px; color: var(--admin-text-muted, #64748b); margin: 0; line-height: 1.4; }

/* Sale price error alert */
.pSaleAlert {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    background: rgba(239, 68, 68, 0.08);
    border: 1px solid rgba(239, 68, 68, 0.35);
    border-radius: 10px;
    padding: 11px 14px;
    color: #f87171;
    font-size: 13.5px;
    font-weight: 500;
    line-height: 1.45;
    animation: shakeIn 0.25s ease;
}
@keyframes shakeIn {
    0%   { transform: translateX(-4px); opacity: 0; }
    40%  { transform: translateX(3px); }
    70%  { transform: translateX(-2px); }
    100% { transform: translateX(0); opacity: 1; }
}
.pSaleAlert__icon {
    width: 16px;
    height: 16px;
    flex-shrink: 0;
    margin-top: 1px;
    color: #f87171;
}

/* Sale preview */
.pSalePreview {
    display: flex;
    align-items: center;
    gap: 12px;
    background: rgba(245,158,11,0.08);
    border: 1px solid rgba(245,158,11,0.2);
    border-radius: 10px;
    padding: 10px 14px;
}
.pSalePreview__badge {
    background: linear-gradient(135deg, #f59e0b, #d97706);
    color: #fff;
    font-size: 10px;
    font-weight: 800;
    padding: 3px 8px;
    border-radius: 5px;
    letter-spacing: 0.05em;
    flex-shrink: 0;
}
.pSalePreview__text { display: flex; align-items: center; gap: 8px; font-size: 14px; }
.pSalePreview__original { text-decoration: line-through; color: #64748b; }
.pSalePreview__arrow { color: #64748b; }
.pSalePreview__sale { color: #34d399; font-weight: 700; }
.pSalePreview__pct { color: #f59e0b; font-size: 12px; font-weight: 600; }

/* Toggle */
.pToggleField { }
.pToggle {
    display: flex;
    align-items: center;
    gap: 12px;
    cursor: pointer;
    user-select: none;
}
.pToggle__input { display: none; }
.pToggle__track {
    position: relative;
    width: 42px;
    height: 24px;
    background: rgba(100,116,139,0.3);
    border-radius: 999px;
    flex-shrink: 0;
    transition: background 0.2s;
    border: 1px solid rgba(100,116,139,0.3);
}
.pToggle--on .pToggle__track { background: rgba(59,130,246,0.4); border-color: #3b82f6; }
.pToggle__thumb {
    position: absolute;
    top: 2px;
    left: 2px;
    width: 18px;
    height: 18px;
    background: #94a3b8;
    border-radius: 50%;
    transition: transform 0.2s, background 0.2s;
    box-shadow: 0 1px 3px rgba(0,0,0,0.3);
}
.pToggle--on .pToggle__thumb { transform: translateX(18px); background: #3b82f6; }
.pToggle__label { font-size: 13.5px; display: flex; flex-direction: column; gap: 1px; }
.pToggle__label strong { font-weight: 700; color: var(--admin-text, #e2e8f0); }
.pToggle__label span { font-size: 12px; color: var(--admin-text-muted, #64748b); }

@media (max-width: 640px) {
    .pField__row { grid-template-columns: 1fr; }
    .planGrid { grid-template-columns: 1fr; }
}
</style>
