<script setup lang="ts">
import PageHeader from "../components/ui/PageHeader.vue";
import WeeklyReportsList from "../components/reports/WeeklyReportsList.vue";
</script>

<template>
    <div>
        <PageHeader
            title="Reports"
            description="Weekly reporting snapshots and exports."
        />

        <WeeklyReportsList />
    </div>
</template>
