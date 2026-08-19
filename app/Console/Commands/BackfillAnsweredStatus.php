<?php

namespace App\Console\Commands;

use App\Models\Call;
use Illuminate\Console\Command;

/**
 * Backfills status='answered' for existing calls that were ingested with
 * status='unknown' but have billed duration (billsec > 0) — which only
 * accrues once a call is actually answered. IngestPbxCallsJob previously
 * only recognized the exact disposition string "ANSWERED"; any other PBX
 * disposition wording landed in 'unknown' and was silently excluded from
 * transcription/categorization (FetchTranscriptionsJob only queries
 * status='answered'), even though the call clearly connected.
 *
 * Flipping status is enough — FetchTranscriptionsJob's
 * promotePendingAnsweredCandidates() automatically picks up any
 * status='answered', has_transcription=false, transcript_text=null call on
 * its next run.
 */
class BackfillAnsweredStatus extends Command
{
    protected $signature = 'calls:backfill-answered-status
                            {--chunk=500 : Rows per batch}
                            {--dry-run : Report what would change without writing}';

    protected $description = 'Reclassify unknown-status calls with billed duration as answered';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $chunk = max(50, (int) $this->option('chunk'));

        $query = Call::query()
            ->where('status', 'unknown')
            ->where('duration_seconds', '>', 0);

        $total = $query->count();
        if ($total === 0) {
            $this->info('No unknown-status calls with billed duration found.');

            return self::SUCCESS;
        }

        $this->info("Found {$total} call(s) to reclassify" . ($dryRun ? ' (dry run)' : '') . '...');
        $bar = $this->output->createProgressBar($total);
        $updated = 0;

        $query->select(['id'])
            ->orderBy('id')
            ->chunkById($chunk, function ($calls) use (&$updated, $dryRun, $bar) {
                if (! $dryRun) {
                    Call::whereIn('id', $calls->pluck('id'))->update(['status' => 'answered']);
                }
                $updated += $calls->count();
                $bar->advance($calls->count());
            });

        $bar->finish();
        $this->newLine(2);

        $this->info("Rows reclassified to 'answered': {$updated}");

        if ($dryRun) {
            $this->warn('Dry run — nothing was written.');
        } else {
            $this->info('Run FetchTranscriptionsJob (or wait for the next scheduled pass) to pick these up for transcription.');
        }

        return self::SUCCESS;
    }
}
