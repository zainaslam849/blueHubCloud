<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CallRecording;
use App\Models\CallSpeakerSegment;
use App\Models\CallTranscription;
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
            'provider_name',
            'language',
            'duration_seconds',
            'call_uid',
            'company',
        ];

        if (! in_array($sort, $allowedSort, true)) {
            $sort = 'created_at';
        }

        $query = CallTranscription::query()
            ->select([
                'call_transcriptions.*',
                'calls.call_uid as call_uid',
                'companies.name as company_name',
            ])
            ->leftJoin('calls', 'calls.id', '=', 'call_transcriptions.call_id')
            ->leftJoin('companies', 'companies.id', '=', 'calls.company_id');

        if ($sort === 'call_uid') {
            $query->orderBy('calls.call_uid', $direction);
        } elseif ($sort === 'company') {
            $query->orderBy('companies.name', $direction);
        } else {
            $query->orderBy("call_transcriptions.{$sort}", $direction);
        }

        $query->orderBy('call_transcriptions.id', 'desc');

        $paginator = $query->paginate($perPage)->appends($request->query());

        $items = collect($paginator->items())->map(function (CallTranscription $t) {
            return [
                'id' => $t->id,
                'callId' => $t->getAttribute('call_uid'),
                'company' => $t->getAttribute('company_name'),
                'provider' => (string) ($t->provider_name ?? ''),
                'language' => (string) ($t->language ?? ''),
                'durationSeconds' => (int) ($t->duration_seconds ?? 0),
                'createdAt' => optional($t->created_at)->toISOString(),
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

        $t = CallTranscription::query()
            ->with(['call.company'])
            ->findOrFail($idInt);

        $call = $t->call;
        $company = $call?->company;

        $segments = [];
        if ($call) {
            $segments = CallSpeakerSegment::query()
                ->where('call_id', $call->id)
                ->orderBy('start_second')
                ->orderBy('id')
                ->get()
                ->map(function (CallSpeakerSegment $s) {
                    return [
                        'id' => $s->id,
                        'speaker' => (string) ($s->speaker_label ?? ''),
                        'startSecond' => (int) ($s->start_second ?? 0),
                        'endSecond' => (int) ($s->end_second ?? 0),
                        'text' => (string) ($s->text ?? ''),
                        'createdAt' => optional($s->created_at)->toISOString(),
                    ];
                })
                ->values()
                ->all();
        }

        $recording = null;
        if ($call) {
            $rec = CallRecording::query()
                ->where('call_id', $call->id)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->first();

            if ($rec) {
                $recording = [
                    'id' => $rec->id,
                    'status' => (string) ($rec->status ?? ''),
                    'durationSeconds' => (int) ($rec->recording_duration ?? 0),
                    'createdAt' => optional($rec->created_at)->toISOString(),
                ];
            }
        }

        return response()->json([
            'transcription' => [
                'id' => $t->id,
                'provider' => (string) ($t->provider_name ?? ''),
                'language' => (string) ($t->language ?? ''),
                'durationSeconds' => (int) ($t->duration_seconds ?? 0),
                'confidenceScore' => $t->confidence_score,
                'createdAt' => optional($t->created_at)->toISOString(),
                'updatedAt' => optional($t->updated_at)->toISOString(),
                'text' => (string) ($t->transcript_text ?? ''),
            ],
            'call' => $call ? [
                'id' => $call->id,
                'callId' => (string) ($call->call_uid ?? ''),
                'direction' => (string) ($call->direction ?? ''),
                'fromNumber' => (string) ($call->from_number ?? ''),
                'toNumber' => (string) ($call->to_number ?? ''),
                'startedAt' => optional($call->started_at)->toISOString(),
                'endedAt' => optional($call->ended_at)->toISOString(),
                'durationSeconds' => (int) ($call->duration_seconds ?? 0),
            ] : null,
            'company' => $company ? [
                'id' => $company->id,
                'name' => (string) ($company->name ?? ''),
            ] : null,
            'recording' => $recording,
            'segments' => $segments,
        ]);
    }
}
