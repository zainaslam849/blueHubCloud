<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CallRecording;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;

class AdminRecordingsController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],

            // Filters
            'company' => ['nullable', 'string', 'max:120'],
            'company_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'string', 'max:40'],
            'duration_min' => ['nullable', 'integer', 'min:0'],
            'duration_max' => ['nullable', 'integer', 'min:0'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],

            // Sorting
            'sort' => ['nullable', 'string', 'max:40'],
            'direction' => ['nullable', 'in:asc,desc'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 25);

        $sort = (string) ($validated['sort'] ?? 'created_at');
        $direction = (string) ($validated['direction'] ?? 'desc');

        $allowedSort = [
            'id',
            'status',
            'recording_duration',
            'storage_provider',
            'created_at',
            'call_uid',
            'company',
            'codec',
        ];

        if (! in_array($sort, $allowedSort, true)) {
            $sort = 'created_at';
        }

        $query = CallRecording::query()
            ->select([
                'call_recordings.*',
                'calls.call_uid as call_uid',
                'companies.name as company_name',
            ])
            ->leftJoin('calls', 'calls.id', '=', 'call_recordings.call_id')
            ->leftJoin('companies', 'companies.id', '=', 'call_recordings.company_id');

        // Filters
        $companyId = $validated['company_id'] ?? null;
        if ($companyId) {
            $query->where('call_recordings.company_id', (int) $companyId);
        }

        $company = trim((string) ($validated['company'] ?? ''));
        if ($company !== '') {
            $query->where('companies.name', 'like', "%{$company}%");
        }

        $status = trim((string) ($validated['status'] ?? ''));
        if ($status !== '') {
            $query->where('call_recordings.status', $status);
        }

        $durationMin = $validated['duration_min'] ?? null;
        if ($durationMin !== null) {
            $query->where('call_recordings.recording_duration', '>=', (int) $durationMin);
        }

        $durationMax = $validated['duration_max'] ?? null;
        if ($durationMax !== null) {
            $query->where('call_recordings.recording_duration', '<=', (int) $durationMax);
        }

        $dateFrom = $validated['date_from'] ?? null;
        if ($dateFrom) {
            $query->whereDate('call_recordings.created_at', '>=', $dateFrom);
        }

        $dateTo = $validated['date_to'] ?? null;
        if ($dateTo) {
            $query->whereDate('call_recordings.created_at', '<=', $dateTo);
        }

        // Sorting
        if ($sort === 'call_uid') {
            $query->orderBy('calls.call_uid', $direction);
        } elseif ($sort === 'company') {
            $query->orderBy('companies.name', $direction);
        } else {
            $query->orderBy("call_recordings.{$sort}", $direction);
        }

        $query->orderBy('call_recordings.id', 'desc');

        $paginator = $query->paginate($perPage)->appends($request->query());

        $data = collect($paginator->items())->map(function (CallRecording $rec) {
            $raw = strtolower((string) ($rec->status ?? ''));
            $category = $raw === 'failed'
                ? 'failed'
                : (in_array($raw, ['completed', 'transcribed'], true) ? 'completed' : 'processing');

            return [
                'id' => $rec->id,
                'recordingId' => $rec->id,
                'callId' => $rec->getAttribute('call_uid'),
                'companyId' => $rec->company_id,
                'company' => $rec->getAttribute('company_name'),
                'codec' => $rec->codec,
                'durationSeconds' => (int) ($rec->recording_duration ?? 0),
                'storageProvider' => (string) ($rec->storage_provider ?? ''),
                'recordingUrl' => (string) ($rec->recording_url ?? ''),
                'status' => $category,
                'rawStatus' => (string) ($rec->status ?? ''),
                'createdAt' => optional($rec->created_at)->toISOString(),
            ];
        })->values();

        return response()->json([
            'data' => $data,
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'filters' => [
                'company' => $company,
                'companyId' => $companyId,
                'status' => $status,
                'durationMin' => $durationMin,
                'durationMax' => $durationMax,
                'dateFrom' => $dateFrom,
                'dateTo' => $dateTo,
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

        $rec = CallRecording::query()
            ->with([
                'call.company',
                'call.companyPbxAccount.pbxProvider',
                'call.callTranscriptions',
            ])
            ->findOrFail($idInt);

        $rawRecStatus = strtolower((string) ($rec->status ?? ''));
        $recCategory = $rawRecStatus === 'failed'
            ? 'failed'
            : (in_array($rawRecStatus, ['completed', 'stored', 'transcribed'], true) ? 'completed' : 'processing');

        $call = $rec->call;
        $company = $call?->company;

        $transcriptions = $call?->callTranscriptions ?? collect();
        $lastTranscription = $transcriptions->sortByDesc('created_at')->first();

        $transcriptionStatus = 'processing';
        if (($transcriptions->count() ?? 0) > 0) {
            $transcriptionStatus = 'completed';
        } elseif (in_array($rawRecStatus, ['transcribing', 'transcribed'], true)) {
            $transcriptionStatus = $rawRecStatus === 'transcribed' ? 'completed' : 'processing';
        } else {
            $transcriptionStatus = 'processing';
        }

        if ($rawRecStatus === 'failed') {
            $transcriptionStatus = 'failed';
        }

        $createdAtIso = optional($rec->created_at)->toISOString();
        $updatedAtIso = optional($rec->updated_at)->toISOString();

        $isProcessed = in_array(
            $rawRecStatus,
            ['processing', 'completed', 'transcribing', 'transcribed', 'stored', 'failed'],
            true
        );

        $isStored = ($rec->storage_path !== null && $rec->storage_path !== '')
            || in_array($rawRecStatus, ['stored', 'completed', 'transcribing', 'transcribed'], true);

        $timeline = [
            [
                'key' => 'ingested',
                'label' => 'Ingested',
                'status' => 'completed',
                'occurredAt' => $createdAtIso,
                'detail' => 'Recording received',
            ],
            [
                'key' => 'processed',
                'label' => 'Processed',
                'status' => $rawRecStatus === 'failed' ? 'failed' : ($isProcessed ? 'completed' : 'processing'),
                'occurredAt' => $isProcessed ? $updatedAtIso : null,
                'detail' => $rawRecStatus ? "Status: {$rawRecStatus}" : null,
            ],
            [
                'key' => 'stored',
                'label' => 'Stored',
                'status' => $rawRecStatus === 'failed' ? 'failed' : ($isStored ? 'completed' : 'processing'),
                'occurredAt' => $isStored ? $updatedAtIso : null,
                'detail' => $rec->storage_provider ? "Provider: {$rec->storage_provider}" : null,
            ],
        ];

        return response()->json([
            'recording' => [
                'id' => $rec->id,
                'status' => $recCategory,
                'rawStatus' => (string) ($rec->status ?? ''),
                'durationSeconds' => (int) ($rec->recording_duration ?? 0),
                'codec' => $rec->codec,
                'createdAt' => $createdAtIso,
                'updatedAt' => $updatedAtIso,
                'errorMessage' => $rec->error_message,
            ],
            'call' => $call ? [
                'id' => $call->id,
                'callId' => $call->call_uid,
                'status' => (string) ($call->status ?? ''),
                'direction' => (string) ($call->direction ?? ''),
                'fromNumber' => (string) ($call->from_number ?? ''),
                'toNumber' => (string) ($call->to_number ?? ''),
                'startedAt' => optional($call->started_at)->toISOString(),
                'endedAt' => optional($call->ended_at)->toISOString(),
                'durationSeconds' => (int) ($call->duration_seconds ?? 0),
                'company' => $company?->name,
            ] : null,
            'company' => $company ? [
                'id' => $company->id,
                'name' => (string) ($company->name ?? ''),
                'timezone' => (string) ($company->timezone ?? ''),
                'status' => (string) ($company->status ?? ''),
            ] : null,
            'storage' => [
                'provider' => (string) ($rec->storage_provider ?? ''),
                'path' => (string) ($rec->storage_path ?? ''),
                'fileSizeBytes' => $rec->file_size,
                'url' => (string) ($rec->recording_url ?? ''),
            ],
            'transcription' => [
                'status' => $transcriptionStatus,
                'provider' => $lastTranscription?->provider_name,
                'count' => (int) ($transcriptions->count() ?? 0),
                'lastCreatedAt' => optional($lastTranscription?->created_at)->toISOString(),
            ],
            'timeline' => $timeline,
        ]);
    }
}
