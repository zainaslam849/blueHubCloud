<template>
    <div v-if="isOpen" class="admin-modal-overlay" @click.self="close">
        <div class="admin-modal admin-modal--confirm" @click.stop>
            <div class="admin-modal__header">
                <h2 class="admin-modal__title">Delete Call?</h2>
                <button
                    type="button"
                    class="admin-modal__close"
                    @click="close"
                    aria-label="Close dialog"
                >
                    ✕
                </button>
            </div>

            <div class="admin-modal__body">
                <p class="admin-confirm-text">
                    Are you sure you want to delete call
                    <strong>{{ callId }}</strong
                    >?
                </p>
                <p class="admin-confirm-warning">
                    <strong>⚠ This action cannot be undone.</strong>
                    The call will be marked as deleted but may still exist in
                    backups.
                </p>
                <p v-if="company" class="admin-confirm-meta">
                    <span class="admin-confirm-label">Company:</span>
                    <span>{{ company }}</span>
                </p>
            </div>

            <div class="admin-modal__footer">
                <button
                    type="button"
                    class="admin-btn admin-btn--secondary"
                    @click="close"
                    :disabled="loading"
                >
                    Cancel
                </button>
                <button
                    type="button"
                    class="admin-btn admin-btn--danger"
                    @click="confirm"
                    :disabled="loading"
                >
                    <span v-if="loading">Deleting...</span>
                    <span v-else>Delete Call</span>
                </button>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, computed } from "vue";

const props = defineProps({
    isOpen: {
        type: Boolean,
        required: true,
    },
    callId: {
        type: String,
        required: true,
    },
    company: {
        type: String,
        default: null,
    },
    loading: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(["close", "confirm"]);

function close() {
    emit("close");
}

function confirm() {
    emit("confirm");
}
</script>

<style scoped>
.admin-modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.5);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 100;
    padding: 16px;
}

.admin-modal {
    background: var(--bg-surface);
    border-radius: 16px;
    box-shadow: var(--shadow-elev-3);
    max-width: 400px;
    width: 100%;
    max-height: 90vh;
    overflow-y: auto;
    border: 1px solid var(--border-soft);
}

.admin-modal__header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 20px;
    border-bottom: 1px solid var(--border-faint);
}

.admin-modal__title {
    font-size: 18px;
    font-weight: 700;
    color: var(--text-primary);
    margin: 0;
}

.admin-modal__close {
    appearance: none;
    background: transparent;
    border: none;
    color: var(--text-muted);
    font-size: 24px;
    cursor: pointer;
    padding: 0;
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 8px;
    transition: all 140ms ease;
}

.admin-modal__close:hover {
    background: var(--bg-surface-2);
    color: var(--text-primary);
}

.admin-modal__body {
    padding: 20px;
    color: var(--text-secondary);
    font-size: 14px;
    line-height: 1.6;
}

.admin-confirm-text {
    margin: 0 0 12px 0;
    color: var(--text-primary);
}

.admin-confirm-warning {
    margin: 12px 0;
    padding: 12px;
    background: rgba(220, 38, 38, 0.1);
    border-left: 3px solid #dc2626;
    border-radius: 6px;
    color: #991b1b;
}

.admin-confirm-meta {
    margin: 12px 0 0 0;
    font-size: 13px;
    color: var(--text-secondary);
}

.admin-confirm-label {
    font-weight: 600;
    color: var(--text-primary);
}

.admin-modal__footer {
    display: flex;
    gap: 10px;
    padding: 16px 20px;
    border-top: 1px solid var(--border-faint);
    justify-content: flex-end;
}

.admin-btn {
    padding: 8px 16px;
    border-radius: 8px;
    border: 1px solid var(--border-soft);
    background: var(--bg-surface);
    color: var(--text-primary);
    font-weight: 600;
    font-size: 14px;
    cursor: pointer;
    transition: all 140ms ease;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.admin-btn:hover:not(:disabled) {
    background: var(--bg-surface-1);
    border-color: var(--border-primary);
    transform: translateY(-1px);
}

.admin-btn:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

.admin-btn--secondary {
    border-color: var(--border-soft);
}

.admin-btn--danger {
    background: #dc2626;
    color: white;
    border-color: #991b1b;
}

.admin-btn--danger:hover:not(:disabled) {
    background: #b91c1c;
    border-color: #7f1d1d;
}
</style>
