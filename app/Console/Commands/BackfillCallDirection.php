<?php

namespace App\Console\Commands;

use App\Models\Call;
use App\Support\CdrParty;
use Illuminate\Console\Command;

/**
 * Backfills call direction and extension columns for rows ingested before
 * those were derived.
 *
 * Historically IngestPbxCallsJob wrote direction='unknown' (the CDR payload
 * has no direction column) and tested for extensions with a bare-digits
 * regex, which never matches PBXware's "Name (extension)" format. The result
 * was null caller/answered extensions on every row and outbound calls being
 * dropped from the extension leaderboard entirely.
 *
 * Everything needed is already stored on the call (from/to), so this rebuilds
 * the derived fields in place — no PBX refetch required.
 */
class BackfillCallDirection extends Command
{
    protected $signature = 'calls:backfill-direction
                            {--chunk=500 : Rows per batch}
                            {--dry-run : Report what would change without writing}';

    protected $description = 'Derive direction and extension columns for existing calls from their stored From/To values';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $chunk = max(50, (int) $this->option('chunk'));

        $total = Call::query()->withTrashed()->count();
        if ($total === 0) {
            $this->info('No calls to backfill.');

            return self::SUCCESS;
        }

        $this->info("Scanning {$total} call(s)" . ($dryRun ? ' (dry run)' : '') . '...');
        $bar = $this->output->createProgressBar($total);

        $counts = ['inbound' => 0, 'outbound' => 0, 'internal' => 0];
        $updated = 0;
        $extensionsFilled = 0;

        Call::query()
            ->withTrashed()
            ->select(['id', 'from', 'to', 'direction', 'caller_extension', 'answered_by_extension'])
            ->orderBy('id')
            ->chunkById($chunk, function ($calls) use (&$counts, &$updated, &$extensionsFilled, $dryRun, $bar) {
                foreach ($calls as $call) {
                    $fromParty = CdrParty::parse($call->from);
                    $toParty = CdrParty::parse($call->to);
                    $direction = CdrParty::direction($call->from, $call->to);

                    $attributes = [];

                    if ($call->direction !== $direction) {
                        $attributes['direction'] = $direction;
                    }
                    if ($call->caller_extension === null && $fromParty['extension'] !== null) {
                        $attributes['caller_extension'] = $fromParty['extension'];
                    }
                    if ($call->answered_by_extension === null && $toParty['extension'] !== null) {
                        $attributes['answered_by_extension'] = $toParty['extension'];
                    }

                    if (isset($counts[$direction])) {
                        $counts[$direction]++;
                    }

                    if ($attributes !== []) {
                        $updated++;
                        if (isset($attributes['caller_extension']) || isset($attributes['answered_by_extension'])) {
                            $extensionsFilled++;
                        }

                        if (! $dryRun) {
                            // Query-builder update: no model events, no timestamp
                            // churn, and soft-deleted rows are included.
                            Call::withTrashed()->whereKey($call->id)->update($attributes);
                        }
                    }

                    $bar->advance();
                }
            });

        $bar->finish();
        $this->newLine(2);

        $this->table(
            ['Direction', 'Calls'],
            [
                ['inbound', $counts['inbound']],
                ['outbound', $counts['outbound']],
                ['internal', $counts['internal']],
            ]
        );

        $this->info("Rows needing update: {$updated} (extensions filled on {$extensionsFilled})");

        if ($dryRun) {
            $this->warn('Dry run — nothing was written.');
        } else {
            $this->info('Backfill complete. Regenerate weekly reports to see outbound in the leaderboard.');
        }

        return self::SUCCESS;
    }
}
