<?php

namespace App\Jobs;

use App\Models\Call;
use App\Models\CallTranscription;
use App\Services\Normalization\PbxPayloadNormalizer;
use App\Services\Providers\PbxwareAdapter;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * PHASE 4: TRANSCRIPTION INGESTION PIPELINE
 * 
 * Queue-based job that fetches transcriptions for calls with recordings.
 * 
 * Responsibilities:
 * 1. For each call without transcription:
 *    - Call pbxware.transcription.get API
 *    - Store raw payload in pbx_raw_payloads
 *    - If transcript found: save to call_transcriptions table
 *    - If not found: mark as no_transcription, retry with backoff
 * 2. Dispatch InsightAnalysisJob to analyze transcript
 * 
 * Design:
 * - Queue-based only (async via queue:work)
 * - Batch processing (50 calls per job run)
 * - Exponential backoff for transient failures
 * - No synchronous processing (non-blocking)
 */
class FetchTranscriptionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Number of calls to process per job execution
    private const BATCH_SIZE = 50;
    private const RETRY_COOLDOWN_SECONDS = 20;
    private const MAX_RETRY_ATTEMPTS = 5;

    // Maximum attempts before giving up
    public int $tries = 5;

    // Timeout in seconds
    public int $timeout = 300;

    // Exponential backoff: 1 min, 5 min, 15 min, 1 hour
    public array $backoff = [60, 300, 900, 3600];

    public function __construct(
        public ?int $companyId = null,
        public ?string $fromDate = null,
        public ?string $toDate = null,
        public ?int $pipelineRunId = null,
        public string $pipelineQueue = 'default',
        public int $summarizeLimit = 500,
        public int $categorizeLimit = 500,
    ) {}

    /**
     * Execute the job: fetch transcriptions for calls that have them available.
     */
    public function handle(): void
    {
        try {
            $stageStartedAt = microtime(true);
            Log::info('FetchTranscriptionsJob: stage_start', [
                'company_id' => $this->companyId,
                'from' => $this->fromDate,
                'to' => $this->toDate,
                'pipeline_run_id' => $this->pipelineRunId,
                'queue' => $this->pipelineQueue,
                'batch_size' => self::BATCH_SIZE,
                'job_id' => $this->job?->getJobId(),
                'attempt' => $this->attempts(),
                'event' => 'stage_start',
            ]);

            $adapter = app(PbxwareAdapter::class);

            $promotedCount = $this->promotePendingAnsweredCandidates();

            // Find answered calls still pending transcription verification.
            $callsQuery = Call::query()
                ->where('status', 'answered')
                ->where('has_transcription', true)
                ->whereNull('transcript_text')
                ->where(function ($q) {
                    $q->whereNull('transcription_checked_at')
                        ->orWhere('transcription_checked_at', '<=', now()->subSeconds(self::RETRY_COOLDOWN_SECONDS));
                });

            if ($this->companyId !== null) {
                $callsQuery->where('company_id', $this->companyId);
            }

            if ($this->fromDate !== null && $this->toDate !== null) {
                $callsQuery->whereBetween('started_at', [
                    Carbon::parse($this->fromDate)->startOfDay(),
                    Carbon::parse($this->toDate)->endOfDay(),
                ]);
            }

            $calls = $callsQuery
                ->orderBy('id')
                ->limit(self::BATCH_SIZE)
                ->get();

            Log::info('FetchTranscriptionsJob: candidate query executed', [
                'company_id' => $this->companyId,
                'from' => $this->fromDate,
                'to' => $this->toDate,
                'pipeline_run_id' => $this->pipelineRunId,
                'promoted_candidates' => $promotedCount,
                'candidate_count' => $calls->count(),
                'candidate_call_ids' => $calls->pluck('id')->take(10)->values()->all(),
                'job_id' => $this->job?->getJobId(),
                'attempt' => $this->attempts(),
                'event' => 'candidate_query',
            ]);

            if ($calls->isEmpty()) {
                Log::info('FetchTranscriptionsJob: No calls needing transcription', [
                    'company_id' => $this->companyId,
                    'from' => $this->fromDate,
                    'to' => $this->toDate,
                    'pipeline_run_id' => $this->pipelineRunId,
                ]);
                return;
            }

            Log::info("FetchTranscriptionsJob: Processing {$calls->count()} calls");

            foreach ($calls as $call) {
                $this->processCall($call, $adapter);
            }

            $remainingQuery = Call::query()
                ->where('status', 'answered')
                ->where('has_transcription', true)
                ->whereNull('transcript_text');

            if ($this->companyId !== null) {
                $remainingQuery->where('company_id', $this->companyId);
            }

            if ($this->fromDate !== null && $this->toDate !== null) {
                $remainingQuery->whereBetween('started_at', [
                    Carbon::parse($this->fromDate)->startOfDay(),
                    Carbon::parse($this->toDate)->endOfDay(),
                ]);
            }

            $remainingCount = $remainingQuery->count();
            Log::info('FetchTranscriptionsJob: batch_complete', [
                'company_id' => $this->companyId,
                'from' => $this->fromDate,
                'to' => $this->toDate,
                'pipeline_run_id' => $this->pipelineRunId,
                'processed_count' => $calls->count(),
                'remaining_pending' => $remainingCount,
                'elapsed_ms' => (int) round((microtime(true) - $stageStartedAt) * 1000),
                'job_id' => $this->job?->getJobId(),
                'attempt' => $this->attempts(),
                'event' => 'batch_complete',
            ]);

            // Re-queue only when pending candidates still exist.
            if ($remainingCount > 0) {
                static::dispatch(
                    $this->companyId,
                    $this->fromDate,
                    $this->toDate,
                    $this->pipelineRunId,
                    $this->pipelineQueue,
                    $this->summarizeLimit,
                    $this->categorizeLimit,
                )->onQueue($this->pipelineQueue)->delay(now()->addSeconds(5));
                Log::info('FetchTranscriptionsJob: retry_scheduled', [
                    'company_id' => $this->companyId,
                    'pipeline_run_id' => $this->pipelineRunId,
                    'remaining_pending' => $remainingCount,
                    'delay_seconds' => 5,
                    'job_id' => $this->job?->getJobId(),
                    'attempt' => $this->attempts(),
                    'event' => 'retry_scheduled',
                ]);
            }
        } catch (\Exception $e) {
            Log::error('FetchTranscriptionsJob: Batch processing failed', [
                'error' => $e->getMessage(),
                'job_id' => $this->job?->getJobId(),
                'attempt' => $this->attempts(),
                'event' => 'stage_failed',
            ]);

            // Rethrow to leverage queue retry logic
            throw $e;
        }
    }

    /**
     * Process a single call: fetch transcription and analyze.
     *
     * @param Call $call
     * @return void
     */
    private function processCall(Call $call, PbxwareAdapter $adapter): void
    {
        try {
            $callStartedAt = microtime(true);
            Log::info('FetchTranscriptionsJob: api request start', [
                'call_id' => $call->id,
                'company_id' => $call->company_id,
                'server_id' => $call->server_id,
                'pbx_unique_id' => $call->pbx_unique_id,
                'pipeline_run_id' => $this->pipelineRunId,
                'job_id' => $this->job?->getJobId(),
                'attempt' => $this->attempts(),
                'event' => 'api_request_start',
            ]);

            // Fetch transcription from PBXware API
            $transcriptionData = $adapter->fetchTranscription(
                serverId: $call->server_id,
                externalCallId: $call->pbx_unique_id
            );

            Log::info('FetchTranscriptionsJob: api response received', [
                'call_id' => $call->id,
                'server_id' => $call->server_id,
                'pbx_unique_id' => $call->pbx_unique_id,
                'payload_type' => gettype($transcriptionData),
                'top_level_keys' => is_array($transcriptionData) ? array_keys($transcriptionData) : [],
                'text_lengths' => [
                    'text' => is_string($transcriptionData['text'] ?? null) ? mb_strlen(trim($transcriptionData['text'])) : 0,
                    'transcript' => is_string($transcriptionData['transcript'] ?? null) ? mb_strlen(trim($transcriptionData['transcript'])) : 0,
                    'Transcript' => is_string($transcriptionData['Transcript'] ?? null) ? mb_strlen(trim($transcriptionData['Transcript'])) : 0,
                    'message' => is_string($transcriptionData['message'] ?? null) ? mb_strlen(trim($transcriptionData['message'])) : 0,
                    'result_text' => is_string(data_get($transcriptionData, 'result.text')) ? mb_strlen(trim((string) data_get($transcriptionData, 'result.text'))) : 0,
                    'result_transcript' => is_string(data_get($transcriptionData, 'result.transcript')) ? mb_strlen(trim((string) data_get($transcriptionData, 'result.transcript'))) : 0,
                ],
                'vtt_word_count' => is_array(data_get($transcriptionData, 'vtt.words')) ? count(data_get($transcriptionData, 'vtt.words')) : 0,
                'preview' => $this->extractPreview($transcriptionData),
                'elapsed_ms' => (int) round((microtime(true) - $callStartedAt) * 1000),
                'event' => 'api_response_received',
            ]);

            // If no transcription found, mark it and skip
            if (empty($transcriptionData)) {
                $attempt = $this->incrementRetryAttempts($call);
                $shouldTerminate = $attempt >= self::MAX_RETRY_ATTEMPTS;

                if ($shouldTerminate) {
                    $this->markVerificationTerminal($call, 'terminal_no_transcription', 'api_empty_response_max_retries');
                } else {
                    $this->markVerificationPending($call, 'api_empty_response_retry');
                }

                Log::warning('FetchTranscriptionsJob: terminal outcome api_empty_response_retry_later', [
                    'call_id' => $call->id,
                    'server_id' => $call->server_id,
                    'pbx_unique_id' => $call->pbx_unique_id,
                    'pipeline_run_id' => $this->pipelineRunId,
                    'retry_after_seconds' => self::RETRY_COOLDOWN_SECONDS,
                    'retry_attempt' => $attempt,
                    'max_retry_attempts' => self::MAX_RETRY_ATTEMPTS,
                    'terminal_after_max_retries' => $shouldTerminate,
                    'event' => 'terminal_state_set',
                ]);
                return;
            }

            // Normalize transcription payload
            $normalized = PbxPayloadNormalizer::normalizeTranscription(
                $transcriptionData,
                $call
            );

            $transcriptText = is_string($normalized['transcript_text'] ?? null)
                ? trim($normalized['transcript_text'])
                : '';

            Log::info('FetchTranscriptionsJob: normalized payload', [
                'call_id' => $call->id,
                'transcript_length' => mb_strlen($transcriptText),
                'confidence' => $normalized['transcript_confidence'] ?? 0,
                'pipeline_run_id' => $this->pipelineRunId,
                'event' => 'normalization_result',
            ]);

            if ($transcriptText === '') {
                $explicitNotFound = $this->isExplicitNoTranscription($transcriptionData);
                $attempt = $this->incrementRetryAttempts($call);
                $shouldTerminate = $explicitNotFound || $attempt >= self::MAX_RETRY_ATTEMPTS;

                if ($shouldTerminate) {
                    $reason = $explicitNotFound ? 'explicit_no_transcription' : 'unusable_text_max_retries';
                    $this->markVerificationTerminal($call, 'terminal_no_transcription', $reason);
                } else {
                    $this->markVerificationPending($call, 'unusable_text_retry');
                }

                Log::warning('FetchTranscriptionsJob: terminal outcome unusable_text_extraction', [
                    'call_id' => $call->id,
                    'server_id' => $call->server_id,
                    'pbx_unique_id' => $call->pbx_unique_id,
                    'payload_keys' => array_keys($transcriptionData),
                    'pipeline_run_id' => $this->pipelineRunId,
                    'preview' => $this->extractPreview($transcriptionData),
                    'explicit_not_found' => $explicitNotFound,
                    'retry_after_seconds' => $shouldTerminate ? null : self::RETRY_COOLDOWN_SECONDS,
                    'retry_attempt' => $attempt,
                    'max_retry_attempts' => self::MAX_RETRY_ATTEMPTS,
                    'terminal_after_max_retries' => $shouldTerminate,
                    'event' => 'terminal_state_set',
                ]);

                return;
            }

            $normalized['transcript_text'] = $transcriptText;

            // Store transcription in database (upsert for idempotency)
            $transcription = CallTranscription::query()->updateOrCreate(
                ['call_id' => $call->id],
                $normalized
            );

            Log::info('FetchTranscriptionsJob: transcription row persisted', [
                'call_id' => $call->id,
                'transcription_id' => $transcription->id,
                'transcript_length' => mb_strlen($transcriptText),
                'pipeline_run_id' => $this->pipelineRunId,
                'event' => 'persistence_result',
            ]);

            // Update call record with transcript
            $call->update([
                'transcript_text' => $transcriptText,
                'has_transcription' => true,
                'transcription_checked_at' => now(),
            ]);
            $this->markVerificationSaved($call, 'transcription_saved');
            $this->setRetryAttempts($call, 0);

            $call->refresh();

            if (! is_string($call->transcript_text) || trim($call->transcript_text) === '') {
                Log::error('FetchTranscriptionsJob: terminal outcome persistence_mismatch', [
                    'call_id' => $call->id,
                    'transcription_id' => $transcription->id,
                    'pipeline_run_id' => $this->pipelineRunId,
                ]);
            }

            Log::info('FetchTranscriptionsJob: terminal outcome transcription_saved', [
                'call_id' => $call->id,
                'characters' => mb_strlen($transcriptText),
                'pipeline_run_id' => $this->pipelineRunId,
                'elapsed_ms' => (int) round((microtime(true) - $callStartedAt) * 1000),
                'event' => 'terminal_state_set',
            ]);

            // Dispatch insight analysis job to extract intent/department/deflection
            InsightAnalysisJob::dispatch($call, $transcription);
        } catch (\Exception $e) {
            $attempt = $this->incrementRetryAttempts($call);
            $shouldTerminate = $attempt >= self::MAX_RETRY_ATTEMPTS;
            if ($shouldTerminate) {
                $this->markVerificationTerminal($call, 'terminal_error', 'exception_max_retries');
            } else {
                $this->markVerificationPending($call, 'exception_retry');
            }

            Log::error('FetchTranscriptionsJob: terminal outcome exception_failed', [
                'call_id' => $call->id,
                'company_id' => $call->company_id,
                'server_id' => $call->server_id,
                'pbx_unique_id' => $call->pbx_unique_id,
                'pipeline_run_id' => $this->pipelineRunId,
                'error' => $e->getMessage(),
                'retry_attempt' => $attempt,
                'max_retry_attempts' => self::MAX_RETRY_ATTEMPTS,
                'terminal_after_max_retries' => $shouldTerminate,
                'event' => 'terminal_state_set',
            ]);

            // Don't rethrow - continue processing other calls in batch
        }
    }

    private function extractPreview(array $payload): ?string
    {
        $candidates = [
            $payload['text'] ?? null,
            $payload['Transcript'] ?? null,
            $payload['transcript'] ?? null,
            $payload['message'] ?? null,
            data_get($payload, 'result.text'),
            data_get($payload, 'result.transcript'),
            data_get($payload, 'data.text'),
            data_get($payload, 'data.transcript'),
        ];

        foreach ($candidates as $candidate) {
            if (! is_string($candidate)) {
                continue;
            }

            $value = trim($candidate);
            if ($value === '') {
                continue;
            }

            return Str::limit(preg_replace('/\s+/', ' ', $value) ?? $value, 160, '...');
        }

        return null;
    }

    private function promotePendingAnsweredCandidates(): int
    {
        $query = Call::query()
            ->where('status', 'answered')
            ->where('has_transcription', false)
            ->whereNull('transcript_text');

        if ($this->companyId !== null) {
            $query->where('company_id', $this->companyId);
        }

        if ($this->fromDate !== null && $this->toDate !== null) {
            $query->whereBetween('started_at', [
                Carbon::parse($this->fromDate)->startOfDay(),
                Carbon::parse($this->toDate)->endOfDay(),
            ]);
        }

        $promoted = 0;

        $query->orderBy('id')->chunkById(200, function ($calls) use (&$promoted) {
            foreach ($calls as $call) {
                $call->has_transcription = true;
                $call->transcription_checked_at = null;
                $this->markVerificationPending($call, 'promoted_answered_candidate', false);
                $promoted++;
            }
        });

        return $promoted;
    }

    private function markVerificationPending(Call $call, string $reason, bool $setCheckedAt = true): void
    {
        $meta = is_array($call->pbx_metadata) ? $call->pbx_metadata : [];
        $meta['transcription_verification_status'] = 'pending';
        $meta['transcription_last_decision'] = $reason;
        $meta['transcription_last_decision_at'] = now()->toIso8601String();
        $call->has_transcription = true;
        $call->transcription_checked_at = $setCheckedAt ? now() : null;
        $call->pbx_metadata = $meta;
        $call->save();
    }

    private function markVerificationTerminal(Call $call, string $status, string $reason): void
    {
        $meta = is_array($call->pbx_metadata) ? $call->pbx_metadata : [];
        $meta['transcription_verification_status'] = $status;
        $meta['transcription_last_decision'] = $reason;
        $meta['transcription_last_decision_at'] = now()->toIso8601String();
        $call->has_transcription = false;
        $call->transcription_checked_at = now();
        $call->pbx_metadata = $meta;
        $call->save();
    }

    private function markVerificationSaved(Call $call, string $reason): void
    {
        $meta = is_array($call->pbx_metadata) ? $call->pbx_metadata : [];
        $meta['transcription_verification_status'] = 'saved';
        $meta['transcription_last_decision'] = $reason;
        $meta['transcription_last_decision_at'] = now()->toIso8601String();
        $call->pbx_metadata = $meta;
        $call->save();
    }

    private function incrementRetryAttempts(Call $call): int
    {
        $meta = is_array($call->pbx_metadata) ? $call->pbx_metadata : [];
        $attempt = (int) ($meta['transcription_retry_attempts'] ?? 0) + 1;
        $meta['transcription_retry_attempts'] = $attempt;
        $meta['transcription_last_attempt_at'] = now()->toIso8601String();

        $call->pbx_metadata = $meta;
        $call->save();

        return $attempt;
    }

    private function setRetryAttempts(Call $call, int $attempt): void
    {
        $meta = is_array($call->pbx_metadata) ? $call->pbx_metadata : [];
        $meta['transcription_retry_attempts'] = max(0, $attempt);
        $meta['transcription_last_attempt_at'] = now()->toIso8601String();

        $call->pbx_metadata = $meta;
        $call->save();
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
}
