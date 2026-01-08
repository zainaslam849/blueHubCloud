<template>
    <div class="admin-container admin-dashboard">
        <header class="admin-dashboard__header">
            <div>
                <p class="admin-dashboard__kicker">Overview</p>
                <h1 class="admin-dashboard__title">Admin Dashboard</h1>
            </div>

            <div class="admin-dashboard__status">
                <span class="admin-badge admin-badge--active">Active</span>
                <span class="admin-badge admin-badge--processing"
                    >Processing</span
                >
                <span class="admin-badge admin-badge--failed">Failed</span>
            </div>
        </header>

        <section class="admin-kpiGrid" aria-label="Key performance indicators">
            <article v-for="card in kpis" :key="card.key" class="admin-kpi">
                <div class="admin-kpi__top">
                    <div class="admin-kpi__icon" aria-hidden="true">
                        <svg
                            v-if="card.icon === 'calls'"
                            viewBox="0 0 24 24"
                            fill="none"
                            xmlns="http://www.w3.org/2000/svg"
                        >
                            <path
                                d="M7.5 10.2c1.4 2.7 3.6 4.9 6.3 6.3l2.1-2.1c.4-.4 1-.5 1.5-.3 1 .3 2.1.5 3.2.5.8 0 1.4.6 1.4 1.4V20c0 .8-.6 1.4-1.4 1.4C10.6 21.4 2.6 13.4 2.6 3.4 2.6 2.6 3.2 2 4 2h3.9c.8 0 1.4.6 1.4 1.4 0 1.1.2 2.2.5 3.2.2.5.1 1.1-.3 1.5L7.5 10.2Z"
                                stroke="currentColor"
                                stroke-width="1.8"
                                stroke-linejoin="round"
                            />
                        </svg>
                        <svg
                            v-else-if="card.icon === 'recordings'"
                            viewBox="0 0 24 24"
                            fill="none"
                            xmlns="http://www.w3.org/2000/svg"
                        >
                            <path
                                d="M9 7.5A3.5 3.5 0 0 1 12.5 4H16a3 3 0 0 1 3 3v10a3 3 0 0 1-3 3h-3.5A3.5 3.5 0 0 1 9 16.5v-9Z"
                                stroke="currentColor"
                                stroke-width="1.8"
                            />
                            <path
                                d="M6 9.2v5.6c0 .8-.9 1.3-1.6.8l-1.5-1.2a1 1 0 0 1-.4-.8v-3.2c0-.3.1-.6.4-.8l1.5-1.2c.7-.5 1.6 0 1.6.8Z"
                                stroke="currentColor"
                                stroke-width="1.8"
                                stroke-linejoin="round"
                            />
                        </svg>
                        <svg
                            v-else-if="card.icon === 'jobs'"
                            viewBox="0 0 24 24"
                            fill="none"
                            xmlns="http://www.w3.org/2000/svg"
                        >
                            <path
                                d="M8 7V6a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v1"
                                stroke="currentColor"
                                stroke-width="1.8"
                                stroke-linecap="round"
                            />
                            <path
                                d="M6 7h12a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V9a2 2 0 0 1 2-2Z"
                                stroke="currentColor"
                                stroke-width="1.8"
                            />
                            <path
                                d="M4 12h16"
                                stroke="currentColor"
                                stroke-width="1.8"
                                stroke-linecap="round"
                            />
                        </svg>
                        <svg
                            v-else
                            viewBox="0 0 24 24"
                            fill="none"
                            xmlns="http://www.w3.org/2000/svg"
                        >
                            <path
                                d="M16 20v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2"
                                stroke="currentColor"
                                stroke-width="1.8"
                                stroke-linecap="round"
                            />
                            <path
                                d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z"
                                stroke="currentColor"
                                stroke-width="1.8"
                            />
                            <path
                                d="M21 20v-2a4 4 0 0 0-3-3.87"
                                stroke="currentColor"
                                stroke-width="1.8"
                                stroke-linecap="round"
                            />
                            <path
                                d="M18 8a4 4 0 0 1 0 8"
                                stroke="currentColor"
                                stroke-width="1.8"
                                stroke-linecap="round"
                            />
                        </svg>
                    </div>

                    <div class="admin-kpi__meta">
                        <div class="admin-kpi__label">{{ card.label }}</div>
                        <div class="admin-kpi__value">{{ card.value }}</div>
                    </div>
                </div>

                <div class="admin-kpi__bottom">
                    <span class="admin-kpi__hint">{{ card.hint }}</span>
                    <span class="admin-kpi__badge" :class="card.badgeClass">{{
                        card.badge
                    }}</span>
                </div>
            </article>
        </section>

        <section class="admin-dashboard__grid">
            <section class="admin-panel" aria-label="Queue status">
                <header class="admin-panel__header">
                    <h2 class="admin-panel__title">Queue status</h2>
                    <span class="admin-panel__meta">Last 15 minutes</span>
                </header>

                <div class="admin-metricRows">
                    <div class="admin-metricRow">
                        <div class="admin-metricRow__label">Active workers</div>
                        <div class="admin-metricRow__value">4</div>
                        <span class="admin-badge admin-badge--active"
                            >Active</span
                        >
                    </div>
                    <div class="admin-metricRow">
                        <div class="admin-metricRow__label">
                            Jobs processing
                        </div>
                        <div class="admin-metricRow__value">12</div>
                        <span class="admin-badge admin-badge--processing"
                            >Processing</span
                        >
                    </div>
                    <div class="admin-metricRow">
                        <div class="admin-metricRow__label">Failed jobs</div>
                        <div class="admin-metricRow__value">1</div>
                        <span class="admin-badge admin-badge--failed"
                            >Failed</span
                        >
                    </div>
                </div>
            </section>

            <section class="admin-panel" aria-label="Recent activity">
                <header class="admin-panel__header">
                    <h2 class="admin-panel__title">Recent activity</h2>
                    <span class="admin-panel__meta">Static sample data</span>
                </header>

                <div class="admin-activity">
                    <div
                        v-for="row in recent"
                        :key="row.id"
                        class="admin-activityRow"
                    >
                        <div class="admin-activityRow__main">
                            <div class="admin-activityRow__title">
                                {{ row.title }}
                            </div>
                            <div class="admin-activityRow__sub">
                                {{ row.sub }}
                            </div>
                        </div>

                        <div class="admin-activityRow__right">
                            <span class="admin-badge" :class="row.badgeClass">{{
                                row.status
                            }}</span>
                            <div class="admin-activityRow__time">
                                {{ row.time }}
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </section>
    </div>
