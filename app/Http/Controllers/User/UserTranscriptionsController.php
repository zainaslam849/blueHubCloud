<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Call;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class UserTranscriptionsController extends Controller
{
    /**
     * List the company's calls that have a transcript.
     * GET /api/v1/transcriptions
     */
    public function index(Request $request): JsonResponse
    {
        $user = Auth::guard('web')->user();

        if (! $user->company_id) {
            return response()->json([
                'data' => [],
                'meta' => ['currentPage' => 1, 'lastPage' => 1, 'perPage' => 25, 'total' => 0],
                'message' => 'No company assigned to your account yet.',
            ]);
        }

        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search' => ['nullable', 'string', 'max:120'],
            'call_direction' => ['nullable', 'in:inbound,outbound,internal'],
            'call_status' => ['nullable', 'in:answered,missed,unknown'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 25);

        $query = Call::query()
            ->where('company_id', $user->company_id)
            ->whereNotNull('transcript_text')
            ->where('transcript_text', '!=', '')
            ->orderByDesc('started_at');

        if (! empty($validated['search'])) {
            $term = '%' . $validated['search'] . '%';
            $query->where(function ($q) use ($term) {
                $q->where('pbx_unique_id', 'like', $term)
                    ->orWhere('from', 'like', $term)
                    ->orWhere('to', 'like', $term)
                    ->orWhere('transcript_text', 'like', $term);
            });
        }

        if (! empty($validated['call_direction'])) {
            $query->where('direction', $validated['call_direction']);
        }
        if (! empty($validated['call_status'])) {
            $query->where('status', $validated['call_status']);
        }

        // calls.started_at is stored in UTC; a customer only ever sees their
        // own company's calls, so use that company's own timezone for the
        // date-range boundaries instead of comparing local dates against the
        // raw UTC column (whereDate), which shifts the window by the UTC offset.
        if (! empty($validated['start_date']) || ! empty($validated['end_date'])) {
            $companyTimezone = $user->company?->timezone;
            $timezone = is_string($companyTimezone) && $companyTimezone !== '' ? $companyTimezone : 'UTC';

            if (! empty($validated['start_date'])) {
                $query->where('started_at', '>=', CarbonImmutable::parse($validated['start_date'], $timezone)->startOfDay()->setTimezone('UTC'));
            }
            if (! empty($validated['end_date'])) {
                $query->where('started_at', '<=', CarbonImmutable::parse($validated['end_date'], $timezone)->endOfDay()->setTimezone('UTC'));
            }
        }

        $paginator = $query->paginate($perPage)->appends($request->query());

        return response()->json([
            'data' => collect($paginator->items())->map(fn (Call $call) => [
                'id' => $call->id,
                'pbx_unique_id' => $call->pbx_unique_id,
                'from' => $call->from,
                'to' => $call->to,
                'direction' => $call->direction,
                'status' => $call->status,
                'duration_seconds' => $call->duration_seconds,
                'started_at' => $call->started_at?->toIso8601String(),
                'ai_summary' => $call->ai_summary,
                'transcript_text' => $call->transcript_text,
            ])->values(),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }
}
