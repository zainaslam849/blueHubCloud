<?php

namespace App\Services\Reports;

use App\Models\Call;
use App\Models\CompanyPbxAccount;
use App\Models\WeeklyCallReport;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * PHASE 9: INSIGHT-DRIVEN REPORTING
 * 
 * Redesigned report generation that shows:
 * - WHY people call (call intents)
 * - WHO handles it (extension/ring group performance)
 * - WHERE time is wasted (time sinks)
 * - WHAT to automate first (deflection opportunities)
 * - ESTIMATED cost savings
 * 
 * NOT just call counts.
 */
class InsightDrivenReportService
{
    private WeeklyCallReport $report;
    private Collection $calls;
    private CompanyPbxAccount $pbxAccount;

    public function __construct(WeeklyCallReport $report)
    {
        $this->report = $report;
        $this->pbxAccount = $report->companyPbxAccount;
        $this->calls = $this->loadCalls();
    }

    /**
     * Generate complete insight-driven report.
     *
     * @return array
     */
    public function generate(): array
    {
        return [
            'summary' => $this->generateSummary(),
            'call_intents' => $this->analyzeCallIntents(),
            'department_distribution' => $this->analyzeDepartments(),
            'automation_opportunities' => $this->findAutomationOpportunities(),
            'extension_performance' => $this->analyzeExtensionPerformance(),
            'ring_group_performance' => $this->analyzeRingGroupPerformance(),
            'time_sinks' => $this->identifyTimeSinks(),
            'repetitive_issues' => $this->findRepetitiveIssues(),
            'cost_analysis' => $this->calculateCostSavings(),
        ];
    }

    /**
     * Generate executive summary.
     *
     * @return array
     */
    private function generateSummary(): array
    {
        $totalCalls = $this->calls->count();
        $totalDuration = $this->calls->sum('duration_seconds');
        $avgDuration = $totalCalls > 0 ? $totalDuration / $totalCalls : 0;
        $callsWithTranscript = $this->calls->whereNotNull('transcript_text')->count();
        $automatable = $this->calls->where('deflection_confidence', '>=', 75)->count();

        return [
            'total_calls' => $totalCalls,
            'total_duration_minutes' => round($totalDuration / 60, 2),
            'average_call_duration_seconds' => round($avgDuration, 2),
            'calls_with_transcription' => $callsWithTranscript,
            'transcription_coverage' => $totalCalls > 0 ? round(($callsWithTranscript / $totalCalls) * 100, 1) : 0,
            'automatable_calls' => $automatable,
            'automation_potential_percent' => $totalCalls > 0 ? round(($automatable / $totalCalls) * 100, 1) : 0,
        ];
    }

    /**
     * Analyze call intents: WHY people called.
     *
     * @return array
     */
    private function analyzeCallIntents(): array
    {
        // Group by call_intent
        $byIntent = $this->calls
            ->whereNotNull('call_intent')
            ->groupBy('call_intent')
            ->map(function (Collection $group) {
                $totalDuration = $group->sum('duration_seconds');
                $avgDuration = $totalDuration / $group->count();

                return [
                    'intent' => $group->first()->call_intent,
                    'count' => $group->count(),
                    'percent' => round(($group->count() / $this->calls->count()) * 100, 1),
                    'total_minutes' => round($totalDuration / 60, 2),
                    'avg_duration_seconds' => round($avgDuration, 2),
                    'automatable' => $group->where('deflection_confidence', '>=', 75)->count(),
                    'top_departments' => $group
                        ->groupBy('inferred_department')
                        ->map(fn(Collection $deptGroup) => $deptGroup->count())
                        ->take(3),
                ];
            })
            ->sortByDesc('count')
            ->values();

        return $byIntent->toArray();
    }

    /**
     * Analyze department distribution.
     *
     * @return array
     */
    private function analyzeDepartments(): array
    {
        return $this->calls
            ->whereNotNull('inferred_department')
            ->groupBy('inferred_department')
            ->map(function (Collection $group) {
                $totalDuration = $group->sum('duration_seconds');

                return [
                    'department' => $group->first()->inferred_department,
                    'calls' => $group->count(),
                    'percent' => round(($group->count() / $this->calls->count()) * 100, 1),
                    'total_minutes' => round($totalDuration / 60, 2),
                    'avg_duration_seconds' => round($totalDuration / $group->count(), 2),
                    'avg_deflection_score' => round($group->avg('deflection_confidence') ?? 0, 1),
                ];
            })
            ->sortByDesc('calls')
            ->values()
            ->toArray();
    }

    /**
     * Find automation opportunities: WHAT to automate first.
     *
     * Ranked by impact (frequency + savings).
     *
     * @return array
     */
    private function findAutomationOpportunities(): array
    {
        return $this->calls
            ->where('deflection_confidence', '>=', 50)
            ->groupBy('call_intent')
            ->map(function (Collection $group) {
                $totalChance = $group->sum('deflection_confidence') / $group->count();
                $totalMinutes = $group->sum('duration_seconds') / 60;
                $impactScore = ($group->count() * $totalChance / 100) * $totalMinutes;

                return [
                    'intent' => $group->first()->call_intent,
                    'recommended_automation' => json_decode($group->first()->suggested_automation ?? '[]', true),
                    'volume' => $group->count(),
                    'avg_deflection_confidence' => round($totalChance, 1),
                    'total_minutes_affected' => round($totalMinutes, 1),
                    'impact_score' => round($impactScore, 2),
                    'estimated_annual_calls' => $group->count() * 52,
                    'estimated_agent_hours_saveable_weekly' => round($totalMinutes / 60, 1),
                ];
            })
            ->sortByDesc('impact_score')
            ->values()
            ->toArray();
    }

