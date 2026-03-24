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
                ->whereNull('transcript_text');

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
            // Fetch transcription from PBXware API
            $transcriptionData = $adapter->fetchTranscription(
                serverId: $call->server_id,
                externalCallId: $call->pbx_unique_id
            );

            // If no transcription found, mark it and skip
            if (empty($transcriptionData)) {
                $call->update([
                    'has_transcription' => false,
                ]);
                Log::info("FetchTranscriptionsJob: No transcription found for call {$call->id}");
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

            if ($transcriptText === '') {
                $call->update([
                    'transcription_checked_at' => now(),
                    'has_transcription' => false,
                ]);

                Log::warning("FetchTranscriptionsJob: Transcription payload had no usable text for call {$call->id}", [
                    'call_id' => $call->id,
                    'server_id' => $call->server_id,
                    'pbx_unique_id' => $call->pbx_unique_id,
                    'payload_keys' => array_keys($transcriptionData),
                ]);

                return;
            }

            $normalized['transcript_text'] = $transcriptText;

            // Store transcription in database (upsert for idempotency)
            $transcription = CallTranscription::query()->updateOrCreate(
                ['call_id' => $call->id],
                $normalized
            );

            // Update call record with transcript
            $call->update([
                'transcript_text' => $transcriptText,
                'has_transcription' => true,
                'transcription_checked_at' => now(),
            ]);

            Log::info("FetchTranscriptionsJob: Stored transcription for call {$call->id}", [
                'call_id' => $call->id,
                'characters' => mb_strlen($transcriptText),
            ]);

            // Dispatch insight analysis job to extract intent/department/deflection
            InsightAnalysisJob::dispatch($call, $transcription);
        } catch (\Exception $e) {
            Log::error("FetchTranscriptionsJob: Failed to process call {$call->id}", [
                'error' => $e->getMessage(),
            ]);

            // Don't rethrow - continue processing other calls in batch
        }
    }
}
