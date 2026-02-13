<script setup lang="ts">
import {
    LayoutDashboard,
    FileText,
    BarChart3,
    User,
    ChevronLeft,
    PhoneCall,
    Clock,
    FileCheck,
    Server,
    Plus,
    Search,
    X,
    Slack,
    Figma,
    Settings,
    LogOut,
    Building2,
} from "lucide-vue-next";

type IconName =
    | "dashboard"
    | "reports"
    | "usage"
    | "account"
    | "companies"
    | "pbx"
    | "collapse"
    | "calls"
    | "minutes"
    | "generated"
    | "jobs"
    | "plus"
    | "search"
    | "x"
    | "slack"
    | "figma"
    | "settings"
    | "logout";

const icons: Record<IconName, any> = {
    dashboard: LayoutDashboard,
    reports: FileText,
    usage: BarChart3,
    account: User,
    companies: Building2,
    pbx: Server,
    collapse: ChevronLeft,
    calls: PhoneCall,
    minutes: Clock,
    generated: FileCheck,
    jobs: Server,
    plus: Plus,
    search: Search,
    x: X,
    slack: Slack,
    figma: Figma,
    settings: Settings,
    logout: LogOut,
};

const props = defineProps<{ name: IconName }>();
</script>

<template>
    <component :is="icons[props.name]" aria-hidden="true" />
</template>
