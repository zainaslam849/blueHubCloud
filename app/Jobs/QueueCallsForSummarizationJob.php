<?php

namespace App\Jobs;

use App\Models\Call;
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
        public int $batch = 25
    ) {}

    public function handle(): void
    {
        $calls = Call::query()
            ->where('company_id', $this->companyId)
            ->whereNotNull('transcript_text')
            ->where('transcript_text', '!=', '')
            ->whereNull('ai_summary')
            ->orderByDesc('started_at')
            ->limit($this->limit)
            ->get(['id']);

        if ($calls->isEmpty()) {
            Log::info('QueueCallsForSummarizationJob: no calls to summarize', [
                'company_id' => $this->companyId,
            ]);
            return;
        }

        $chunks = $calls->chunk(max(1, $this->batch));

        foreach ($chunks as $chunkIndex => $chunk) {
            foreach ($chunk as $call) {
                SummarizeSingleCallJob::dispatch($call->id)
                    ->onQueue('summarization')
                    ->delay(now()->addSeconds($chunkIndex * 2));
            }
        }

        Log::info('QueueCallsForSummarizationJob: queued calls', [
            'company_id' => $this->companyId,
            'count' => $calls->count(),
        ]);
    }
}