    /**
     * Analyze extension performance: WHO handles it.
     *
     * @return array
     */
    private function analyzeExtensionPerformance(): array
    {
        return $this->calls
            ->whereNotNull('answered_by_extension')
            ->groupBy('answered_by_extension')
            ->map(function (Collection $group) {
                $totalDuration = $group->sum('duration_seconds');

                return [
                    'extension' => $group->first()->answered_by_extension,
                    'calls_answered' => $group->count(),
                    'total_minutes' => round($totalDuration / 60, 2),
                    'avg_duration_seconds' => round($totalDuration / $group->count(), 2),
                    'repetitive_issues_count' => $group->where('repetitive_flag', true)->count(),
                    'avg_deflection_confidence' => round($group->avg('deflection_confidence') ?? 0, 1),
                    'top_intent' => $group
                        ->groupBy('call_intent')
                        ->sortByDesc(fn(Collection $c) => $c->count())
                        ->keys()
                        ->first(),
                ];
            })
            ->sortByDesc('calls_answered')
            ->values()
            ->toArray();
    }

    /**
     * Analyze ring group performance.
     *
     * @return array
     */
    private function analyzeRingGroupPerformance(): array
    {
        return $this->calls
            ->whereNotNull('ring_group')
            ->groupBy('ring_group')
            ->map(function (Collection $group) {
                $totalDuration = $group->sum('duration_seconds');
                $avgDuration = $totalDuration / $group->count();

                return [
                    'ring_group' => $group->first()->ring_group,
                    'calls' => $group->count(),
                    'total_minutes' => round($totalDuration / 60, 2),
                    'avg_duration_seconds' => round($avgDuration, 2),
                    'extensions_handling' => $group->pluck('answered_by_extension')->unique()->count(),
                    'top_intents' => $group
                        ->groupBy('call_intent')
                        ->map(fn(Collection $c) => $c->count())
                        ->sortDesc()
                        ->take(3),
                    'avg_deflection_confidence' => round($group->avg('deflection_confidence') ?? 0, 1),
                ];
            })
            ->sortByDesc('calls')
            ->values()
            ->toArray();
    }

    /**
     * Identify time sinks: WHERE time is wasted.
     *
     * @return array
     */
    private function identifyTimeSinks(): array
    {
        // Calls with high duration but low automation potential
        return $this->calls
            ->where('duration_seconds', '>', 300) // Over 5 minutes
            ->where('deflection_confidence', '<', 50)
            ->sortByDesc('duration_seconds')
            ->take(10)
            ->map(fn(Call $call) => [
                'call_id' => $call->id,
                'duration_seconds' => $call->duration_seconds,
                'duration_minutes' => round($call->duration_seconds / 60, 2),
                'intent' => $call->call_intent ?? 'unknown',
                'department' => $call->inferred_department ?? 'unknown',
                'extension' => $call->answered_by_extension ?? 'unknown',
                'reason' => 'Complex issue requiring extended human support',
            ])
            ->values()
            ->toArray();
    }

    /**
     * Find repetitive issues that could benefit from focused solutions.
     *
     * @return array
     */
    private function findRepetitiveIssues(): array
    {
        return $this->calls
            ->where('repetitive_flag', true)
            ->groupBy('call_intent')
            ->map(function (Collection $group) {
                return [
                    'intent' => $group->first()->call_intent,
                    'occurrences' => $group->count(),
                    'total_minutes' => round($group->sum('duration_seconds') / 60, 1),
                    'recommendation' => "Create knowledge base article or automation for {$group->first()->call_intent}",
                    'expected_impact' => "Prevent " . min(($group->count() % 3) + 1, 5) . "-10 similar future calls",
                ];
            })
            ->sortByDesc('occurrences')
            ->values()
            ->toArray();
    }

    /**
     * Calculate cost savings potential.
     *
     * @return array
     */
    private function calculateCostSavings(): array
    {
        $automatable = $this->calls->where('deflection_confidence', '>=', 75);
        $savableMinutes = $automatable->sum('duration_seconds') / 60;

        // Use industry average agent cost
        $agentCostPerHour = env('AGENT_COST_PER_HOUR', 35); // $35/hour default
        $agentCostPerMinute = $agentCostPerHour / 60;

        $weeklySavings = $savableMinutes * $agentCostPerMinute;
        $annualSavings = $weeklySavings * 52;

        return [
            'automatable_calls_this_week' => $automatable->count(),
            'savable_agent_minutes' => round($savableMinutes, 1),
            'savable_agent_hours' => round($savableMinutes / 60, 1),
            'weekly_savings_estimate' => round($weeklySavings, 2),
            'annual_savings_estimate' => round($annualSavings, 2),
            'assumptions' => [
                'agent_cost_per_hour' => $agentCostPerHour,
                'deflection_confidence_threshold' => 75,
                'weeks_per_year' => 52,
            ],
        ];
    }

    /**
     * Load calls for this report period.
     *
     * @return Collection
     */
    private function loadCalls(): Collection
    {
        return Call::where('company_pbx_account_id', $this->pbxAccount->id)
            ->where('server_id', $this->pbxAccount->server_id)
            ->whereBetween('started_at', [
                $this->report->period_start,
                $this->report->period_end,
            ])
            ->get();
    }
}
