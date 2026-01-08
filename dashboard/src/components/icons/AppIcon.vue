<script setup lang="ts">
import IconDashboard from "./IconDashboard.vue";
import IconReports from "./IconReports.vue";
import IconUsage from "./IconUsage.vue";
import IconAccount from "./IconAccount.vue";
import IconCollapse from "./IconCollapse.vue";
import IconCalls from "./IconCalls.vue";
import IconMinutes from "./IconMinutes.vue";
import IconGenerated from "./IconGenerated.vue";
import IconJobs from "./IconJobs.vue";

type IconName =
    | "dashboard"
    | "reports"
    | "usage"
    | "account"
    | "collapse"
    | "calls"
    | "minutes"
    | "generated"
    | "jobs";

defineProps<{ name: IconName }>();
</script>

<template>
    <IconDashboard v-if="name === 'dashboard'" />
    <IconReports v-else-if="name === 'reports'" />
    <IconUsage v-else-if="name === 'usage'" />
    <IconAccount v-else-if="name === 'account'" />
    <IconCollapse v-else-if="name === 'collapse'" />
    <IconCalls v-else-if="name === 'calls'" />
    <IconMinutes v-else-if="name === 'minutes'" />
    <IconGenerated v-else-if="name === 'generated'" />
    <IconJobs v-else />
</template>
