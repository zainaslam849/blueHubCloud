<?php

namespace App\Jobs;

use App\Models\CompanyPbxAccount;
use App\Models\PipelineRun;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class AdminTestPipelineJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 60; // Just for dispatching, actual work is async

    private const STAGE_CALL_DISCOVERY = 'call_discovery';
    private const STAGE_TRANSCRIPTION_FETCH = 'transcription_fetch';
    private const STAGE_AI_SUMMARY = 'ai_summary';
    private const STAGE_CATEGORY_GENERATION = 'category_generation';
    private const STAGE_CALL_CATEGORIZATION = 'call_categorization';
    private const STAGE_REPORT_GENERATION = 'report_generation';

    private ?PipelineRun $pipelineRun = null;
    private string $activeStage = self::STAGE_CALL_DISCOVERY;

    public function __construct(
        public int $companyId,
        public string $fromDate,
        public string $toDate,
        public int $summarizeLimit = 500,
        public int $categorizeLimit = 500,
        public string $pipelineQueue = 'default',
        public ?int $pipelineRunId = null,
        public bool $isResume = false,
    ) {}

    public function handle(): void
    {
        $this->pipelineRun = $this->pipelineRunId
            ? PipelineRun::query()->with('stages')->find($this->pipelineRunId)
            : null;

        Log::info('AdminTestPipelineJob::handle() - STARTING', [
            'company_id' => $this->companyId,
            'from' => $this->fromDate,
            'to' => $this->toDate,
            'queue' => $this->pipelineQueue,
            'pipeline_run_id' => $this->pipelineRun?->id,
            'is_resume' => $this->isResume,
        ]);

        $to = CarbonImmutable::parse($this->toDate, 'UTC')->toDateString();
        $from = CarbonImmutable::parse($this->fromDate, 'UTC')->toDateString();
        $rangeDays = max(1, CarbonImmutable::parse($from, 'UTC')->diffInDays(CarbonImmutable::parse($to, 'UTC')));

        Log::info('AdminTestPipelineJob::handle() - Date range', ['from' => $from, 'to' => $to]);

        // STEP 1: Ingest calls (synchronous - must complete first)
        Log::info('AdminTestPipelineJob - Pipeline Step 1: Ingesting calls...', ['company_id' => $this->companyId]);
        $skipCallDiscovery = $this->shouldSkipStage(self::STAGE_CALL_DISCOVERY);
        if (! $skipCallDiscovery) {
            $this->startStage(self::STAGE_CALL_DISCOVERY);
        }

        $accounts = CompanyPbxAccount::query()
            ->where('company_id', $this->companyId)
            ->where('status', 'active')
            ->get(['id']);

        Log::info('AdminTestPipelineJob - Step 1: Found accounts to ingest', [
            'company_id' => $this->companyId,
            'account_count' => $accounts->count(),
        ]);

        if ($skipCallDiscovery) {
            Log::info('AdminTestPipelineJob - Skipping call discovery stage during resume', [
                'company_id' => $this->companyId,
                'pipeline_run_id' => $this->pipelineRun?->id,
            ]);
        } else {
            $discoveryMetrics = [
                'ingest_accounts' => $accounts->count(),
                'calls_created' => 0,
                'calls_skipped_existing' => 0,
                'split_window_retries' => 0,
                'strict_lossless_discovery' => true,
                'transcription_attempts' => 0,
                'transcriptions_stored' => 0,
                'transcription_skipped_no_recording' => 0,
                'transcription_not_found' => 0,
            ];

            foreach ($accounts as $account) {
                Log::info('AdminTestPipelineJob - Dispatching ingest for account', ['account_id' => $account->id]);
                $ingestResult = IngestPbxCallsJob::dispatchSync(
                    $this->companyId,
                    $account->id,
                    ['from' => $from, 'to' => $to]
                );

                if (is_array($ingestResult)) {
                    $discoveryMetrics['calls_created'] += (int) ($ingestResult['calls_created'] ?? 0);
                    $discoveryMetrics['calls_skipped_existing'] += (int) ($ingestResult['calls_skipped_existing'] ?? 0);
                    $discoveryMetrics['split_window_retries'] += (int) ($ingestResult['split_window_retries'] ?? 0);
                    $discoveryMetrics['strict_lossless_discovery'] =
                        $discoveryMetrics['strict_lossless_discovery']
                        && (bool) ($ingestResult['strict_lossless_discovery'] ?? true);
                    $discoveryMetrics['transcription_attempts'] += (int) ($ingestResult['transcription_attempts'] ?? 0);
                    $discoveryMetrics['transcriptions_stored'] += (int) ($ingestResult['transcriptions_stored'] ?? 0);
                    $discoveryMetrics['transcription_skipped_no_recording'] += (int) ($ingestResult['transcription_skipped_no_recording'] ?? 0);
                    $discoveryMetrics['transcription_not_found'] += (int) ($ingestResult['transcription_not_found'] ?? 0);
                }
            }

            $this->completeStage(self::STAGE_CALL_DISCOVERY, $discoveryMetrics);
        }

        if (! $this->shouldSkipStage(self::STAGE_TRANSCRIPTION_FETCH)) {
            $this->startStage(self::STAGE_TRANSCRIPTION_FETCH);

            $transcriptionCandidates = \App\Models\Call::query()
                ->where('company_id', $this->companyId)
                ->where('status', 'answered')
                ->whereBetween('started_at', [
                    CarbonImmutable::parse($from, 'UTC')->startOfDay(),
                    CarbonImmutable::parse($to, 'UTC')->endOfDay(),
                ])
                ->count();

            FetchTranscriptionsJob::dispatch(
                $this->companyId,
                $from,
                $to,
                $this->pipelineRun?->id,
                $this->pipelineQueue,
                $this->summarizeLimit,
                $this->categorizeLimit,
            )->onQueue($this->pipelineQueue);
            $this->completeStage(self::STAGE_TRANSCRIPTION_FETCH, [
                'mode' => 'queued',
                'candidate_total' => $transcriptionCandidates,
                'queued_at' => now()->toIso8601String(),
            ], 'queued');

            Log::info('AdminTestPipelineJob - transcription stage queued', [
                'company_id' => $this->companyId,
                'pipeline_run_id' => $this->pipelineRun?->id,
                'candidate_total' => $transcriptionCandidates,
                'event' => 'stage_queued',
            ]);
        }

        Log::info('AdminTestPipelineJob - Pipeline Step 1 complete: Ingest finished', ['company_id' => $this->companyId]);

        // Step barrier: continue only after transcription stage reaches terminal state.
        ContinuePipelineAfterTranscriptionsJob::dispatch(
            $this->companyId,
            $from,
            $to,
            $this->summarizeLimit,
            $this->categorizeLimit,
            $this->pipelineQueue,
            $this->pipelineRun?->id,
            $rangeDays,
        )->onQueue($this->pipelineQueue)->delay(now()->addSeconds(10));

        Log::info('AdminTestPipelineJob::handle() - COMPLETE', ['company_id' => $this->companyId]);

        Log::info('Admin test pipeline queued successfully', [
            'company_id' => $this->companyId,
            'ingest_accounts' => $accounts->count(),
            'queue' => $this->pipelineQueue,
            'note' => 'Jobs will execute asynchronously. Run: php artisan queue:work --queue=' . $this->pipelineQueue . ' --stop-when-empty',
        ]);
    }

    public function failed(Throwable $exception): void
    {
        if (! $this->pipelineRunId) {
            return;
        }

        $pipelineRun = PipelineRun::query()->find($this->pipelineRunId);
        if (! $pipelineRun) {
            return;
        }

        $pipelineRun->markFailed($this->activeStage, $exception->getMessage());
        $pipelineRun->upsertStage($this->activeStage, [
            'status' => 'failed',
            'error_message' => $exception->getMessage(),
            'finished_at' => now(),
        ]);
    }

    private function startStage(string $stageKey): void
    {
        if ($this->shouldSkipStage($stageKey)) {
            return;
        }

        $this->activeStage = $stageKey;
        if (! $this->pipelineRun) {
            return;
        }

        $this->pipelineRun->markRunning($stageKey);
        $this->pipelineRun->upsertStage($stageKey, [
            'status' => 'running',
            'error_message' => null,
            'started_at' => now(),
        ]);
        $this->pipelineRun->load('stages');
    }

    private function completeStage(string $stageKey, array $metrics = [], string $status = 'completed'): void
    {
        if ($this->shouldSkipStage($stageKey)) {
            return;
        }

        if (! $this->pipelineRun) {
            return;
        }

        $this->pipelineRun->upsertStage($stageKey, [
            'status' => $status,
            'metrics' => $metrics,
            'finished_at' => now(),
        ]);
        $this->pipelineRun->load('stages');
    }

    private function shouldSkipStage(string $stageKey): bool
    {
        if (! $this->isResume || ! $this->pipelineRun) {
            return false;
        }

        $status = $this->pipelineRun->stageStatus($stageKey);

        return in_array($status, ['completed', 'queued'], true);
    }
}
