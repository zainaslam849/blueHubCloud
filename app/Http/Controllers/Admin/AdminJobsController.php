<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\AdminTestPipelineJob;
use App\Models\PipelineRun;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AdminJobsController extends Controller
{
    public function overview(): JsonResponse
    {
        $queueConnection = config('queue.default');

        $queueCounts = DB::table('jobs')
            ->select([
                'queue',
                DB::raw('COUNT(*) as queued'),
                DB::raw('SUM(CASE WHEN reserved_at IS NOT NULL THEN 1 ELSE 0 END) as reserved'),
            ])
            ->groupBy('queue')
            ->orderBy('queue')
            ->get();

        $totals = [
            'queued' => (int) DB::table('jobs')->count(),
            'reserved' => (int) DB::table('jobs')->whereNotNull('reserved_at')->count(),
            'failed' => (int) DB::table('failed_jobs')->count(),
        ];

        $recentJobs = DB::table('jobs')
            ->orderByDesc('id')
            ->limit(25)
            ->get(['id', 'queue', 'payload', 'attempts', 'reserved_at', 'available_at', 'created_at']);

        $jobs = $recentJobs->map(function ($row) {
            $payload = $this->decodePayload($row->payload ?? null);

            return [
                'id' => $row->id,
                'queue' => $row->queue,
                'name' => $payload['displayName'] ?? $payload['job'] ?? 'Job',
                'attempts' => $row->attempts,
                'reserved_at' => $this->formatUnix($row->reserved_at ?? null),
                'available_at' => $this->formatUnix($row->available_at ?? null),
                'created_at' => $this->formatUnix($row->created_at ?? null),
            ];
        })->values();

        $failedJobs = DB::table('failed_jobs')
            ->orderByDesc('id')
            ->limit(25)
            ->get(['id', 'queue', 'failed_at', 'exception']);

        $failed = $failedJobs->map(function ($row) {
            return [
                'id' => $row->id,
                'queue' => $row->queue,
                'failed_at' => $row->failed_at,
                'error' => $this->trimException($row->exception ?? null),
            ];
        })->values();

        $pipelineRows = collect();
        $pipelineTotals = [
            'running' => 0,
            'queued' => 0,
            'failed' => 0,
            'completed' => 0,
        ];

        if (Schema::hasTable('pipeline_runs') && Schema::hasTable('pipeline_run_stages')) {
            $pipelineRuns = PipelineRun::query()
                ->with(['company:id,name', 'stages'])
                ->latest('id')
                ->limit(25)
                ->get();

            $pipelineRows = $pipelineRuns->map(function (PipelineRun $run) {
                $rangeFrom = $run->range_from?->toDateString();
                $rangeTo = $run->range_to?->toDateString();

                $stages = $run->stages
                    ->map(function ($stage) {
                        return [
                            'stage_key' => $stage->stage_key,
                            'status' => $stage->status,
                            'finished_at' => $stage->finished_at?->toIso8601String(),
                            'error_message' => $this->trimException((string) ($stage->error_message ?? '')),
                        ];
                    })
                    ->values();

                return [
                    'id' => $run->id,
                    'company_id' => $run->company_id,
                    'company_name' => $run->company?->name ?? 'Unknown company',
                    'range_from' => $rangeFrom,
                    'range_to' => $rangeTo,
                    'status' => $run->status,
                    'current_stage' => $run->current_stage,
                    'resume_count' => (int) ($run->resume_count ?? 0),
                    'started_at' => $run->started_at?->toIso8601String(),
                    'finished_at' => $run->finished_at?->toIso8601String(),
                    'last_error' => $this->trimException((string) ($run->last_error ?? '')),
                    'can_resume' => in_array($run->status, ['failed', 'queued'], true),
                    'stages' => $stages,
                ];
            })->values();

            $pipelineTotals = [
                'running' => PipelineRun::query()->where('status', 'running')->count(),
                'queued' => PipelineRun::query()->where('status', 'queued')->count(),
                'failed' => PipelineRun::query()->where('status', 'failed')->count(),
                'completed' => PipelineRun::query()->where('status', 'completed')->count(),
            ];
        }

        return response()->json([
            'data' => [
                'queue_connection' => $queueConnection,
                'totals' => $totals,
                'queues' => $queueCounts,
                'jobs' => $jobs,
                'failed_jobs' => $failed,
                'pipeline_totals' => $pipelineTotals,
                'pipeline_runs' => $pipelineRows,
            ],
        ]);
    }

    public function resumePipeline(int $pipelineRunId): JsonResponse
    {
        if (! Schema::hasTable('pipeline_runs')) {
            return response()->json([
                'message' => 'Pipeline tracking tables are not available yet. Run migrations first.',
            ], 422);
        }

        $run = PipelineRun::query()->findOrFail($pipelineRunId);

        if (! in_array($run->status, ['failed', 'queued'], true)) {
            return response()->json([
                'message' => 'This pipeline cannot be resumed in its current state.',
            ], 422);
        }

        $activeKey = $this->buildActiveKey(
            (int) $run->company_id,
            $run->range_from?->toDateString() ?? '',
            $run->range_to?->toDateString() ?? ''
        );

        $hasActivePeer = PipelineRun::query()
            ->where('active_key', $activeKey)
            ->where('id', '!=', $run->id)
            ->whereNotIn('status', ['failed', 'completed', 'cancelled'])
            ->exists();

        if ($hasActivePeer) {
            return response()->json([
                'message' => 'Another active pipeline already exists for this company/date range.',
            ], 409);
        }

        $metrics = is_array($run->metrics) ? $run->metrics : [];

        $run->forceFill([
            'status' => 'queued',
            'last_error' => null,
            'active_key' => $activeKey,
            'finished_at' => null,
            'resume_count' => (int) $run->resume_count + 1,
        ])->save();

        AdminTestPipelineJob::dispatch(
            (int) $run->company_id,
            $run->range_from?->toDateString() ?? CarbonImmutable::now('UTC')->subDay()->toDateString(),
            $run->range_to?->toDateString() ?? CarbonImmutable::now('UTC')->toDateString(),
            (int) ($metrics['summarize_limit'] ?? 500),
            (int) ($metrics['categorize_limit'] ?? 500),
            'default',
            $run->id,
            true
        )->onQueue('default');

        return response()->json([
            'message' => 'Pipeline resume queued.',
            'data' => [
                'pipeline_run_id' => $run->id,
            ],
        ]);
    }

    private function decodePayload(mixed $payload): array
    {
        if (! is_string($payload) || trim($payload) === '') {
            return [];
        }

        $decoded = json_decode($payload, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function formatUnix(mixed $value): ?string
    {
        if (! $value || ! is_numeric($value)) {
            return null;
        }

        return now()->createFromTimestamp((int) $value)->toIso8601String();
    }

    private function trimException(mixed $value): string
    {
        if (! is_string($value)) {
            return '';
        }

        return mb_strlen($value) > 240 ? mb_substr($value, 0, 240).'…' : $value;
    }

    private function buildActiveKey(int $companyId, string $from, string $to): string
    {
        return $companyId . ':' . $from . ':' . $to;
    }
}
