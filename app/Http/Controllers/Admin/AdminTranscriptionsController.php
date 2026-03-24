<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Call;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AdminTranscriptionsController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],

            'sort' => ['nullable', 'string', 'max:40'],
            'direction' => ['nullable', 'in:asc,desc'],
            'search' => ['nullable', 'string', 'max:200'],
            'company_id' => ['nullable', 'integer', 'min:1'],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 25);

        $sort = (string) ($validated['sort'] ?? 'created_at');
        $direction = (string) ($validated['direction'] ?? 'desc');

        $allowedSort = [
            'id',
            'created_at',
            'pbx_unique_id',
            'company',
        ];

        if (! in_array($sort, $allowedSort, true)) {
            $sort = 'created_at';
        }

        $search = trim((string) ($validated['search'] ?? ''));
        $companyId = isset($validated['company_id']) ? (int) $validated['company_id'] : null;
        $startDate = $validated['start_date'] ?? null;
        $endDate = $validated['end_date'] ?? null;

        Log::info('AdminTranscriptionsController@index: request received', [
            'params' => [
                'page' => $validated['page'] ?? 1,
                'per_page' => $perPage,
                'sort' => $sort,
                'direction' => $direction,
                'search' => $search !== '' ? $search : null,
                'company_id' => $companyId,
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
        ]);

        $query = Call::query()
            ->select([
                'calls.*',
                'companies.name as company_name',
            ])
            ->leftJoin('companies', 'companies.id', '=', 'calls.company_id')
            ->where('calls.has_transcription', true)
            ->whereNotNull('calls.transcript_text')
            ->where('calls.transcript_text', '!=', '');

        $debugCounts = [
            'base_count' => (clone $query)->count(),
        ];

        if ($companyId) {
            $query->where('calls.company_id', $companyId);
            $debugCounts['company_filtered_count'] = (clone $query)->count();
        }

        if ($startDate) {
            $query->where('calls.started_at', '>=', CarbonImmutable::parse($startDate, 'UTC')->startOfDay());
            $debugCounts['start_date_filtered_count'] = (clone $query)->count();
        }

        if ($endDate) {
            $query->where('calls.started_at', '<=', CarbonImmutable::parse($endDate, 'UTC')->endOfDay());
            $debugCounts['end_date_filtered_count'] = (clone $query)->count();
        }

        if ($search !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%';
            $query->where(function ($q) use ($like) {
                $q->where('calls.pbx_unique_id', 'like', $like)
                    ->orWhere('calls.transcript_text', 'like', $like)
                    ->orWhere('calls.from', 'like', $like)
                    ->orWhere('calls.to', 'like', $like)
                    ->orWhere('companies.name', 'like', $like);
            });
            $debugCounts['search_filtered_count'] = (clone $query)->count();
        }

        if ($sort === 'pbx_unique_id') {
            $query->orderBy('calls.pbx_unique_id', $direction);
        } elseif ($sort === 'company') {
            $query->orderBy('companies.name', $direction);
        } else {
            $query->orderBy("calls.{$sort}", $direction);
        }

        $query->orderBy('calls.id', 'desc');

        $paginator = $query->paginate($perPage)->appends($request->query());

        $items = collect($paginator->items())->map(function (Call $call) {
            return [
                'id' => $call->id,
                'callId' => (string) ($call->pbx_unique_id ?? ''),
                'company' => (string) ($call->getAttribute('company_name') ?? ''),
                'provider' => 'pbxware',
                'durationSeconds' => (int) ($call->duration_seconds ?? 0),
                'createdAt' => optional($call->created_at)->toISOString(),
            ];
        })->values();

        Log::info('AdminTranscriptionsController@index: response summary', [
            'counts' => $debugCounts,
            'returned_rows' => $items->count(),
            'total' => $paginator->total(),
            'first_row' => $items->first(),
        ]);

        return response()->json([
            'data' => $items,
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'sort' => [
                'by' => $sort,
                'direction' => $direction,
            ],
        ]);
    }

    public function show(Request $request, string $id)
    {
        $idInt = (int) $id;
        if ($idInt <= 0) {
            throw new ModelNotFoundException();
        }

        $call = Call::query()
            ->with(['company:id,name'])
            ->findOrFail($idInt);

        if (! (bool) ($call->has_transcription ?? false)) {
            throw new ModelNotFoundException();
        }

        $company = $call->company;

        return response()->json([
            'transcription' => [
                'provider' => 'pbxware',
                'durationSeconds' => (int) ($call->duration_seconds ?? 0),
                'createdAt' => optional($call->created_at)->toISOString(),
                'updatedAt' => optional($call->updated_at)->toISOString(),
                'text' => (string) ($call->transcript_text ?? ''),
            ],
            'call' => $call ? [
                'id' => $call->id,
                'callId' => (string) ($call->pbx_unique_id ?? ''),
                'direction' => (string) ($call->direction ?? ''),
                'from' => (string) ($call->from ?? ''),
                'to' => (string) ($call->to ?? ''),
                'startedAt' => optional($call->started_at)->toISOString(),
                'durationSeconds' => (int) ($call->duration_seconds ?? 0),
            ] : null,
            'company' => $company ? [
                'id' => $company->id,
                'name' => (string) ($company->name ?? ''),
            ] : null,
        ]);
    }
}
