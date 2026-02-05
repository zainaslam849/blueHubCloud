<?php

namespace App\Jobs;

use App\Models\Call;
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
        public string $targetQueue = 'categorization'
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
        ]);
    }
}
