<?php

namespace App\Jobs;

use App\Jobs\GenerateWeeklyPbxReportsJob;
use App\Models\Call;
use App\Models\PipelineRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class QueueCallsForCategorizationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 30;

    public function __construct(
        public int $companyId,
        public int $limit = 500,
        public int $batch = 25,
        public bool $force = false,
        public string $targetQueue = 'categorization',
        public ?string $fromDate = null,
        public ?string $toDate = null,
        public ?int $pipelineRunId = null,
    ) {}

    public function handle(): void
    {
        $query = Call::query()
            ->where('company_id', $this->companyId)
            ->whereNotNull('transcript_text')
            ->where('transcript_text', '!=', '')
            ->orderByDesc('started_at');

        if (! $this->force) {
            $query->whereNull('category_id');
        }

        $calls = $query->limit($this->limit)->get(['id']);

        if ($calls->isEmpty()) {
            Log::info('QueueCallsForCategorizationJob: no calls to categorize', [
                'company_id' => $this->companyId,
            ]);
            return;
        }

        $chunks = $calls->chunk(max(1, $this->batch));

        foreach ($chunks as $chunkIndex => $chunk) {
            foreach ($chunk as $call) {
                CategorizeSingleCallJob::dispatch($call->id)
                    ->onQueue($this->targetQueue)
                    ->delay(now()->addSeconds($chunkIndex * 2));
            }
        }

        Log::info('QueueCallsForCategorizationJob: queued calls', [
            'company_id' => $this->companyId,
            'count' => $calls->count(),
            'force' => $this->force,
            'pipeline_run_id' => $this->pipelineRunId,
        ]);

        if ($this->pipelineRunId) {
            $run = PipelineRun::query()->find($this->pipelineRunId);
            if ($run) {
                $run->upsertStage('call_categorization', [
                    'status' => 'queued',
                    'metrics' => [
                        'queued_calls' => $calls->count(),
                    ],
                    'finished_at' => now(),
                ]);
                $run->markQueued('call_categorization');
            }
        }

        // Dispatch report generation AFTER categorization completes
        // Calculate delay: number of chunks * 2 seconds per chunk + buffer for processing
        $delaySeconds = ($chunks->count() * 2) + 30; // 30s buffer for categorization processing
        
        if ($this->fromDate && $this->toDate) {
            Log::info('QueueCallsForCategorizationJob: scheduling report generation', [
                'company_id' => $this->companyId,
                'delay_seconds' => $delaySeconds,
                'from_date' => $this->fromDate,
                'to_date' => $this->toDate,
            ]);
            
            GenerateWeeklyPbxReportsJob::dispatch($this->fromDate, $this->toDate, $this->pipelineRunId)
                ->onQueue($this->targetQueue)
                ->delay(now()->addSeconds($delaySeconds));

            if ($this->pipelineRunId) {
                $run = PipelineRun::query()->find($this->pipelineRunId);
                if ($run) {
                    $run->upsertStage('report_generation', [
                        'status' => 'queued',
                        'metrics' => [
                            'delay_seconds' => $delaySeconds,
                        ],
                        'finished_at' => now(),
                    ]);
                    $run->markQueued('report_generation');
                }
            }
        }
    }
}
