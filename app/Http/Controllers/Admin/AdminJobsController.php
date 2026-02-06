<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class AdminJobsController extends Controller
{
    public function overview(): JsonResponse
    {
        $queueConnection = config('queue.default');

        $jobsQuery = DB::table('jobs');
        $failedQuery = DB::table('failed_jobs');

        $queueCounts = $jobsQuery
            ->select([
                'queue',
                DB::raw('COUNT(*) as queued'),
                DB::raw('SUM(CASE WHEN reserved_at IS NOT NULL THEN 1 ELSE 0 END) as reserved'),
            ])
            ->groupBy('queue')
            ->orderBy('queue')
            ->get();

        $totals = [
            'queued' => (int) $jobsQuery->count(),
            'reserved' => (int) $jobsQuery->whereNotNull('reserved_at')->count(),
            'failed' => (int) $failedQuery->count(),
        ];

        $recentJobs = $jobsQuery
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

        $failedJobs = $failedQuery
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

        return response()->json([
            'data' => [
                'queue_connection' => $queueConnection,
                'totals' => $totals,
                'queues' => $queueCounts,
                'jobs' => $jobs,
                'failed_jobs' => $failed,
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
}
