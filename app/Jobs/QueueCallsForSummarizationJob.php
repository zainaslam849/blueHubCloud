<?php

namespace App\Jobs;

use App\Models\Call;
use App\Models\PipelineRun;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class QueueCallsForSummarizationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 30;

    public function __construct(
        public int $companyId,
        public int $limit = 500,
        public int $batch = 25,
        public string $targetQueue = 'summarization',
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
            ->where(function ($q) {
                $q->whereNull('ai_summary')
                    ->orWhere('ai_summary', '');
            })
            ->where('ai_summary_status', '!=', 'not_generated')
            ->orderByDesc('started_at');

        if ($this->fromDate !== null && $this->toDate !== null) {
            // Pad the window by 1 day on each side so calls whose `started_at`
            // (stored UTC) sits near a UTC midnight boundary aren't dropped
            // when the supplied fromDate/toDate represent local-calendar dates.
            $query->whereBetween('started_at', [
                CarbonImmutable::parse($this->fromDate, 'UTC')->startOfDay()->subDay(),
                CarbonImmutable::parse($this->toDate, 'UTC')->endOfDay()->addDay(),
            ]);
        }

        $calls = $query->limit($this->limit)->get(['id']);

        if ($calls->isEmpty()) {
            Log::info('QueueCallsForSummarizationJob: no calls to summarize', [
                'company_id' => $this->companyId,
                'pipeline_run_id' => $this->pipelineRunId,
            ]);

            if ($this->pipelineRunId) {
                $run = PipelineRun::query()->find($this->pipelineRunId);
                if ($run) {
                    $run->upsertStage('ai_summary', [
                        'status' => 'completed',
                        'metrics' => [
                            'queued_calls' => 0,
                        ],
                        'finished_at' => now(),
                    ]);
                }
            }

            return;
        }

        $chunks = $calls->chunk(max(1, $this->batch));

        foreach ($chunks as $chunkIndex => $chunk) {
            foreach ($chunk as $call) {
                SummarizeSingleCallJob::dispatch($call->id)
                    ->onQueue($this->targetQueue)
                    ->delay(now()->addSeconds($chunkIndex * 2));
            }
        }

        Log::info('QueueCallsForSummarizationJob: queued calls', [
            'company_id' => $this->companyId,
            'count' => $calls->count(),
            'pipeline_run_id' => $this->pipelineRunId,
        ]);

        if ($this->pipelineRunId) {
            $run = PipelineRun::query()->find($this->pipelineRunId);
            if ($run) {
                $run->upsertStage('ai_summary', [
                    'status' => 'queued',
                    'metrics' => [
                        'queued_calls' => $calls->count(),
                    ],
                    'finished_at' => now(),
                ]);
                $run->markQueued('ai_summary');
            }
        }
    }
}
