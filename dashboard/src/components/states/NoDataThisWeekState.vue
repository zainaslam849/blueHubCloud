<script setup lang="ts">
import StatePanel from "./StatePanel.vue";
</script>

<template>
    <StatePanel
        title="No data this week"
        description="We didn’t see any calls or processing activity for the selected week."
        :primary-action="{
            label: 'View reports',
            to: { name: 'reports' },
            variant: 'primary',
        }"
        :secondary-action="{
            label: 'Review account',
            to: { name: 'account' },
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
                    d="M26 58V26"
                    stroke="currentColor"
                    stroke-width="2"
                    stroke-linecap="round"
                    opacity="0.25"
                />
                <path
                    d="M26 58h70"
                    stroke="currentColor"
                    stroke-width="2"
                    stroke-linecap="round"
                    opacity="0.25"
                />
                <path
                    d="M36 50l12-10 12 8 18-18"
                    stroke="currentColor"
                    stroke-width="2"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    opacity="0.25"
                />
                <path
                    d="M70 32h18"
                    stroke="currentColor"
                    stroke-width="2"
                    stroke-linecap="round"
                    opacity="0.25"
                />
            </svg>
        </template>
    </StatePanel>
</template>
