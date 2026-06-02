<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class UserJobsController extends Controller
{
    public function overview(): JsonResponse
    {
        $user = Auth::guard('web')->user();
        $companyId = $user->company_id;

        if (! $companyId) {
            return response()->json([
                'queue_counts' => [],
                'totals' => ['queued' => 0, 'reserved' => 0, 'failed' => 0],
                'recent_jobs' => [],
                'failed_jobs' => [],
                'message' => 'No company assigned to your account yet.',
            ]);
        }

        // Show recent jobs payload-filtered to this company (best-effort scan)
        $recentJobs = DB::table('jobs')
            ->orderByDesc('id')
            ->limit(50)
            ->get(['id', 'queue', 'payload', 'attempts', 'reserved_at', 'available_at', 'created_at']);

        $companyJobs = $recentJobs
            ->filter(fn ($row) => $this->payloadBelongsToCompany($row->payload ?? null, $companyId))
            ->map(fn ($row) => [
                'id' => $row->id,
                'queue' => $row->queue,
                'name' => $this->extractJobName($row->payload ?? null),
                'attempts' => $row->attempts,
                'reserved_at' => $row->reserved_at ? date('Y-m-d H:i:s', $row->reserved_at) : null,
                'available_at' => $row->available_at ? date('Y-m-d H:i:s', $row->available_at) : null,
                'created_at' => $row->created_at ? date('Y-m-d H:i:s', $row->created_at) : null,
            ])
            ->values();

        $failedJobs = DB::table('failed_jobs')
            ->orderByDesc('id')
            ->limit(50)
            ->get(['id', 'queue', 'payload', 'failed_at', 'exception'])
            ->filter(fn ($row) => $this->payloadBelongsToCompany($row->payload ?? null, $companyId))
            ->map(fn ($row) => [
                'id' => $row->id,
                'queue' => $row->queue,
                'name' => $this->extractJobName($row->payload ?? null),
                'failed_at' => $row->failed_at,
                'error' => mb_substr((string) ($row->exception ?? ''), 0, 300),
            ])
            ->values();

        $totals = [
            'queued' => $companyJobs->count(),
            'failed' => $failedJobs->count(),
        ];

        return response()->json([
            'totals' => $totals,
            'recent_jobs' => $companyJobs,
            'failed_jobs' => $failedJobs,
        ]);
    }

    private function payloadBelongsToCompany(?string $raw, int $companyId): bool
    {
        if (! $raw) {
            return false;
        }

        $payload = json_decode($raw, true);
        if (! is_array($payload)) {
            return false;
        }

        // Check command data for company_id field
        $commandData = json_decode($payload['data']['command'] ?? '{}', true);
        if (is_array($commandData)) {
            // Serialized objects won't decode cleanly; fall back to string search
        }

        // Fallback: plain string search in the raw payload
        return str_contains($raw, '"company_id";i:'.$companyId.';')
            || str_contains($raw, '"company_id":' . $companyId);
    }

    private function extractJobName(?string $raw): string
    {
        if (! $raw) {
            return 'Job';
        }
        $payload = json_decode($raw, true);
        return $payload['displayName'] ?? $payload['job'] ?? 'Job';
    }
}
