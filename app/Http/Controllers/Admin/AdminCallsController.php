<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\CategorizeSingleCallJob;
use App\Jobs\GenerateWeeklyPbxReportsJob;
use App\Jobs\SummarizeSingleCallJob;
use App\Models\Call;
use App\Models\CallTranscription;
use App\Services\Admin\AdminCallPresenter;
use App\Services\Admin\AdminCallsIndexQueryService;
use App\Services\Normalization\PbxPayloadNormalizer;
use App\Services\Providers\PbxwareAdapter;
use Carbon\CarbonImmutable;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

class AdminCallsController extends Controller
{
    public function __construct(
        private readonly AdminCallPresenter $presenter,
    ) {}

    public function index(Request $request, AdminCallsIndexQueryService $queryService)
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

        $result = $queryService->paginate($validated);
        $paginator = $result['paginator']->appends($request->query());
        $sort = $result['sort'];
        $direction = $result['direction'];

        return response()->json([
            'data' => collect($paginator->items())
                ->map(fn (Call $call) => $this->presenter->toIndexRow($call))
                ->values(),
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
        $aiRecovery = $this->presenter->buildAiRecoveryState($call);

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
                    'status' => $this->presenter->normalizeOperationalStatus($call->status),
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

        $aiRecovery = $this->presenter->buildAiRecoveryState($call);

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
     * Check whether transcript is now available for a call and, if found,
     * restart AI pipeline from transcript -> summary -> category -> report refresh.
     */
    public function checkTranscript(Request $request, string $idOrUid): JsonResponse
    {
        $call = $this->resolveCall($idOrUid, [
            'company:id,timezone',
        ]);

        if (! $call) {
            return response()->json([
                'message' => 'Call not found.',
            ], 404);
        }

        if ($this->hasUsableTranscript($call)) {
            return response()->json([
                'found' => true,
                'message' => 'Transcript already available for this call.',
                'data' => [
                    'call_id' => $call->id,
                ],
            ], 200);
        }

        if (! $call->server_id || ! $call->pbx_unique_id) {
            return response()->json([
                'found' => false,
                'message' => 'Call is missing PBX identifiers.',
                'data' => [
                    'call_id' => $call->id,
                ],
            ], 422);
        }

        $adapter = app(PbxwareAdapter::class);

        try {
            $transcriptionData = $adapter->fetchTranscription(
                serverId: (string) $call->server_id,
                externalCallId: (string) $call->pbx_unique_id,
            );
        } catch (\Throwable $e) {
            Log::warning('AdminCallsController: checkTranscript fetch failed', [
                'call_id' => $call->id,
                'server_id' => $call->server_id,
                'pbx_unique_id' => $call->pbx_unique_id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'found' => false,
                'message' => 'Failed to contact PBX server. Please try again later.',
                'data' => [
                    'call_id' => $call->id,
                ],
            ], 502);
        }

        if (empty($transcriptionData) || $this->isExplicitNoTranscription($transcriptionData)) {
            $call->has_transcription = false;
            $call->transcription_status = 'skipped';
            $call->transcription_checked_at = now();
            $call->save();

            return response()->json([
                'found' => false,
                'message' => 'Transcript is not available on the PBX server for this call.',
                'data' => [
                    'call_id' => $call->id,
                ],
            ], 200);
        }

        $normalized = PbxPayloadNormalizer::normalizeTranscription($transcriptionData, $call);

        $transcriptText = is_string($normalized['transcript_text'] ?? null)
            ? trim($normalized['transcript_text'])
            : '';

        if ($transcriptText === '') {
            $call->has_transcription = false;
            $call->transcription_status = 'skipped';
            $call->transcription_checked_at = now();
            $call->save();

            return response()->json([
                'found' => false,
                'message' => 'Transcript data was returned but no usable text could be extracted.',
                'data' => [
                    'call_id' => $call->id,
                ],
            ], 200);
        }

        $normalized['transcript_text'] = $transcriptText;

        CallTranscription::query()->updateOrCreate(
            ['call_id' => $call->id],
            $normalized
        );

        // Reset downstream AI state so the chain restarts from summary.
        $call->transcript_text = $transcriptText;
        $call->has_transcription = true;
        $call->transcription_status = 'saved';
        $call->transcription_checked_at = now();
        $call->ai_summary = null;
        $call->ai_summary_status = 'queued';
        $call->category_id = null;
        $call->sub_category_id = null;
        $call->sub_category_label = null;
        $call->category_source = null;
        $call->category_confidence = null;
        $call->categorized_at = null;
        $call->ai_category_status = 'queued';
        $call->save();

        $refreshRange = $this->resolveRefreshRange($call);

        Bus::chain([
            new SummarizeSingleCallJob($call->id),
            new CategorizeSingleCallJob($call->id),
            new GenerateWeeklyPbxReportsJob(
                $refreshRange['from_date'],
                $refreshRange['to_date'],
                null,
                (int) $call->company_id,
            ),
        ])
            ->onQueue('default')
            ->dispatch();

        Log::info('AdminCallsController: transcript found, AI pipeline queued', [
            'call_id' => $call->id,
            'server_id' => $call->server_id,
            'pbx_unique_id' => $call->pbx_unique_id,
            'transcript_length' => mb_strlen($transcriptText),
        ]);

        return response()->json([
            'found' => true,
            'message' => 'Transcript found. AI summary and categorization have been queued.',
            'data' => [
                'call_id' => $call->id,
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

    private function hasUsableTranscript(Call $call): bool
    {
        return is_string($call->transcript_text) && trim($call->transcript_text) !== '';
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

    private function isExplicitNoTranscription(array $payload): bool
    {
        $candidates = [
            $payload['message'] ?? null,
            $payload['error'] ?? null,
            $payload['status'] ?? null,
            data_get($payload, 'result.message'),
            data_get($payload, 'result.error'),
            data_get($payload, 'data.message'),
            data_get($payload, 'data.error'),
        ];

        foreach ($candidates as $candidate) {
            if (! is_string($candidate)) {
                continue;
            }

            $value = strtolower(trim($candidate));
            if ($value === '') {
                continue;
            }

            if (str_contains($value, 'no transcription')
                || str_contains($value, 'transcription not found')
                || str_contains($value, 'not found')) {
                return true;
            }
        }

        return false;
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
