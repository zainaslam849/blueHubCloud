<?php

namespace App\Jobs;

use App\Services\ReportGeneratorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class GenerateWeeklyReport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * @var int[]
     */
    public array $backoff = [5, 15, 60];

    public function __construct(
        public int $weeklyReportId,
    ) {
    }

    public function handle(ReportGeneratorService $reportGeneratorService): void
    {
        try {
            $shouldGenerate = DB::transaction(function () {
                $report = DB::table('weekly_call_reports')
                    ->lockForUpdate()
                    ->where('id', $this->weeklyReportId)
                    ->first();

                if (! $report) {
                    return false;
                }

                if (($report->status ?? null) === 'completed') {
                    return false;
                }

                // Prevent duplicate work if another worker is already generating.
                if (($report->status ?? null) === 'generating') {
                    return false;
                }

                DB::table('weekly_call_reports')
                    ->where('id', $this->weeklyReportId)
                    ->update([
                        'status' => 'generating',
                        'error_message' => null,
                        'updated_at' => now(),
                    ]);

                return true;
            });

            if (! $shouldGenerate) {
                return;
            }

            $pdf = $reportGeneratorService->generatePdf($this->weeklyReportId);
            $csv = $reportGeneratorService->generateCsv($this->weeklyReportId);

            DB::transaction(function () use ($pdf, $csv) {
                $report = DB::table('weekly_call_reports')
                    ->lockForUpdate()
                    ->where('id', $this->weeklyReportId)
                    ->first();

                if (! $report) {
                    return;
                }

                DB::table('weekly_call_reports')
                    ->where('id', $this->weeklyReportId)
                    ->update([
                        'status' => 'completed',
                        'pdf_disk' => $pdf['disk'] ?? null,
                        'pdf_path' => $pdf['path'] ?? null,
                        'csv_disk' => $csv['disk'] ?? null,
                        'csv_path' => $csv['path'] ?? null,
                        'generated_at' => now(),
                        'error_message' => null,
                        'updated_at' => now(),
                    ]);
            });
        } catch (Throwable $e) {
            $this->handleFailure($e);
        }
    }

    private function handleFailure(Throwable $e): void
    {
        $message = mb_substr($e->getMessage(), 0, 2000);

        Log::warning('Weekly report generation failed.', [
            'weekly_report_id' => $this->weeklyReportId,
            'attempts' => $this->attempts(),
            'error' => $message,
        ]);

        DB::transaction(function () use ($message) {
            $report = DB::table('weekly_call_reports')
                ->lockForUpdate()
                ->where('id', $this->weeklyReportId)
                ->first();

            if (! $report) {
                return;
            }

            $status = $this->attempts() >= $this->tries ? 'failed' : 'pending';

            DB::table('weekly_call_reports')
                ->where('id', $this->weeklyReportId)
                ->update([
                    'status' => $status,
                    'error_message' => $message,
                    'updated_at' => now(),
                ]);
        });

        if ($this->attempts() >= $this->tries) {
            $this->fail($e);
            return;
        }

        throw $e;
    }
}
