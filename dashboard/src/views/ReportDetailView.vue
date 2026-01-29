<script setup lang="ts">
import { computed, ref, onMounted } from "vue";
import { useRoute } from "vue-router";

interface ReportData {
    header: {
        id: number;
        company: { id: number; name: string };
        pbx_account: { id: number; name: string };
        week_range: {
            start: string;
            end: string;
            formatted: string;
        };
        generated_at: string;
        status: string;
    };
    executive_summary: string;
    metrics: {
        total_calls: number;
        answered_calls: number;
        missed_calls: number;
        answer_rate: number;
        calls_with_transcription: number;
        transcription_rate: number;
        total_call_duration_seconds: number;
        avg_call_duration_seconds: number;
        avg_call_duration_formatted: string;
        first_call_at: string;
        last_call_at: string;
    };
    category_breakdowns: {
        counts: Record<string, number>;
        details: Record<string, any>;
        top_dids: Array<{ did: string; calls: number }>;
        hourly_distribution: Record<number, number>;
    };
    insights: {
        ai_opportunities: any[];
        recommendations: any[];
    };
    ai_summary?: {
        ai_summary: string;
        recommendations: string[];
        risks: string[];
        automation_opportunities: string[];
    };
    exports: {
        pdf_available: boolean;
        csv_available: boolean;
    };
}

const route = useRoute();
const loading = ref(true);
const error = ref<string | null>(null);
const reportData = ref<ReportData | null>(null);

const reportId = computed(() => String(route.params.id ?? ""));

const categoryCountsArray = computed(() => {
    if (!reportData.value?.category_breakdowns.counts) return [];
    return Object.entries(reportData.value.category_breakdowns.counts)
        .map(([category, count]) => {
            const categoryName = category.includes("|")
                ? category.split("|")[1]
                : category;
            return {
                key: category,
                category: categoryName,
                count: count as number,
            };
        })
        .sort((a, b) => b.count - a.count);
});

const hourlyDistributionArray = computed(() => {
    if (!reportData.value?.category_breakdowns.hourly_distribution) return [];
    return Object.entries(
        reportData.value.category_breakdowns.hourly_distribution,
    )
        .filter(([, count]) => (count as number) > 0)
        .map(([hour, count]) => ({
            hour: parseInt(hour),
            count: count as number,
        }))
        .sort((a, b) => a.hour - b.hour);
});

const currentDate = computed(() => {
    const now = new Date();
    return now.toISOString().split("T")[0];
});

const extractCategoryFromOpp = (opp: string): string => {
    // Extract category name from format like "Property Enquiry (Availability/Pricing): ..."
    const match = opp.match(/^([^:]+)/);
    return match ? match[1].trim() : opp;
};

const extractDescriptionFromOpp = (opp: string): string => {
    // Extract description after the colon
    const match = opp.match(/:\s*(.+)$/);
    return match ? match[1].trim() : opp;
};

onMounted(async () => {
    try {
        const response = await fetch(
            `/admin/api/weekly-call-reports/${reportId.value}`,
        );
        if (!response.ok) {
            throw new Error(`Failed to load report: ${response.statusText}`);
        }
        const json = await response.json();
        reportData.value = json.data;
    } catch (err) {
        error.value =
            err instanceof Error ? err.message : "Failed to load report";
    } finally {
        loading.value = false;
    }
});
</script>

