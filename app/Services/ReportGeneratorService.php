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

    /**
     * @return array{
     *   id:int,
     *   company_id:int,
     *   reporting_period_start:string,
     *   reporting_period_end:string,
     *   total_calls:int,
     *   total_duration_seconds:int,
     *   unresolved_calls_count:int,
     *   top_extensions:array<int,mixed>,
     *   top_call_topics:array<int,mixed>,
     *   metadata:array<string,mixed>
     * }
     */
    private function getWeeklyReport(int $weeklyReportId): array
    {
        $row = DB::table('weekly_call_reports')
            ->where('id', $weeklyReportId)
            ->first();

        if (! $row) {
            throw new RuntimeException('Weekly report not found.');
        }

        return [
            'id' => (int) $row->id,
            'company_id' => (int) $row->company_id,
            'reporting_period_start' => (string) $row->reporting_period_start,
            'reporting_period_end' => (string) $row->reporting_period_end,
            'total_calls' => (int) ($row->total_calls ?? 0),
            'total_duration_seconds' => (int) ($row->total_duration_seconds ?? 0),
            'unresolved_calls_count' => (int) ($row->unresolved_calls_count ?? 0),
            'top_extensions' => $this->decodeJsonArray($row->top_extensions ?? null),
            'top_call_topics' => $this->decodeJsonArray($row->top_call_topics ?? null),
            'metadata' => $this->decodeJsonAssoc($row->metadata ?? null),
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
