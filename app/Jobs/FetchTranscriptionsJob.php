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
    private const RETRY_COOLDOWN_MINUTES = 30;

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
            $adapter = app(PbxwareAdapter::class);

            // Find calls with recordings but no transcription (limit to batch size)
            $callsQuery = Call::query()
                ->where('has_transcription', true)
                ->whereNull('transcript_text')
                ->where(function ($q) {
                    $q->whereNull('transcription_checked_at')
                        ->orWhere('transcription_checked_at', '<=', now()->subMinutes(self::RETRY_COOLDOWN_MINUTES));
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
                'candidate_count' => $calls->count(),
                'candidate_call_ids' => $calls->pluck('id')->take(10)->values()->all(),
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

            // If we processed a full batch, re-queue to continue
            if ($calls->count() === self::BATCH_SIZE) {
                static::dispatch(
                    $this->companyId,
                    $this->fromDate,
                    $this->toDate,
                    $this->pipelineRunId,
                    $this->pipelineQueue,
                    $this->summarizeLimit,
                    $this->categorizeLimit,
                )->onQueue($this->pipelineQueue)->delay(now()->addSeconds(5));
                Log::info('FetchTranscriptionsJob: Re-queued for next batch');
            }
        } catch (\Exception $e) {
            Log::error('FetchTranscriptionsJob: Batch processing failed', [
                'error' => $e->getMessage(),
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
            Log::info('FetchTranscriptionsJob: api request start', [
                'call_id' => $call->id,
                'company_id' => $call->company_id,
                'server_id' => $call->server_id,
                'pbx_unique_id' => $call->pbx_unique_id,
                'pipeline_run_id' => $this->pipelineRunId,
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
            ]);

            // If no transcription found, mark it and skip
            if (empty($transcriptionData)) {
                $call->update([
                    'transcription_checked_at' => now(),
                ]);
                Log::warning('FetchTranscriptionsJob: terminal outcome api_empty_response_retry_later', [
                    'call_id' => $call->id,
                    'server_id' => $call->server_id,
                    'pbx_unique_id' => $call->pbx_unique_id,
                    'pipeline_run_id' => $this->pipelineRunId,
                    'retry_after_minutes' => self::RETRY_COOLDOWN_MINUTES,
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
            ]);

            if ($transcriptText === '') {
                $explicitNotFound = $this->isExplicitNoTranscription($transcriptionData);

                $call->update([
                    'transcription_checked_at' => now(),
                    'has_transcription' => $explicitNotFound ? false : true,
                ]);

                Log::warning('FetchTranscriptionsJob: terminal outcome unusable_text_extraction', [
                    'call_id' => $call->id,
                    'server_id' => $call->server_id,
                    'pbx_unique_id' => $call->pbx_unique_id,
                    'payload_keys' => array_keys($transcriptionData),
                    'pipeline_run_id' => $this->pipelineRunId,
                    'preview' => $this->extractPreview($transcriptionData),
                    'explicit_not_found' => $explicitNotFound,
                    'retry_after_minutes' => $explicitNotFound ? null : self::RETRY_COOLDOWN_MINUTES,
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
            ]);

            // Update call record with transcript
            $call->update([
                'transcript_text' => $transcriptText,
                'has_transcription' => true,
                'transcription_checked_at' => now(),
            ]);

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
            ]);

            // Dispatch insight analysis job to extract intent/department/deflection
            InsightAnalysisJob::dispatch($call, $transcription);
        } catch (\Exception $e) {
            Log::error('FetchTranscriptionsJob: terminal outcome exception_failed', [
                'call_id' => $call->id,
                'company_id' => $call->company_id,
                'server_id' => $call->server_id,
                'pbx_unique_id' => $call->pbx_unique_id,
                'pipeline_run_id' => $this->pipelineRunId,
                'error' => $e->getMessage(),
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