<template>
    <!-- Loading State -->
    <div v-if="loading" class="loading-container">
        <p>Loading report...</p>
    </div>

    <!-- Error State -->
    <div v-else-if="error" class="error-container">
        <p>{{ error }}</p>
    </div>

    <!-- Report Content -->
    <div v-else-if="reportData" class="report-container">
        <div class="container">
            <!-- Header -->
            <div class="header">
                <img
                    src="https://bluehubcloud.com.au/wp-content/uploads/2024/05/bluehubcloud-logo-transparent.png"
                    alt="BlueHub Cloud Logo"
                />
                <h1>Call Detail Record (CDR) Analysis Report</h1>
                <p>
                    Prepared by BlueHub Cloud |
                    {{
                        new Date(
                            reportData.header.generated_at,
                        ).toLocaleDateString()
                    }}
                </p>
            </div>

            <!-- Table of Contents -->
            <div class="toc">
                <h2>Table of Contents</h2>
                <ul>
                    <li><a href="#summary">Executive Summary</a></li>
                    <li><a href="#quantitative">Quantitative Analysis</a></li>
                    <li><a href="#breakdown">Key Category Breakdowns</a></li>
                    <li><a href="#insights">Insights & Recommendations</a></li>
                </ul>
            </div>

            <!-- Executive Summary -->
            <div id="summary">
                <h2>Executive Summary</h2>
                <p>
                    {{
                        reportData.executive_summary ||
                        "This report provides a detailed analysis of call detail records from the provided dataset. The analysis categorizes calls to identify key trends, peak call times, and common topics of inquiry. The primary goal is to uncover actionable insights that can help improve customer service, optimize resource allocation, and identify opportunities for automation."
                    }}
                </p>
            </div>

            <!-- Quantitative Analysis -->
            <div id="quantitative">
                <h2>Quantitative Analysis</h2>

                <!-- Call Category Counts -->
                <h3>Call Category Counts</h3>
                <table border="1" class="dataframe table">
                    <thead>
                        <tr style="text-align: right">
                            <th></th>
                            <th>count</th>
                        </tr>
                        <tr>
                            <th>category</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="cat in categoryCountsArray"
                            :key="cat.category"
                        >
                            <th>{{ cat.category }}</th>
                            <td>{{ cat.count }}</td>
                        </tr>
                    </tbody>
                </table>

                <!-- Top 10 Locations (by DID) -->
                <h3>Top 10 Locations (by DID)</h3>
                <table
                    v-if="reportData.category_breakdowns.top_dids.length > 0"
                    border="1"
                    class="dataframe table"
                >
                    <thead>
                        <tr style="text-align: right">
                            <th></th>
                            <th>count</th>
                        </tr>
                        <tr>
                            <th>did</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="(did, idx) in reportData.category_breakdowns
                                .top_dids"
                            :key="`did-${idx}`"
                        >
                            <th>{{ did.did }}</th>
                            <td>{{ did.calls }}</td>
                        </tr>
                    </tbody>
                </table>
                <p
                    v-else
                    style="font-style: italic; color: #666; margin-top: 1rem"
                >
                    No DID data available for this reporting period.
                </p>

                <!-- Hourly Call Distribution -->
                <h3>Hourly Call Distribution</h3>
                <table border="1" class="dataframe table">
                    <thead>
                        <tr style="text-align: right">
                            <th></th>
                            <th>count</th>
                        </tr>
                        <tr>
                            <th>date</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="hour in hourlyDistributionArray"
                            :key="`hour-${hour.hour}`"
                        >
                            <th>{{ hour.hour }}</th>
                            <td>{{ hour.count }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Key Category Breakdowns -->
            <div id="breakdown">
                <h2>Key Category Breakdowns</h2>

                <div
                    v-for="cat in categoryCountsArray"
                    :key="`breakdown-${cat.key}`"
                >
                    <h3>Category: {{ cat.category }}</h3>

                    <!-- Sub-Category Counts -->
                    <div
                        v-if="
                            reportData.category_breakdowns.details[cat.key]
                                ?.sub_categories &&
                            Object.keys(
                                reportData.category_breakdowns.details[cat.key]
                                    .sub_categories,
                            ).length > 0
                        "
                    >
                        <h4>Sub-Category Counts</h4>
                        <table border="1" class="dataframe table">
                            <thead>
                                <tr style="text-align: right">
                                    <th></th>
                                    <th>count</th>
                                </tr>
                                <tr>
                                    <th>sub_category</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="(subCount, subKey) in reportData
                                        .category_breakdowns.details[cat.key]
                                        .sub_categories"
                                    :key="`${cat.key}-${subKey}`"
                                >
                                    <th>
                                        {{
                                            (subKey as string).includes("|")
                                                ? (subKey as string).split(
                                                      "|",
                                                  )[1]
                                                : subKey
                                        }}
                                    </th>
                                    <td>{{ subCount }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Sample Calls -->
                    <div
                        v-if="
                            reportData.category_breakdowns.details[cat.key]
                                ?.sample_calls &&
                            reportData.category_breakdowns.details[cat.key]
                                .sample_calls.length > 0
                        "
                    >
                        <h4>Sample Calls</h4>
                        <table border="1" class="dataframe table">
                            <thead>
                                <tr style="text-align: right">
                                    <th>date</th>
                                    <th>did</th>
                                    <th>src</th>
                                    <th>text</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="(sample, sIdx) in reportData
                                        .category_breakdowns.details[cat.key]
                                        .sample_calls"
                                    :key="`${cat.key}-sample-${sIdx}`"
                                >
                                    <td>
                                        {{
                                            new Date(sample.date)
                                                .toISOString()
                                                .replace("T", " ")
                                                .substring(0, 19)
                                        }}
                                    </td>
                                    <td>{{ sample.did || "None" }}</td>
                                    <td>{{ sample.src || "—" }}</td>
                                    <td>
                                        {{
                                            sample.transcript
                                                ? sample.transcript.length > 150
                                                    ? sample.transcript.substring(
                                                          0,
                                                          150,
                                                      ) + "..."
                                                    : sample.transcript
                                                : "—"
                                        }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Insights & Recommendations -->
            <div id="insights">
                <h2>Insights & Recommendations</h2>

                <!-- AI Summary Section (if available) -->
                <div
                    v-if="reportData.ai_summary?.ai_summary"
                    class="ai-insights"
                >
                    <h4>AI Business Analysis</h4>
                    <p>{{ reportData.ai_summary.ai_summary }}</p>

                    <div
                        v-if="
                            reportData.ai_summary.automation_opportunities
                                .length > 0
                        "
                    >
                        <h4>Opportunities for AI Call Deflection</h4>
                        <p>
                            Based on the call volume and the nature of
                            inquiries, several categories present strong
                            opportunities for deflection using a Conversational
                            AI agent. Automating these routine queries can free
                            up staff to handle more complex issues, improving
                            overall efficiency.
                        </p>
                        <ul>
                            <li
                                v-for="(opp, idx) in reportData.ai_summary
                                    .automation_opportunities"
                                :key="`ai-opp-${idx}`"
                            >
                                <strong
                                    >{{ extractCategoryFromOpp(opp) }}:</strong
                                >
                                {{ extractDescriptionFromOpp(opp) }}
                            </li>
                        </ul>
                    </div>

                    <div
                        v-if="reportData.ai_summary.recommendations.length > 0"
                    >
                        <h4>Implementation Steps</h4>
                        <ol>
                            <li
                                v-for="(rec, idx) in reportData.ai_summary
                                    .recommendations"
                                :key="`ai-rec-${idx}`"
                            >
                                {{ rec }}
                            </li>
                        </ol>
                    </div>
                </div>

                <!-- Rule-based Insights (fallback) -->
                <div v-else-if="reportData.insights.recommendations.length > 0">
                    <h4>Key Recommendations</h4>
                    <ul>
                        <li
                            v-for="(rec, idx) in reportData.insights
                                .recommendations"
                            :key="`rec-${idx}`"
                        >
                            <strong>{{ rec.message }}</strong>
                        </li>
                    </ul>
                </div>

                <!-- AI Opportunities -->
                <div v-if="reportData.insights.ai_opportunities.length > 0">
                    <h4>Opportunities for Automation</h4>
                    <ul>
                        <li
                            v-for="(opp, idx) in reportData.insights
                                .ai_opportunities"
                            :key="`opp-${idx}`"
                        >
                            <strong>{{ opp.category }}</strong> ({{
                                opp.call_count
                            }}
                            calls, {{ opp.percentage }}%)
                            <p>{{ opp.reason }}</p>
                        </li>
                    </ul>
                </div>
            </div>

            <!-- Footer -->
            <div class="footer">
                <p>&copy; 2025 BlueHub Cloud. All Rights Reserved.</p>
            </div>
        </div>
    </div>
</template>

<style scoped>
body {
    font-family: "Arial", sans-serif;
    margin: 0;
    padding: 0;
    background-color: #f8fafc;
    color: #222;
}

.loading-container,
.error-container {
    padding: 2rem;
    text-align: center;
    background-color: #f8fafc;
}

.error-container {
    color: #dc2626;
    background-color: #fee2e2;
}

.report-container {
    background-color: #f8fafc;
    min-height: 100vh;
    padding: 20px;
}

.container {
    max-width: 960px;
    margin: 20px auto;
    padding: 20px;
    background-color: #fff;
    box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
}

.header {
    text-align: center;
    padding-bottom: 20px;
    border-bottom: 2px solid #3995c6;
}

.header img {
    max-width: 250px;
    margin-bottom: 10px;
}

h1,
h2,
h3,
h4 {
    color: #22234a;
}

h1 {
    font-size: 2.5em;
}

h2 {
    font-size: 2em;
    border-bottom: 1px solid #eee;
    padding-bottom: 10px;
    margin-top: 40px;
}

h3 {
    font-size: 1.5em;
    color: #3995c6;
    margin-top: 30px;
}

h4 {
    font-size: 1.2em;
}

table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 20px;
}

th,
td {
    padding: 12px;
    border: 1px solid #ddd;
    text-align: left;
}

th {
    background-color: #22234a;
    color: #fff;
}

tr:nth-child(even) {
    background-color: #f2f2f2;
}

.toc ul {
    list-style-type: none;
    padding: 0;
}

.toc ul li {
    margin-bottom: 0.5rem;
}

.toc ul li a {
    text-decoration: none;
    color: #3995c6;
    font-weight: bold;
}

.toc ul li a:hover {
    text-decoration: underline;
}

#summary p,
#insights p {
    line-height: 1.6;
    color: #374151;
}

#insights ul {
    line-height: 1.8;
}

#insights ul li {
    margin-bottom: 1rem;
}

#insights ul li strong {
    color: #1e40af;
}

#insights ul li p {
    margin: 0.5rem 0 0 0;
    color: #374151;
    font-size: 0.95rem;
}

#insights ol {
    line-height: 1.8;
}

#insights ol li {
    margin-bottom: 1rem;
}

.ai-insights {
    background-color: #f0f9ff;
    border: 1px solid #bfdbfe;
    border-radius: 0.5rem;
    padding: 1.5rem;
    margin: 2rem 0;
}

.ai-insights h4 {
    color: #0c4a6e;
}

.footer {
    text-align: center;
    margin-top: 40px;
    padding-top: 20px;
    border-top: 2px solid #3995c6;
    font-size: 0.9em;
    color: #777;
}

@media (max-width: 768px) {
    .container {
        padding: 10px;
        margin: 10px;
    }

    h1 {
        font-size: 1.8em;
    }

    h2 {
        font-size: 1.5em;
    }

    h3 {
        font-size: 1.3em;
    }

    table {
        font-size: 0.85rem;
    }

    th,
    td {
        padding: 8px;
    }
}
</style>
