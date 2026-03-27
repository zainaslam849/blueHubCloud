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

                <div
                    v-if="(company.peak_missed_times || []).length"
                    class="admin-reportSubsection"
                >
                    <h5 class="admin-reportSubsection__title">
                        Peak Missed Call Times
                    </h5>
                    <table class="admin-table admin-table--compact">
                        <thead>
                            <tr>
                                <th>Hour</th>
                                <th class="admin-table__num">Missed Calls</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="peak in company.peak_missed_times"
                                :key="peak.hour_label"
                            >
                                <td class="admin-mono">
                                    {{ peak.hour_label }}
                                </td>
                                <td class="admin-table__num admin-mono">
                                    {{ peak.missed_count }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
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
                                <td>
                                    <span
                                        :style="
                                            priorityBadgeStyle(row.priority)
                                        "
                                    >
                                        {{ (row.priority || "").toUpperCase() }}
                                    </span>
                                </td>
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
                            <th class="admin-table__num">Minutes</th>
                            <th>Time Sink Categories</th>
                            <th class="admin-table__num">Score</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in ringGroups" :key="row.ring_group">
                            <td>
                                <div style="font-weight: 500">
                                    {{ row.ring_group_name || row.ring_group }}
                                </div>
                                <div
                                    v-if="(row.top_categories || []).length"
                                    style="
                                        font-size: 0.82em;
                                        color: var(--color-muted, #6b7280);
                                        margin-top: 2px;
                                    "
                                >
                                    {{
                                        row.top_categories
                                            .slice(0, 2)
                                            .map((c) => c.name)
                                            .join(", ")
                                    }}
                                </div>
                            </td>
                            <td class="admin-table__num admin-mono">
                                {{ row.total_calls }}
                            </td>
                            <td class="admin-table__num admin-mono">
                                <span
                                    :style="
                                        row.missed_calls > 0
                                            ? 'color:#dc2626;font-weight:600'
                                            : ''
                                    "
                                    >{{ row.missed_calls }}</span
                                >
                            </td>
                            <td class="admin-table__num admin-mono">
                                {{ row.abandoned_calls }}
                            </td>
                            <td class="admin-table__num admin-mono">
                                {{ row.total_minutes }}
                            </td>
                            <td style="font-size: 0.85em">
                                <span
                                    v-if="
                                        (row.time_sink_categories || []).length
                                    "
                                >
                                    <span
                                        v-for="(cat, i) in (
                                            row.time_sink_categories || []
                                        ).slice(0, 2)"
                                        :key="i"
                                        >{{ cat.name
                                        }}{{
                                            cat.minutes
                                                ? ` (${cat.minutes}m)`
                                                : ""
                                        }}{{
                                            i <
                                            Math.min(
                                                (row.time_sink_categories || [])
                                                    .length,
                                                2,
                                            ) -
                                                1
                                                ? ", "
                                                : ""
                                        }}</span
                                    >
                                </span>
                                <span v-else class="admin-muted">—</span>
                            </td>
                            <td class="admin-table__num admin-mono">
                                {{ row.automation_priority_score }}
                            </td>
                        </tr>
                    </tbody>
                </table>
                <div v-else class="admin-muted">
                    <p style="margin: 0 0 8px 0">
                        Ring group analytics unavailable
                    </p>
                    <p style="margin: 0; font-size: 0.9em; line-height: 1.4">
                        This requires queue/department routing data from your
                        PBX server. Please verify that your PBX is configured to
                        capture and return queue assignments for calls.
                    </p>
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
                            <th>Top 3 Categories</th>
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
                            <td>
                                {{ topCategoriesLabel(row.top_categories) }}
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

                            <div class="admin-muted" style="margin-top: 8px">
                                Recent timeline:
                            </div>
                            <table
                                v-if="(card.timeline || []).length"
                                class="admin-table admin-table--mini"
                            >
                                <thead>
                                    <tr>
                                        <th>Time</th>
                                        <th class="admin-table__num">Sec</th>
                                        <th>Category</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="item in (
                                            card.timeline || []
                                        ).slice(0, 8)"
                                        :key="`${card.extension}-t-${item.call_id}`"
                                    >
                                        <td class="admin-mono">
                                            {{
                                                formatShortDateTime(
                                                    item.started_at,
                                                )
                                            }}
                                        </td>
                                        <td class="admin-table__num admin-mono">
                                            {{ item.duration_seconds }}
                                        </td>
                                        <td>
                                            {{ item.category_name || "—" }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                            <div v-else class="admin-muted">
                                No timeline entries
                            </div>

                            <div class="admin-muted" style="margin-top: 8px">
                                Top 5 examples:
                            </div>
                            <div v-if="(card.examples || []).length">
                                <div
                                    v-for="example in card.examples"
                                    :key="`${card.extension}-e-${example.call_id}`"
                                    style="margin-bottom: 8px"
                                >
                                    <div
                                        class="admin-mono"
                                        style="font-size: 0.85em"
                                    >
                                        {{
                                            formatShortDateTime(
                                                example.started_at,
                                            )
                                        }}
                                        <a
                                            :href="
                                                example.recording_or_transcript_link
                                            "
                                            style="margin-left: 6px"
                                            >Open</a
                                        >
                                    </div>
                                    <div>{{ example.snippet }}</div>
                                </div>
                            </div>
                            <div v-else class="admin-muted">
                                No transcript examples
                            </div>
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
                <div v-if="drilldown.length" class="admin-insightsList">
                    <div
                        v-for="row in drilldown"
                        :key="row.category_id"
                        class="admin-insightCard"
                        style="margin-bottom: 16px"
                    >
                        <div
                            class="admin-insightCard__header"
                            style="
                                display: flex;
                                justify-content: space-between;
                                align-items: baseline;
                                flex-wrap: wrap;
                                gap: 8px;
                            "
                        >
                            <strong>{{
                                row.category_name ||
                                `Category #${row.category_id}`
                            }}</strong>
                            <span
                                class="admin-mono"
                                style="
                                    font-size: 0.85em;
                                    color: var(--color-muted, #6b7280);
                                "
                                >{{ row.total_calls }} calls ·
                                {{ row.total_minutes ?? 0 }}m</span
                            >
                        </div>
                        <div class="admin-insightCard__body">
                            <div class="admin-kvGrid" style="margin-top: 8px">
                                <div class="admin-kv">
                                    <div class="admin-kv__k">
                                        Top Extensions
                                    </div>
                                    <div
                                        class="admin-kv__v admin-mono"
                                        style="font-size: 0.85em"
                                    >
                                        <span
                                            v-if="
                                                Object.keys(
                                                    row.extension_breakdown ||
                                                        {},
                                                ).length
                                            "
                                        >
                                            <span
                                                v-for="(
                                                    count, ext, i
                                                ) in getSortedBreakdown(
                                                    row.extension_breakdown,
                                                    3,
                                                )"
                                                :key="ext"
                                                >{{ ext }} ({{ count }}){{
                                                    i <
                                                    Object.keys(
                                                        getSortedBreakdown(
                                                            row.extension_breakdown,
                                                            3,
                                                        ),
                                                    ).length -
                                                        1
                                                        ? ", "
                                                        : ""
                                                }}</span
                                            >
                                        </span>
                                        <span v-else class="admin-muted"
                                            >—</span
                                        >
                                    </div>
                                </div>
                                <div class="admin-kv">
                                    <div class="admin-kv__k">Ring Groups</div>
                                    <div
                                        class="admin-kv__v admin-mono"
                                        style="font-size: 0.85em"
                                    >
                                        <span
                                            v-if="
                                                Object.keys(
                                                    row.ring_group_breakdown ||
                                                        {},
                                                ).length
                                            "
                                        >
                                            <span
                                                v-for="(
                                                    count, rg, i
                                                ) in getSortedBreakdown(
                                                    row.ring_group_breakdown,
                                                    3,
                                                )"
                                                :key="rg"
                                                >{{ rg }} ({{ count }}){{
                                                    i <
                                                    Object.keys(
                                                        getSortedBreakdown(
                                                            row.ring_group_breakdown,
                                                            3,
                                                        ),
                                                    ).length -
                                                        1
                                                        ? ", "
                                                        : ""
                                                }}</span
                                            >
                                        </span>
                                        <span v-else class="admin-muted"
                                            >—</span
                                        >
                                    </div>
                                </div>
                                <div class="admin-kv">
                                    <div class="admin-kv__k">Trend</div>
                                    <div class="admin-kv__v">
                                        {{
                                            trendLabel(
                                                row.trend_direction,
                                                row.trend_percentage_change,
                                            )
                                        }}
                                        <span
                                            class="admin-mono"
                                            style="
                                                font-size: 0.85em;
                                                margin-left: 4px;
                                            "
                                            >{{
                                                trendSparkline(row.daily_trend)
                                            }}</span
                                        >
                                    </div>
                                </div>
                                <div
                                    class="admin-kv"
                                    style="grid-column: 1 / -1"
                                >
                                    <div class="admin-kv__k">
                                        Suggested Automations
                                    </div>
                                    <div class="admin-kv__v">
                                        <ul
                                            v-if="
                                                (
                                                    row.suggested_automations ||
                                                    []
                                                ).length
                                            "
                                            style="
                                                margin: 0;
                                                padding-left: 16px;
                                            "
                                        >
                                            <li
                                                v-for="(
                                                    item, i
                                                ) in row.suggested_automations"
                                                :key="i"
                                            >
                                                {{
                                                    typeof item === "object"
                                                        ? item.suggestion
                                                        : item
                                                }}<span
                                                    v-if="
                                                        typeof item ===
                                                            'object' &&
                                                        item.impact
                                                    "
                                                    style="
                                                        color: var(
                                                            --color-muted,
                                                            #6b7280
                                                        );
                                                        font-size: 0.85em;
                                                    "
                                                >
                                                    — {{ item.impact }}</span
                                                >
                                            </li>
                                        </ul>
                                        <span v-else class="admin-muted"
                                            >—</span
                                        >
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
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