</template>

<script setup>
const kpis = [
    {
        key: "calls",
        label: "Calls",
        value: "12,480",
        hint: "Last 7 days",
        badge: "Active",
        badgeClass: "admin-badge admin-badge--active",
        icon: "calls",
    },
    {
        key: "recordings",
        label: "Recordings",
        value: "3,217",
        hint: "Stored securely",
        badge: "Processing",
        badgeClass: "admin-badge admin-badge--processing",
        icon: "recordings",
    },
    {
        key: "jobs",
        label: "Jobs",
        value: "98",
        hint: "Queued today",
        badge: "Failed",
        badgeClass: "admin-badge admin-badge--failed",
        icon: "jobs",
    },
    {
        key: "users",
        label: "Users",
        value: "142",
        hint: "Total admins",
        badge: "Active",
        badgeClass: "admin-badge admin-badge--active",
        icon: "users",
    },
];

const recent = [
    {
        id: "job-1842",
        title: "Import job #1842",
        sub: "company: BlueHub • type: calls",
        status: "Processing",
        badgeClass: "admin-badge--processing",
        time: "2m ago",
    },
    {
        id: "rec-9911",
        title: "Recording saved",
        sub: "duration: 03:14 • codec: opus",
        status: "Active",
        badgeClass: "admin-badge--active",
        time: "8m ago",
    },
    {
        id: "job-1839",
        title: "Transcription job #1839",
        sub: "source: recordings • language: en",
        status: "Failed",
        badgeClass: "admin-badge--failed",
        time: "12m ago",
    },
];
</script>
