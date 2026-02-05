<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ReportGeneratorService
{
    /**
    * Generate a weekly report PDF and store it in the configured filesystem disk.
     *
     * @return array{disk:string,path:string,url:string,mime:string}
     */
    public function generatePdf(int $weeklyReportId): array
    {
        $report = $this->getWeeklyReport($weeklyReportId);

        $viewName = (string) config('services.reports.weekly_template', 'reports.weekly.default');
        $html = View::make($viewName, [
            'report' => $report,
        ])->render();

        $pdfBytes = $this->renderPdf($html);

        $disk = (string) config('services.reports.storage_disk', 'local');
        $path = $this->buildStoragePath($report['company_id'], $report['reporting_period_start'], $weeklyReportId, 'pdf');

        Storage::disk($disk)->put($path, $pdfBytes, [
            'ContentType' => 'application/pdf',
        ]);

        return [
            'disk' => $disk,
            'path' => $path,
            'url' => $this->makeDownloadUrl($disk, $path),
            'mime' => 'application/pdf',
        ];
    }

    /**
        * Generate a weekly report CSV and store it in the configured filesystem disk.
     *
     * @return array{disk:string,path:string,url:string,mime:string}
     */
    public function generateCsv(int $weeklyReportId): array
    {
        $report = $this->getWeeklyReport($weeklyReportId);

        $csv = $this->renderCsv($report);

        $disk = (string) config('services.reports.storage_disk', 'local');
        $path = $this->buildStoragePath($report['company_id'], $report['reporting_period_start'], $weeklyReportId, 'csv');

        Storage::disk($disk)->put($path, $csv, [
            'ContentType' => 'text/csv; charset=utf-8',
        ]);

        return [
            'disk' => $disk,
            'path' => $path,
            'url' => $this->makeDownloadUrl($disk, $path),
            'mime' => 'text/csv',
        ];
    }

    private function getWeeklyReport(int $weeklyReportId): array
    {
        $report = \App\Models\WeeklyCallReport::with(['company:id,name', 'companyPbxAccount:id,name'])
            ->find($weeklyReportId);

        if (! $report) {
            throw new RuntimeException('Weekly report not found.');
        }

        $metrics = $report->metrics ?? [];

        return [
            'id' => (int) $report->id,
            'company_id' => (int) $report->company_id,
            'company_name' => $report->company?->name,
            'pbx_account_name' => $report->companyPbxAccount?->name,
            'reporting_period_start' => $report->reporting_period_start?->toDateString(),
            'reporting_period_end' => $report->reporting_period_end?->toDateString(),
            'generated_at' => $report->generated_at?->toIso8601String(),
            'executive_summary' => $report->executive_summary,
            'metrics' => [
                'total_calls' => $report->total_calls,
                'answered_calls' => $report->answered_calls,
                'missed_calls' => $report->missed_calls,
                'answer_rate' => $report->total_calls > 0
                    ? round(($report->answered_calls / $report->total_calls) * 100, 1)
                    : 0,
                'calls_with_transcription' => $report->calls_with_transcription,
                'transcription_rate' => $report->total_calls > 0
                    ? round(($report->calls_with_transcription / $report->total_calls) * 100, 1)
                    : 0,
                'total_call_duration_seconds' => $report->total_call_duration_seconds,
                'avg_call_duration_seconds' => $report->avg_call_duration_seconds,
                'avg_call_duration_formatted' => $this->formatDuration((int) ($report->avg_call_duration_seconds ?? 0)),
                'first_call_at' => $report->first_call_at?->toIso8601String(),
                'last_call_at' => $report->last_call_at?->toIso8601String(),
            ],
            'category_breakdowns' => [
                'counts' => $metrics['category_counts'] ?? [],
                'details' => $metrics['category_breakdowns'] ?? [],
                'top_dids' => $metrics['top_dids'] ?? [],
                'hourly_distribution' => $metrics['hourly_distribution'] ?? [],
            ],
            'insights' => $metrics['insights'] ?? [
                'ai_opportunities' => [],
                'recommendations' => [],
            ],
            'ai_summary' => $metrics['ai_summary'] ?? null,
        ];
    }

    /**
     * @return array<int,mixed>
     */
    private function decodeJsonArray(mixed $value): array
    {
        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? array_values($decoded) : [];
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeJsonAssoc(mixed $value): array
    {
        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function formatDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds === 1 ? '1 second' : "{$seconds} seconds";
        }

        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        $parts = [];

        if ($hours > 0) {
            $parts[] = $hours === 1 ? '1 hour' : "{$hours} hours";
        }

        if ($minutes > 0) {
            $parts[] = $minutes === 1 ? '1 minute' : "{$minutes} minutes";
        }

        return implode(' ', $parts) ?: '0 seconds';
    }

    private function renderPdf(string $html): string
    {
        if (! class_exists(Dompdf::class)) {
            throw new RuntimeException('Dompdf is not installed. Run: composer require dompdf/dompdf');
        }

        $options = new Options();
        $options->set('isRemoteEnabled', true);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    private function renderCsv(array $report): string
    {
        $fh = fopen('php://temp', 'wb+');
        if ($fh === false) {
            throw new RuntimeException('Unable to allocate CSV buffer.');
        }

        $writeRow = static function (array $row) use ($fh): void {
            fputcsv($fh, $row);
        };

        $writeRow(['weekly_report_id', $report['id']]);
        $writeRow(['company_id', $report['company_id']]);
        $writeRow(['reporting_period_start', $report['reporting_period_start']]);
        $writeRow(['reporting_period_end', $report['reporting_period_end']]);
        $writeRow(['total_calls', $report['total_calls']]);
        $writeRow(['total_duration_seconds', $report['total_duration_seconds']]);
        $writeRow(['unresolved_calls_count', $report['unresolved_calls_count']]);

        $writeRow([]);
        $writeRow(['top_extensions']);
        $writeRow(['extension_or_key', 'count']);
        foreach ($report['top_extensions'] as $row) {
            if (is_array($row)) {
                $key = $row['key'] ?? ($row['extension'] ?? null);
                $count = $row['count'] ?? ($row['calls'] ?? null);
                $writeRow([(string) $key, (string) $count]);
            }
        }

        $writeRow([]);
        $writeRow(['top_call_topics']);
        $writeRow(['topic', 'mentions']);
        foreach ($report['top_call_topics'] as $row) {
            if (is_array($row)) {
                $writeRow([(string) ($row['topic'] ?? ''), (string) ($row['mentions'] ?? '')]);
            }
        }

        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);

        if (! is_string($csv)) {
            throw new RuntimeException('Unable to read generated CSV.');
        }

        // UTF-8 BOM helps Excel; safe for most tooling.
        return "\xEF\xBB\xBF".$csv;
    }

    private function buildStoragePath(int $companyId, string $periodStart, int $weeklyReportId, string $ext): string
    {
        $uuid = (string) Str::uuid();
        $date = CarbonImmutable::now('UTC')->format('Ymd_His');

        return "reports/company_{$companyId}/weekly/{$periodStart}/weekly_report_{$weeklyReportId}_{$date}_{$uuid}.{$ext}";
    }

    private function makeDownloadUrl(string $disk, string $path): string
    {
        $minutes = (int) config('services.reports.signed_url_minutes', 60);

        try {
            return Storage::disk($disk)->temporaryUrl($path, now()->addMinutes($minutes));
        } catch (Throwable $e) {
            // Fallback for disks that don't support temporary URLs.
            try {
                return Storage::disk($disk)->url($path);
            } catch (Throwable $e2) {
                // Local/private disks may not be URL-addressable; return an absolute path.
                return Storage::disk($disk)->path($path);
            }
        }
    }
}
