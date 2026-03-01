<template>
    <BaseCard
        title="Automation Priority Views"
        description="Company, ring group, extension, and category drill-down dashboards"
        variant="glass"
    >
        <div v-if="loading" class="admin-skeletonLines">
            <div class="admin-skeleton admin-skeleton--line" />
            <div class="admin-skeleton admin-skeleton--line" />
            <div class="admin-skeleton admin-skeleton--line" />
        </div>

        <div v-else-if="!hasData" class="admin-empty">
            <div class="admin-empty__title">No advanced analytics yet</div>
            <div class="admin-empty__desc">
                Run weekly report generation with categorized calls to populate
                these views.
            </div>
        </div>

        <div v-else class="admin-reportSections">
            <div class="admin-reportSection">
                <h4 class="admin-reportSection__title">1) Company Dashboard</h4>
                <div class="admin-kvGrid">
                    <div class="admin-kv">
                        <div class="admin-kv__k">Total Calls</div>
                        <div class="admin-kv__v admin-mono">
                            {{ companySummary.total_calls ?? 0 }}
                        </div>
                    </div>
                    <div class="admin-kv">
                        <div class="admin-kv__k">Total Minutes</div>
                        <div class="admin-kv__v admin-mono">
                            {{ companySummary.total_minutes ?? 0 }}
                        </div>
                    </div>
                    <div class="admin-kv">
                        <div class="admin-kv__k">Missed Calls</div>
                        <div class="admin-kv__v admin-mono">
                            {{ companySummary.missed_calls ?? 0 }}
                        </div>
                    </div>
                    <div class="admin-kv">
                        <div class="admin-kv__k">Trend vs Last Period</div>
                        <div class="admin-kv__v">
                            <span v-if="trend.has_previous">
                                Calls {{ trend.calls_delta > 0 ? "+" : ""
                                }}{{ trend.calls_delta ?? 0 }}
                                <span
                                    v-if="trend.calls_delta_pct !== null"
                                    class="admin-muted"
                                >
                                    ({{ trend.calls_delta_pct > 0 ? "+" : ""
                                    }}{{ trend.calls_delta_pct }}%)</span
                                >
                            </span>
                            <span v-else>—</span>
                        </div>
                    </div>
                </div>

                <div class="admin-reportSubsection">
                    <h5 class="admin-reportSubsection__title">
                        Top Categories
                    </h5>
                    <table
                        class="admin-table admin-table--compact"
                        v-if="(company.top_categories || []).length"
                    >
                        <thead>
                            <tr>
                                <th>Category</th>
                                <th class="admin-table__num">Calls</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in company.top_categories"
                                :key="row.name"
                            >
                                <td>{{ row.name }}</td>
                                <td class="admin-table__num admin-mono">
                                    {{ row.count }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <div v-else class="admin-muted">No category data</div>
                </div>

                <div class="admin-reportSubsection">
                    <h5 class="admin-reportSubsection__title">
                        Top Automation Opportunities
                    </h5>
                    <table
                        class="admin-table admin-table--compact"
                        v-if="
                            (company.top_automation_opportunities || []).length
                        "
                    >
                        <thead>
                            <tr>
                                <th>Category</th>
                                <th>Priority</th>
                                <th class="admin-table__num">Calls</th>
                                <th class="admin-table__num">Minutes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in company.top_automation_opportunities"
                                :key="`${row.category_id}-${row.priority}`"
                            >
                                <td>
                                    {{
                                        row.category_name ||
                                        `Category #${row.category_id}`
                                    }}
                                </td>
                                <td>{{ row.priority }}</td>
                                <td class="admin-table__num admin-mono">
                                    {{ row.total_calls }}
                                </td>
                                <td class="admin-table__num admin-mono">
                                    {{ row.total_minutes }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <div v-else class="admin-muted">
                        No automation candidates yet
                    </div>
                </div>
            </div>

            <div class="admin-reportSection">
                <h4 class="admin-reportSection__title">
                    2) Ring Group Dashboard
                </h4>
                <table
                    class="admin-table admin-table--compact"
                    v-if="ringGroups.length"
                >
                    <thead>
                        <tr>
                            <th>Ring Group / Queue</th>
                            <th class="admin-table__num">Calls</th>
                            <th class="admin-table__num">Missed</th>
                            <th class="admin-table__num">Abandoned</th>
                            <th class="admin-table__num">Automation Score</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in ringGroups" :key="row.ring_group">
                            <td>{{ row.ring_group_name || row.ring_group }}</td>
                            <td class="admin-table__num admin-mono">
                                {{ row.total_calls }}
                            </td>
                            <td class="admin-table__num admin-mono">
                                {{ row.missed_calls }}
                            </td>
                            <td class="admin-table__num admin-mono">
                                {{ row.abandoned_calls }}
                            </td>
                            <td class="admin-table__num admin-mono">
                                {{ row.automation_priority_score }}
                            </td>
                        </tr>
                    </tbody>
                </table>
                <div v-else class="admin-muted">
                    No ring group analytics yet
                </div>
            </div>

            <div class="admin-reportSection">
                <h4 class="admin-reportSection__title">
                    3) Extension Leaderboard
                </h4>
                <table
                    class="admin-table admin-table--compact"
                    v-if="extensions.length"
                >
                    <thead>
                        <tr>
                            <th>Extension</th>
                            <th class="admin-table__num">Answered</th>
                            <th class="admin-table__num">Minutes</th>
                            <th class="admin-table__num">Repetitive %</th>
                            <th class="admin-table__num">Impact Score</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in extensions" :key="row.extension">
                            <td>{{ row.extension }}</td>
                            <td class="admin-table__num admin-mono">
                                {{ row.calls_answered }}
                            </td>
                            <td class="admin-table__num admin-mono">
                                {{ row.total_minutes }}
                            </td>
                            <td class="admin-table__num admin-mono">
                                {{ row.repetitive_percentage }}
                            </td>
                            <td class="admin-table__num admin-mono">
                                {{ row.automation_impact_score }}
                            </td>
                        </tr>
                    </tbody>
                </table>
                <div v-else class="admin-muted">
                    No extension performance data yet
                </div>
            </div>

            <div class="admin-reportSection">
                <h4 class="admin-reportSection__title">
                    4) Extension Scorecards
                </h4>
                <div v-if="scorecards.length" class="admin-insightsList">
                    <div
                        v-for="card in scorecards"
                        :key="card.extension"
                        class="admin-insightCard"
                    >
                        <div class="admin-insightCard__header">
                            <strong>Extension {{ card.extension }}</strong>
                        </div>
                        <div class="admin-insightCard__body">
                            <div class="admin-muted">
                                Top automation candidates:
                            </div>
                            <ul>
                                <li
                                    v-for="(
                                        c, idx
                                    ) in card.top_automation_candidates || []"
                                    :key="`${card.extension}-${idx}`"
                                >
                                    {{
                                        c.category_name ||
                                        `Category #${c.category_id}`
                                    }}
                                </li>
                            </ul>
                            <div class="admin-muted">Recommended actions:</div>
                            <ul>
                                <li
                                    v-for="(
                                        action, idx
                                    ) in card.recommended_actions || []"
                                    :key="`${card.extension}-a-${idx}`"
                                >
                                    {{ action }}
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
                <div v-else class="admin-muted">
                    No extension scorecards yet
                </div>
            </div>

            <div class="admin-reportSection">
                <h4 class="admin-reportSection__title">
                    5) Category Drill-down
                </h4>
                <table
                    class="admin-table admin-table--compact"
                    v-if="drilldown.length"
                >
                    <thead>
                        <tr>
                            <th>Category</th>
                            <th class="admin-table__num">Calls</th>
                            <th>Top Extension</th>
                            <th>Top Ring Group</th>
                            <th>Trend</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in drilldown" :key="row.category_id">
                            <td>
                                {{
                                    row.category_name ||
                                    `Category #${row.category_id}`
                                }}
                            </td>
                            <td class="admin-table__num admin-mono">
                                {{ row.total_calls }}
                            </td>
                            <td>{{ row.top_extension || "—" }}</td>
                            <td>{{ row.top_ring_group || "—" }}</td>
                            <td>
                                {{
                                    trendLabel(
                                        row.trend_direction,
                                        row.trend_percentage_change,
                                    )
                                }}
                            </td>
                        </tr>
                    </tbody>
                </table>
                <div v-else class="admin-muted">
                    No category drill-down analytics yet
                </div>
            </div>
        </div>
    </BaseCard>
</template>

<script setup>
import { computed } from "vue";
import { BaseCard } from "../base";

const props = defineProps({
    loading: { type: Boolean, default: false },
    advanced: { type: Object, default: null },
});

const company = computed(() => props.advanced?.company_dashboard ?? {});
const companySummary = computed(() => company.value.summary ?? {});
const trend = computed(
    () => company.value.trend_vs_last_period ?? { has_previous: false },
);
const ringGroups = computed(() => props.advanced?.ring_group_dashboard ?? []);
const extensions = computed(() => props.advanced?.extension_leaderboard ?? []);
const scorecards = computed(() => props.advanced?.extension_scorecards ?? []);
const drilldown = computed(() => props.advanced?.category_drilldown ?? []);

const hasData = computed(() => {
    return (
        (company.value.top_categories || []).length > 0 ||
        ringGroups.value.length > 0 ||
        extensions.value.length > 0 ||
        scorecards.value.length > 0 ||
        drilldown.value.length > 0
    );
});

function trendLabel(direction, pct) {
    if (direction > 0) return `Up ${pct ?? 0}%`;
    if (direction < 0) return `Down ${Math.abs(pct ?? 0)}%`;
    return "Stable";
}
</script>
