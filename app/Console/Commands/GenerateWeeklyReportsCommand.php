<?php

namespace App\Console\Commands;

use App\Jobs\GenerateWeeklyPbxReportsJob;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class GenerateWeeklyReportsCommand extends Command
{
    protected $signature = 'pbx:generate-weekly-reports {--from= : YYYY-MM-DD (optional)} {--to= : YYYY-MM-DD (optional)}';

    protected $description = 'Dispatch weekly PBX call report aggregation (default: last completed week).';

    public function handle(): int
    {
        $fromOpt = $this->option('from');
        $toOpt = $this->option('to');

        [$fromDate, $toDate] = $this->resolveDateRange($fromOpt, $toOpt);
        if (! $fromDate || ! $toDate) {
            return self::FAILURE;
        }

        GenerateWeeklyPbxReportsJob::dispatchSync(
            $fromDate->toDateString(),
            $toDate->toDateString(),
        );

        $this->info('Dispatched GenerateWeeklyPbxReportsJob');
        $this->line('from: ' . $fromDate->toDateString());
        $this->line('to:   ' . $toDate->toDateString());

        return self::SUCCESS;
    }

    /**
     * @return array{0:CarbonImmutable|null,1:CarbonImmutable|null}
     */
    private function resolveDateRange(mixed $fromOpt, mixed $toOpt): array
    {
        $fromRaw = is_string($fromOpt) ? trim($fromOpt) : '';
        $toRaw = is_string($toOpt) ? trim($toOpt) : '';

        if ($fromRaw === '' && $toRaw === '') {
            // Default: last completed week (Monday-Sunday) in UTC.
            $thisMonday = CarbonImmutable::now('UTC')->startOfWeek(CarbonImmutable::MONDAY);
            $from = $thisMonday->subWeek();
            $to = $from->addDays(6);

            return [$from, $to];
        }

        if ($fromRaw === '' && $toRaw !== '') {
            $fromRaw = $toRaw;
        }

        if ($toRaw === '' && $fromRaw !== '') {
            $toRaw = $fromRaw;
        }

        $from = $this->parseIsoDateOption($fromRaw, '--from');
        $to = $this->parseIsoDateOption($toRaw, '--to');

        if (! $from || ! $to) {
            return [null, null];
        }

        if ($from->gt($to)) {
            $this->error('Invalid range: --from must be <= --to');
            return [null, null];
        }

        return [$from, $to];
    }

    private function parseIsoDateOption(string $value, string $flag): ?CarbonImmutable
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $this->error("{$flag} must be in YYYY-MM-DD format");
            return null;
        }

        $dt = CarbonImmutable::createFromFormat('Y-m-d', $value, 'UTC');
        if (! $dt) {
            $this->error("{$flag} is not a valid date");
            return null;
        }

        // Strict check: ensures the parsed date matches exactly.
        if ($dt->format('Y-m-d') !== $value) {
            $this->error("{$flag} is not a valid calendar date");
            return null;
        }

        return $dt;
    }
}
