<script setup lang="ts">
import StatePanel from "./StatePanel.vue";
</script>

<template>
    <StatePanel
        tone="danger"
        title="Permission denied"
        description="You don’t have access to this area. If you think this is a mistake, request access from an admin."
        :primary-action="{
            label: 'Request access',
            to: { name: 'account' },
            variant: 'primary',
        }"
        :secondary-action="{
            label: 'Back to dashboard',
            to: { name: 'dashboard' },
            variant: 'ghost',
        }"
    >
        <template #illustration>
            <svg
                viewBox="0 0 120 80"
                fill="none"
                xmlns="http://www.w3.org/2000/svg"
                width="120"
                height="80"
            >
                <path
                    d="M44 36v-6a16 16 0 0 1 32 0v6"
                    stroke="currentColor"
                    stroke-width="2"
                    stroke-linecap="round"
                    opacity="0.25"
                />
                <path
                    d="M38 36h44a8 8 0 0 1 8 8v18a8 8 0 0 1-8 8H38a8 8 0 0 1-8-8V44a8 8 0 0 1 8-8Z"
                    stroke="currentColor"
                    stroke-width="2"
                    opacity="0.25"
                />
                <path
                    d="M60 50v8"
                    stroke="currentColor"
                    stroke-width="2"
                    stroke-linecap="round"
                    opacity="0.25"
                />
                <path
                    d="M60 62h.01"
                    stroke="currentColor"
                    stroke-width="3"
                    stroke-linecap="round"
                    opacity="0.25"
                />
            </svg>
        </template>
    </StatePanel>
</template>
