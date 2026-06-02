<?php

namespace App\Services;

use App\Jobs\GenerateWeeklyPbxReportsJob;
use App\Models\CompanyMinuteBalance;
use App\Models\WeeklyCallReport;
use Illuminate\Support\Facades\Log;

class PendingMinutesReportService
{
    /**
     * After a company receives more minutes, convert any parked reports.
     *
     * Processes in chronological order (oldest first). For each eligible
     * report we mark it as 'pending' (removing the pending_minutes block)
     * and re-dispatch the job. The job's gating block then handles the
     * actual atomic minute deduction when it runs.
     *
     * We do NOT pre-deduct here to avoid double-deduction: the job always
     * owns the single deduction. We stop dispatching once the current
     * balance can no longer cover the next report.
     */
    public function convertPending(int $companyId): void
    {
        $pendingReports = WeeklyCallReport::where('company_id', $companyId)
            ->where('status', 'pending_minutes')
            ->orderBy('week_start_date')
            ->get();

        if ($pendingReports->isEmpty()) {
            return;
        }

        foreach ($pendingReports as $report) {
            $minutesRequired = (int) ceil((int) $report->total_call_duration_seconds / 60);

            $balance = CompanyMinuteBalance::where('company_id', $companyId)->first();

            if (! $balance || $balance->available_minutes < $minutesRequired) {
                Log::info('PendingMinutesReportService: balance insufficient, stopping', [
                    'company_id' => $companyId,
                    'report_id' => $report->id,
                    'minutes_required' => $minutesRequired,
                    'available' => $balance?->available_minutes ?? 0,
                ]);
                break;
            }

            // Mark the report out of pending_minutes so it won't be picked up again
            // by this service on a subsequent call before the job runs.
            // The job's gating block handles the actual minute deduction.
            $report->update(['status' => 'pending']);

            GenerateWeeklyPbxReportsJob::dispatch(
                fromDate: $report->week_start_date->toDateString(),
                toDate: $report->week_end_date->toDateString(),
                companyId: $companyId,
            );

            Log::info('PendingMinutesReportService: dispatched regeneration', [
                'company_id' => $companyId,
                'report_id' => $report->id,
                'week_start_date' => $report->week_start_date->toDateString(),
                'minutes_required' => $minutesRequired,
            ]);
        }
    }
}
