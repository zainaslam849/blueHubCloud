<?php

namespace App\Jobs;

use App\Models\Call;
use App\Models\CallTranscription;
use App\Services\Insights\CallInsightAnalyzer;
use App\Services\Normalization\PbxPayloadNormalizer;
use App\Services\Providers\PbxwareAdapter;
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

    private PbxwareAdapter $adapter;

    public function __construct()
    {
        $this->adapter = new PbxwareAdapter();
    }

    /**
     * Execute the job: fetch transcriptions for calls that have them available.
     */
    public function handle(): void
    {
        try {
            // Find calls with recordings but no transcription (limit to batch size)
            $calls = Call::where('has_transcription', true)
                ->whereNull('transcript_text')
                ->limit(self::BATCH_SIZE)
                ->get();

            if ($calls->isEmpty()) {
                Log::info('FetchTranscriptionsJob: No calls needing transcription');
                return;
            }

            Log::info("FetchTranscriptionsJob: Processing {$calls->count()} calls");

            foreach ($calls as $call) {
                $this->processCall($call);
            }

            // If we processed a full batch, re-queue to continue
            if ($calls->count() === self::BATCH_SIZE) {
                static::dispatch()->delay(now()->addSeconds(5));
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
    private function processCall(Call $call): void
    {
        try {
            // Fetch transcription from PBXware API
            $transcriptionData = $this->adapter->fetchTranscription(
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

            // Store transcription in database
            $transcription = CallTranscription::create($normalized);

            // Update call record with transcript
            $call->update([
                'transcript_text' => $normalized['transcript_text'],
                'has_transcription' => !empty($normalized['transcript_text']),
            ]);

            Log::info("FetchTranscriptionsJob: Stored transcription for call {$call->id}");

            // Dispatch insight analysis job to extract intent/department/deflection
            if (!empty($normalized['transcript_text'])) {
                InsightAnalysisJob::dispatch($call, $transcription);
            }
        } catch (\Exception $e) {
            Log::error("FetchTranscriptionsJob: Failed to process call {$call->id}", [
                'error' => $e->getMessage(),
            ]);

            // Don't rethrow - continue processing other calls in batch
        }
    }
}
