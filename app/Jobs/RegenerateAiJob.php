<?php

namespace App\Jobs;

use App\Models\Call;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RegenerateAiJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 180;
    public array $backoff = [30, 120, 300];

    /**
     * @param  array<int, string>  $steps
     */
    public function __construct(
        public int $companyId,
        public string $fromDate,
        public string $toDate,
        public array $steps,
    ) {
    }

    public function handle(): void
    {
        $from = CarbonImmutable::parse($this->fromDate, 'UTC')->startOfDay();
        $to = CarbonImmutable::parse($this->toDate, 'UTC')->endOfDay();
        $steps = array_values(array_unique(array_map('strval', $this->steps)));

        Log::info('RegenerateAiJob: started', [
            'company_id' => $this->companyId,
            'from_date' => $from->toDateString(),
            'to_date' => $to->toDateString(),
            'steps' => $steps,
        ]);

        if (in_array('transcript', $steps, true)) {
            $this->dispatchTranscriptRegeneration($from, $to);
        }

        if (in_array('summary', $steps, true)) {
            $this->dispatchSummaryRegeneration($from, $to);

            $rangeDays = max(1, $from->diffInDays($to) + 1);

            ContinuePipelineAfterSummariesJob::dispatch(
                $this->companyId,
                $from->toDateString(),
                $to->toDateString(),
                100000,
                'default',
                null,
                $rangeDays,
            )->onQueue('default')->delay(now()->addSeconds(10));

            return;
        }

        if (in_array('categories', $steps, true)) {
            $pendingCategories = $this->resetPendingCategoryStatuses($from, $to);
            $rangeDays = max(1, $from->diffInDays($to) + 1);

            Bus::chain([
                new GenerateAiCategoriesForCompanyJob(
                    $this->companyId,
                    $rangeDays,
                    null,
                ),
                new QueueCallsForCategorizationJob(
                    $this->companyId,
                    max(500, $pendingCategories + 25),
                    25,
                    false,
                    'default',
                    $from->toDateString(),
                    $to->toDateString(),
                    null,
                ),
            ])->onQueue('default')->dispatch();
        }
    }

    private function dispatchTranscriptRegeneration(CarbonImmutable $from, CarbonImmutable $to): void
    {
        $accountIds = DB::table('company_pbx_accounts')
            ->where('company_id', $this->companyId)
            ->where('status', 'active')
            ->pluck('id')
            ->map(static fn ($id) => (int) $id)
            ->all();

        if (empty($accountIds)) {
            Log::warning('RegenerateAiJob: transcript step skipped, no active PBX accounts', [
                'company_id' => $this->companyId,
            ]);
            return;
        }

        foreach ($accountIds as $accountId) {
            IngestPbxCallsJob::dispatch(
                $this->companyId,
                $accountId,
                [
                    'from' => $from->toDateString(),
                    'to' => $to->toDateString(),
                ]
            );
        }

        Log::info('RegenerateAiJob: queued transcript regeneration via ingest jobs', [
            'company_id' => $this->companyId,
            'company_pbx_account_ids' => $accountIds,
        ]);
    }

    private function dispatchSummaryRegeneration(CarbonImmutable $from, CarbonImmutable $to): void
    {
        $query = Call::query()
            ->where('company_id', $this->companyId)
            ->whereBetween('started_at', [$from, $to])
            ->whereNotNull('transcript_text')
            ->where('transcript_text', '!=', '')
            ->where(function ($q) {
                $q->where('ai_summary_status', 'credit_exhausted')
                    ->orWhere(function ($nested) {
                        $nested->whereNull('ai_summary_status')
                            ->where(function ($pendingSummary) {
                                $pendingSummary->whereNull('ai_summary')
                                    ->orWhere('ai_summary', '');
                            });
                    });
            });

        $pendingCount = (clone $query)->count();

        $query->orderBy('id')->chunkById(200, function ($calls): void {
            $ids = $calls->pluck('id')->map(static fn ($id) => (int) $id)->all();

            Call::query()->whereIn('id', $ids)->update(['ai_summary_status' => null]);

            foreach ($ids as $callId) {
                SummarizeSingleCallJob::dispatch($callId)
                    ->onQueue('summarization');
            }
        }, 'id');

        Log::info('RegenerateAiJob: queued summary regeneration', [
            'company_id' => $this->companyId,
            'pending_count' => $pendingCount,
        ]);
    }

    private function resetPendingCategoryStatuses(CarbonImmutable $from, CarbonImmutable $to): int
    {
        $query = Call::query()
            ->where('company_id', $this->companyId)
            ->whereBetween('started_at', [$from, $to])
            ->whereNotNull('transcript_text')
            ->where('transcript_text', '!=', '')
            ->whereNull('category_id')
            ->where(function ($q) {
                $q->where('ai_category_status', 'credit_exhausted')
                    ->orWhere('ai_category_status', 'not_generated')
                    ->orWhereNull('ai_category_status');
            });

        $pendingCount = (clone $query)->count();

        $query->orderBy('id')->chunkById(500, function ($calls): void {
            $ids = $calls->pluck('id')->map(static fn ($id) => (int) $id)->all();
            Call::query()->whereIn('id', $ids)->update(['ai_category_status' => null]);
        }, 'id');

        Log::info('RegenerateAiJob: reset category statuses', [
            'company_id' => $this->companyId,
            'pending_count' => $pendingCount,
        ]);

        return $pendingCount;
    }
}
