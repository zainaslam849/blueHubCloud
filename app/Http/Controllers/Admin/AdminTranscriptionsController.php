<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Call;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;

class AdminTranscriptionsController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],

            'sort' => ['nullable', 'string', 'max:40'],
            'direction' => ['nullable', 'in:asc,desc'],
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

        $query = Call::query()
            ->select([
                'calls.*',
                'companies.name as company_name',
            ])
            ->leftJoin('companies', 'companies.id', '=', 'calls.company_id')
            ->where('calls.has_transcription', true);

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
