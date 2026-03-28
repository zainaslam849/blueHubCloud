<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\CategorizeSingleCallJob;
use App\Jobs\GenerateWeeklyPbxReportsJob;
use App\Jobs\SummarizeSingleCallJob;
use App\Models\Call;
use Carbon\CarbonImmutable;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;

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
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'category_id' => ['nullable', 'integer', 'exists:call_categories,id'],
            'source' => ['nullable', 'in:ai,manual,default'],
            'confidence_min' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'confidence_max' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
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
                'companyPbxAccount:id,pbx_provider_id,company_id',
                'companyPbxAccount.pbxProvider:id,name',
                'category:id,name',
                'subCategory:id,name',
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

        // Company filter
        if (isset($validated['company_id'])) {
            $query->where('calls.company_id', $validated['company_id']);
        }

        // Category filters
        if (isset($validated['category_id'])) {
            $query->where('calls.category_id', $validated['category_id']);
        }

        if (isset($validated['source'])) {
            $query->where('calls.category_source', $validated['source']);
        }

        if (isset($validated['confidence_min'])) {
            $query->where('calls.category_confidence', '>=', $validated['confidence_min']);
        }

        if (isset($validated['confidence_max'])) {
            $query->where('calls.category_confidence', '<=', $validated['confidence_max']);
        }

        if (isset($validated['start_date'])) {
            $startDate = Carbon::parse($validated['start_date'])->startOfDay();
            $query->where('calls.created_at', '>=', $startDate);
        }

        if (isset($validated['end_date'])) {
            $endDate = Carbon::parse($validated['end_date'])->endOfDay();
            $query->where('calls.created_at', '<=', $endDate);
        }

        if ($sort === 'company') {
            $query->orderBy('companies.name', $direction);
        } elseif ($sort === 'provider') {
            $query->orderBy('pbx_providers.name', $direction);
        } else {
            $query->orderBy("calls.{$sort}", $direction);
        }

        // Stable ordering for consistent pagination.
        $query->orderBy('calls.id', 'desc');

        $paginator = $query->paginate($perPage)->appends($request->query());

        return response()->json([
            'data' => collect($paginator->items())->map(function (Call $call) {
                $providerName = $call->companyPbxAccount?->pbxProvider?->name ?? '—';

                $aiRecovery = $this->buildAiRecoveryState($call);

                return [
                    'id' => $call->id,
                    'callId' => $call->pbx_unique_id,
                    'company' => $call->company?->name ?? '—',
                    'provider' => $providerName,
                    'durationSeconds' => (int) ($call->duration_seconds ?? 0),
                    'status' => $this->normalizeOperationalStatus($call->status),
                    'hasTranscription' => (bool) ($call->has_transcription ?? false),
                    'transcriptSnippet' => $call->transcript_text ? mb_substr($call->transcript_text, 0, 160) : null,
                    'createdAt' => optional($call->created_at)->toISOString(),
                    'category' => $call->category?->name,
                    'categoryId' => $call->category_id,
                    'subCategory' => $call->subCategory?->name ?? $call->sub_category_label,
                    'categorySource' => $call->category_source,
                    'categoryConfidence' => $call->category_confidence,
                    'aiSummaryStatus' => $call->ai_summary_status,
                    'aiCategoryStatus' => $call->ai_category_status,
                    'hasAiSummary' => $this->hasAiSummary($call),
                    'aiRecovery' => $aiRecovery,
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
        $call = $this->resolveCall($idOrUid, [
            'company:id,name,timezone,status',
            'companyPbxAccount:id,pbx_provider_id,company_id',
            'companyPbxAccount.pbxProvider:id,name,slug,status',
            'category:id,name',
            'subCategory:id,name',
        ]);

        if (! $call) {
            return response()->json([
                'message' => 'Call not found.',
            ], 404);
        }

        $providerName = $call->companyPbxAccount?->pbxProvider?->name ?? '—';

        $transcriptionStatus = (bool) ($call->has_transcription ?? false) ? 'completed' : 'none';
        $transcriptionProvider = (bool) ($call->has_transcription ?? false) ? 'pbxware' : null;
        $aiRecovery = $this->buildAiRecoveryState($call);

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

        if (is_string($call->ai_summary) && trim($call->ai_summary) !== '') {
            $jobHistory->push([
                'key' => 'summary',
                'type' => 'summary',
                'label' => 'AI summary generated',
                'status' => 'completed',
                'occurredAt' => optional($call->updated_at ?? $call->created_at)->toISOString(),
                'detail' => 'Source: AI',
            ]);
        } elseif ($call->ai_summary_status) {
            $jobHistory->push([
                'key' => 'summary-status',
                'type' => 'summary',
                'label' => 'AI summary pending review',
                'status' => $this->normalizeAiStageStatus($call->ai_summary_status),
                'occurredAt' => optional($call->updated_at ?? $call->created_at)->toISOString(),
                'detail' => $this->humanizeAiStatus($call->ai_summary_status),
            ]);
        }

        if ($call->category_id) {
            $jobHistory->push([
                'key' => 'categorization',
                'type' => 'categorization',
                'label' => 'AI categorization generated',
                'status' => 'completed',
                'occurredAt' => optional($call->categorized_at ?? $call->updated_at ?? $call->created_at)->toISOString(),
                'detail' => $call->category?->name
                    ? 'Category: '.$call->category->name
                    : 'Category assigned',
            ]);
        } elseif ($call->ai_category_status) {
            $jobHistory->push([
                'key' => 'categorization-status',
                'type' => 'categorization',
                'label' => 'AI categorization pending review',
                'status' => $this->normalizeAiStageStatus($call->ai_category_status),
                'occurredAt' => optional($call->updated_at ?? $call->created_at)->toISOString(),
                'detail' => $this->humanizeAiStatus($call->ai_category_status),
            ]);
        }

        return response()->json([
            'call' => [
                'id' => $call->id,
                'callId' => $call->pbx_unique_id,
                'company' => $call->company?->name ?? '—',
                'provider' => $providerName,
                'durationSeconds' => (int) ($call->duration_seconds ?? 0),
                'status' => $this->normalizeOperationalStatus($call->status),
                'createdAt' => optional($call->created_at)->toISOString(),
                'startedAt' => optional($call->started_at)->toISOString(),
                'direction' => $call->direction,
                'from' => $call->from,
                'to' => $call->to,
                'aiSummary' => $call->ai_summary,
                'aiSummaryStatus' => $call->ai_summary_status,
                'aiCategoryStatus' => $call->ai_category_status,
                'category' => $call->category?->name,
                'subCategory' => $call->subCategory?->name ?? $call->sub_category_label,
                'categoryConfidence' => $call->category_confidence,
            ],
            'transcription' => [
                'status' => $transcriptionStatus,
                'provider' => $transcriptionProvider,
                'hasTranscription' => (bool) ($call->has_transcription ?? false),
                'text' => $call->transcript_text,
            ],
            'aiRecovery' => $aiRecovery,
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

    public function regenerate(Request $request, string $idOrUid): JsonResponse
    {
        $call = $this->resolveCall($idOrUid, [
            'company:id,timezone',
        ]);

        if (! $call) {
            return response()->json([
                'message' => 'Call not found.',
            ], 404);
        }

        $aiRecovery = $this->buildAiRecoveryState($call);

        if (! $aiRecovery['hasTranscript']) {
            return response()->json([
                'message' => 'Transcript is not available for that call.',
                'data' => [
                    'call_id' => $call->id,
                    'action' => 'none',
                ],
            ], 422);
        }

        if (! $aiRecovery['canRegenerate']) {
            return response()->json([
                'message' => 'AI summary and category are already available for this call.',
                'data' => [
                    'call_id' => $call->id,
                    'action' => 'none',
                ],
            ]);
        }

        $shouldGenerateSummary = $aiRecovery['action'] === 'summary_and_category';
        $shouldGenerateCategory = in_array($aiRecovery['action'], ['summary_and_category', 'category_only'], true);

        if ($shouldGenerateSummary) {
            $call->ai_summary = null;
            $call->ai_summary_status = 'queued';
        }

        if ($shouldGenerateCategory) {
            $call->category_id = null;
            $call->sub_category_id = null;
            $call->sub_category_label = null;
            $call->category_source = null;
            $call->category_confidence = null;
            $call->categorized_at = null;
            $call->ai_category_status = 'queued';
        }

        $call->save();

        $refreshRange = $this->resolveRefreshRange($call);

        $jobs = [];
        if ($shouldGenerateSummary) {
            $jobs[] = new SummarizeSingleCallJob($call->id);
        }
        if ($shouldGenerateCategory) {
            $jobs[] = new CategorizeSingleCallJob($call->id);
        }
        $jobs[] = new GenerateWeeklyPbxReportsJob(
            $refreshRange['from_date'],
            $refreshRange['to_date'],
            null,
            (int) $call->company_id,
        );

        Bus::chain($jobs)
            ->onQueue('default')
            ->dispatch();

        return response()->json([
            'message' => $shouldGenerateSummary
                ? 'AI summary and categorization have been queued for this call.'
                : 'AI categorization has been queued for this call.',
            'data' => [
                'call_id' => $call->id,
                'action' => $aiRecovery['action'],
                'from_date' => $refreshRange['from_date'],
                'to_date' => $refreshRange['to_date'],
            ],
        ], 202);
    }

    /**
     * Delete a call (soft delete).
     */
    public function destroy(Request $request, string $idOrUid): JsonResponse
    {
        $call = $this->resolveCall($idOrUid, [
            'company:id,name',
        ]);

        if (! $call) {
            return response()->json([
                'message' => 'Call not found.',
            ], 404);
        }

        // Log the deletion for audit purposes
        \Illuminate\Support\Facades\Log::info('Call deleted via admin panel', [
            'call_id' => $call->id,
            'call_uid' => $call->pbx_unique_id,
            'company_id' => $call->company_id,
            'company_name' => $call->company?->name,
            'deleted_by' => $request->user()?->id,
            'deleted_at' => now(),
        ]);

        // Perform soft delete
        $call->delete();

        return response()->json([
            'message' => 'Call deleted successfully.',
            'data' => [
                'id' => $call->id,
                'callId' => $call->pbx_unique_id,
                'deletedAt' => $call->deleted_at?->toISOString(),
            ],
        ], 200);
    }

    private function resolveCall(string $idOrUid, array $with = []): ?Call
    {
        $query = Call::query()->with($with);

        return ctype_digit($idOrUid)
            ? $query->find((int) $idOrUid)
            : $query->where('pbx_unique_id', $idOrUid)->first();
    }

    private function normalizeOperationalStatus(?string $statusRaw): string
    {
        $status = strtolower((string) $statusRaw);

        $normalized = in_array($status, ['completed', 'complete', 'success', 'answered'], true)
            ? 'completed'
            : (in_array($status, ['processing', 'queued', 'running', 'in_progress'], true)
                ? 'processing'
                : (in_array($status, ['failed', 'error', 'missed'], true) ? 'failed' : $status));

        if (! in_array($normalized, ['completed', 'processing', 'failed'], true)) {
            return 'processing';
        }

        return $normalized;
    }

    private function hasUsableTranscript(Call $call): bool
    {
        return is_string($call->transcript_text) && trim($call->transcript_text) !== '';
    }

    private function hasAiSummary(Call $call): bool
    {
        return is_string($call->ai_summary) && trim($call->ai_summary) !== '';
    }

    private function buildAiRecoveryState(Call $call): array
    {
        $hasTranscript = $this->hasUsableTranscript($call);
        $hasSummary = $this->hasAiSummary($call);
        $hasCategory = $call->category_id !== null;
        $summaryStatus = (string) ($call->ai_summary_status ?? '');
        $categoryStatus = (string) ($call->ai_category_status ?? '');

        if (! $hasTranscript) {
            return [
                'hasTranscript' => false,
                'hasSummary' => $hasSummary,
                'hasCategory' => $hasCategory,
                'canRegenerate' => false,
                'action' => 'none',
                'actionLabel' => null,
                'statusText' => 'Transcript is not available for that call.',
            ];
        }

        if (! $hasSummary && in_array($summaryStatus, ['queued', 'running'], true)) {
            return [
                'hasTranscript' => true,
                'hasSummary' => false,
                'hasCategory' => $hasCategory,
                'canRegenerate' => false,
                'action' => 'processing',
                'actionLabel' => null,
                'statusText' => 'AI summary and category generation are already queued for this call.',
            ];
        }

        if (! $hasSummary) {
            return [
                'hasTranscript' => true,
                'hasSummary' => false,
                'hasCategory' => $hasCategory,
                'canRegenerate' => true,
                'action' => 'summary_and_category',
                'actionLabel' => 'Generate summary + category',
                'statusText' => 'Transcript is available. Generate AI summary first, then AI category.',
            ];
        }

        if (! $hasCategory && in_array($categoryStatus, ['queued', 'running'], true)) {
            return [
                'hasTranscript' => true,
                'hasSummary' => true,
                'hasCategory' => false,
                'canRegenerate' => false,
                'action' => 'processing',
                'actionLabel' => null,
                'statusText' => 'AI category generation is already queued for this call.',
            ];
        }

        if (! $hasCategory) {
            return [
                'hasTranscript' => true,
                'hasSummary' => true,
                'hasCategory' => false,
                'canRegenerate' => true,
                'action' => 'category_only',
                'actionLabel' => 'Generate category',
                'statusText' => 'AI summary is available. Generate AI category for this call.',
            ];
        }

        return [
            'hasTranscript' => true,
            'hasSummary' => true,
            'hasCategory' => true,
            'canRegenerate' => false,
            'action' => 'complete',
            'actionLabel' => null,
            'statusText' => 'AI summary and category are already available for this call.',
        ];
    }

    private function normalizeAiStageStatus(?string $status): string
    {
        return match ((string) $status) {
            'completed' => 'completed',
            'queued', 'running' => 'processing',
            'credit_exhausted', 'not_generated' => 'failed',
            default => 'processing',
        };
    }

    private function humanizeAiStatus(?string $status): string
    {
        return match ((string) $status) {
            'queued' => 'Queued for regeneration',
            'running' => 'Currently processing',
            'credit_exhausted' => 'AI credits exhausted',
            'not_generated' => 'Not generated automatically',
            'completed' => 'Completed',
            default => 'Pending',
        };
    }

    private function resolveRefreshRange(Call $call): array
    {
        $timezone = is_string($call->company?->timezone ?? null) && $call->company->timezone !== ''
            ? $call->company->timezone
            : 'UTC';

        $startedAt = $call->started_at
            ? CarbonImmutable::parse($call->started_at)->setTimezone($timezone)
            : CarbonImmutable::now($timezone);

        return [
            'from_date' => $startedAt->startOfWeek(CarbonImmutable::MONDAY)->toDateString(),
            'to_date' => $startedAt->endOfWeek(CarbonImmutable::SUNDAY)->toDateString(),
        ];
    }
}