function topCategoriesLabel(categories) {
    if (!Array.isArray(categories) || categories.length === 0) return "—";
    return categories
        .slice(0, 3)
        .map((c) => c?.category_name || `#${c?.category_id ?? "?"}`)
        .join(", ");
}

function suggestedAutomationsLabel(items) {
    if (!Array.isArray(items) || items.length === 0) return "—";
    return items
        .slice(0, 3)
        .map((i) =>
            typeof i === "object" ? (i?.suggestion ?? i?.type ?? "—") : i,
        )
        .join(", ");
}

function trendSparkline(dailyTrend) {
    if (!dailyTrend || typeof dailyTrend !== "object") return "—";
    const values = Object.values(dailyTrend)
        .map((v) => Number(v))
        .filter((v) => Number.isFinite(v));
    if (values.length === 0) return "—";
    const bars = "▁▂▃▄▅▆▇█";
    const max = Math.max(...values, 1);
    return values
        .slice(-10)
        .map(
            (v) =>
                bars[
                    Math.min(
                        bars.length - 1,
                        Math.floor((v / max) * (bars.length - 1)),
                    )
                ],
        )
        .join("");
}

function formatShortDateTime(iso) {
    if (!iso) return "—";
    const date = new Date(iso);
    if (!Number.isFinite(date.getTime())) return "—";
    return date.toLocaleString();
}

function priorityBadgeStyle(priority) {
    const styles = {
        high: "background:#fee2e2;color:#dc2626",
        medium: "background:#fef9c3;color:#b45309",
        low: "background:#dcfce7;color:#16a34a",
    };
    const base =
        styles[priority?.toLowerCase?.()] ?? "background:#f3f4f6;color:#6b7280";
    return `${base};border-radius:4px;padding:1px 6px;font-size:0.8em;font-weight:600`;
}

function getSortedBreakdown(breakdown, topN) {
    if (!breakdown || typeof breakdown !== "object") return {};
    return Object.fromEntries(
        Object.entries(breakdown)
            .sort(([, a], [, b]) => Number(b) - Number(a))
            .slice(0, topN),
    );
}
</script>
