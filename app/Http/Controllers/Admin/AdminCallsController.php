<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Call;
use Illuminate\Http\Request;

class AdminCallsController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'search' => ['nullable', 'string', 'max:120'],
            'sort' => ['nullable', 'string', 'max:40'],
            'direction' => ['nullable', 'in:asc,desc'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 25);
        $search = trim((string) ($validated['search'] ?? ''));

        $sort = (string) ($validated['sort'] ?? 'created_at');
        $direction = (string) ($validated['direction'] ?? 'desc');

        $allowedSort = [
            'id',
            'pbx_unique_id',
            'duration_seconds',
            'status',
            'created_at',
            'started_at',
            'company',
            'provider',
        ];

        if (! in_array($sort, $allowedSort, true)) {
            $sort = 'created_at';
        }

        $query = Call::query()->select('calls.*')
            ->with([
                'company:id,name',
                'companyPbxAccount:id,pbx_provider_id,pbx_name',
                'companyPbxAccount.pbxProvider:id,name',
            ]);

        $needsCompanyJoin = $sort === 'company' || $search !== '';
        $needsProviderJoin = $sort === 'provider';

        if ($needsCompanyJoin) {
            $query->leftJoin('companies', 'companies.id', '=', 'calls.company_id');
        }

        if ($needsProviderJoin) {
            $query->leftJoin('company_pbx_accounts', 'company_pbx_accounts.id', '=', 'calls.company_pbx_account_id');
            $query->leftJoin('pbx_providers', 'pbx_providers.id', '=', 'company_pbx_accounts.pbx_provider_id');
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $numericId = ctype_digit($search) ? (int) $search : null;

                if ($numericId) {
                    $q->orWhere('calls.id', $numericId);
                }

                $q->orWhere('calls.pbx_unique_id', 'like', "%{$search}%")
                    ->orWhere('calls.status', 'like', "%{$search}%")
                    ->orWhere('calls.direction', 'like', "%{$search}%")
                    ->orWhere('calls.from', 'like', "%{$search}%")
                    ->orWhere('calls.to', 'like', "%{$search}%");

                // If companies join is present, allow company-name searching.
                $q->orWhere('companies.name', 'like', "%{$search}%");
            });
        }

        if ($sort === 'company') {
            $query->orderBy('companies.name', $direction);
        } elseif ($sort === 'provider') {
            // Prefer provider name, then fallback to PBX account name.
            $query->orderBy('pbx_providers.name', $direction)
                ->orderBy('company_pbx_accounts.pbx_name', $direction);
        } else {
            $query->orderBy("calls.{$sort}", $direction);
        }

        // Stable ordering for consistent pagination.
        $query->orderBy('calls.id', 'desc');

        $paginator = $query->paginate($perPage)->appends($request->query());

        return response()->json([
            'data' => collect($paginator->items())->map(function (Call $call) {
                $providerName = $call->companyPbxAccount?->pbxProvider?->name
                    ?? $call->companyPbxAccount?->pbx_name
                    ?? '—';

                $statusRaw = (string) ($call->status ?? '');
                $status = strtolower($statusRaw);

                $category = in_array($status, ['completed', 'complete', 'success', 'answered'], true)
                    ? 'completed'
                    : (in_array($status, ['processing', 'queued', 'running', 'in_progress'], true)
                        ? 'processing'
                        : (in_array($status, ['failed', 'error', 'missed'], true) ? 'failed' : $status));

                if (! in_array($category, ['completed', 'processing', 'failed'], true)) {
                    // Default unknown statuses to processing (neutral-but-informative).
                    $category = 'processing';
                }

                return [
                    'id' => $call->id,
                    'callId' => $call->pbx_unique_id,
                    'company' => $call->company?->name ?? '—',
                    'provider' => $providerName,
                    'durationSeconds' => (int) ($call->duration_seconds ?? 0),
                    'status' => $category,
                    'createdAt' => optional($call->created_at)->toISOString(),
                ];
            })->values(),
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

    public function show(Request $request, string $idOrUid)
    {
        $callQuery = Call::query()
            ->with([
                'company:id,name,timezone,status',
                'companyPbxAccount:id,pbx_provider_id,pbx_name,company_id',
                'companyPbxAccount.pbxProvider:id,name,slug,status',
            ]);

        $call = ctype_digit($idOrUid)
            ? $callQuery->find((int) $idOrUid)
            : $callQuery->where('pbx_unique_id', $idOrUid)->first();

        if (! $call) {
            return response()->json([
                'message' => 'Call not found.',
            ], 404);
        }

        $providerName = $call->companyPbxAccount?->pbxProvider?->name
            ?? $call->companyPbxAccount?->pbx_name
            ?? '—';

        $callStatusRaw = (string) ($call->status ?? '');
        $callStatus = strtolower($callStatusRaw);
        $callStatusCategory = in_array($callStatus, ['completed', 'complete', 'success', 'answered'], true)
            ? 'completed'
            : (in_array($callStatus, ['processing', 'queued', 'running', 'in_progress'], true)
                ? 'processing'
                : (in_array($callStatus, ['failed', 'error', 'missed'], true) ? 'failed' : 'processing'));

        $transcriptionStatus = (bool) ($call->has_transcription ?? false) ? 'completed' : 'none';
        $transcriptionProvider = (bool) ($call->has_transcription ?? false) ? 'pbxware' : null;

        $jobHistory = collect();

        $jobHistory->push([
            'key' => 'ingestion',
            'type' => 'ingestion',
            'label' => 'Call ingested',
            'status' => 'completed',
            'occurredAt' => optional($call->created_at)->toISOString(),
            'detail' => $call->direction ? "Direction: {$call->direction}" : null,
        ]);

        if ((bool) ($call->has_transcription ?? false)) {
            $jobHistory->push([
                'key' => 'transcription',
                'type' => 'transcription',
                'label' => 'Transcription generated',
                'status' => 'completed',
                'occurredAt' => optional($call->updated_at ?? $call->created_at)->toISOString(),
                'detail' => 'Provider: pbxware',
            ]);
        }

        // AI jobs placeholder (read-only, empty state in UI when none)

        return response()->json([
            'call' => [
                'id' => $call->id,
                'callId' => $call->pbx_unique_id,
                'company' => $call->company?->name ?? '—',
                'provider' => $providerName,
                'durationSeconds' => (int) ($call->duration_seconds ?? 0),
                'status' => $callStatusCategory,
                'createdAt' => optional($call->created_at)->toISOString(),
                'startedAt' => optional($call->started_at)->toISOString(),
                'direction' => $call->direction,
                'from' => $call->from,
                'to' => $call->to,
            ],
            'transcription' => [
                'status' => $transcriptionStatus,
                'provider' => $transcriptionProvider,
                'hasTranscription' => (bool) ($call->has_transcription ?? false),
                'text' => $call->transcript_text,
            ],
            'jobHistory' => $jobHistory->values(),
            'metadata' => [
                'companyId' => $call->company_id,
                'companyTimezone' => $call->company?->timezone,
                'companyStatus' => $call->company?->status,
                'pbxAccountId' => $call->company_pbx_account_id,
                'pbxProviderId' => $call->companyPbxAccount?->pbx_provider_id,
                'pbxProviderSlug' => $call->companyPbxAccount?->pbxProvider?->slug,
                'pbxUniqueId' => $call->pbx_unique_id,
                'serverId' => $call->server_id,
            ],
        ]);
    }
}
