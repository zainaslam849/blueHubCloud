<script setup lang="ts">
import StatePanel from "./StatePanel.vue";
</script>

<template>
    <StatePanel
        title="No reports yet"
        description="Once your first week of data is processed, reports will appear here with export links."
        :primary-action="{
            label: 'View dashboard',
            to: { name: 'dashboard' },
            variant: 'primary',
        }"
        :secondary-action="{
            label: 'Check usage',
            to: { name: 'usage' },
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
                    d="M28 22h52l12 12v28a8 8 0 0 1-8 8H28a8 8 0 0 1-8-8V30a8 8 0 0 1 8-8Z"
                    stroke="currentColor"
                    stroke-width="2"
                    opacity="0.25"
                />
                <path
                    d="M80 22v12a4 4 0 0 0 4 4h12"
                    stroke="currentColor"
                    stroke-width="2"
                    opacity="0.25"
                />
                <path
                    d="M34 42h40"
                    stroke="currentColor"
                    stroke-width="2"
                    stroke-linecap="round"
                    opacity="0.25"
                />
                <path
                    d="M34 52h28"
                    stroke="currentColor"
                    stroke-width="2"
                    stroke-linecap="round"
                    opacity="0.25"
                />
            </svg>
        </template>
    </StatePanel>
</template>
