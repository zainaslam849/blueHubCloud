<script setup lang="ts">
import StatePanel from "./StatePanel.vue";
</script>

<template>
    <StatePanel
        tone="danger"
        title="Something went wrong"
        description="We couldn’t load this data right now. Please try again, or come back in a moment."
        :primary-action="{ label: 'Try again', variant: 'primary' }"
        :secondary-action="{
            label: 'Go to dashboard',
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
                    d="M60 14c18 0 32 14 32 32S78 78 60 78 28 64 28 46 42 14 60 14Z"
                    stroke="currentColor"
                    stroke-width="2"
                    opacity="0.25"
                />
                <path
                    d="M46 46h28"
                    stroke="currentColor"
                    stroke-width="2"
                    stroke-linecap="round"
                    opacity="0.25"
                />
                <path
                    d="M60 30v10"
                    stroke="currentColor"
                    stroke-width="2"
                    stroke-linecap="round"
                    opacity="0.25"
                />
                <path
                    d="M60 58h.01"
                    stroke="currentColor"
                    stroke-width="3"
                    stroke-linecap="round"
                    opacity="0.25"
                />
            </svg>
        </template>
    </StatePanel>
</template>
