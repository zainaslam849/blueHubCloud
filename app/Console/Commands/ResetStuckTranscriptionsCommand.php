<?php

namespace App\Console\Commands;

use App\Models\Call;
use Illuminate\Console\Command;

class ResetStuckTranscriptionsCommand extends Command
{
    protected $signature = 'transcription:reset-stuck
        {--company_id= : Optional company scope}
        {--dry-run : Show counts only; do not update rows}';

    protected $description = 'Reset terminalized transcription candidates back to pending for one clean retry pass';

    public function handle(): int
    {
        $companyId = $this->option('company_id');
        $dryRun = (bool) $this->option('dry-run');

        $query = Call::query()
            ->where('status', 'answered')
            ->whereNull('transcript_text')
            ->where(function ($q) {
                $q->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(pbx_metadata, '$.transcription_verification_status')) = 'terminal_no_transcription'")
                    ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(pbx_metadata, '$.transcription_verification_status')) = 'terminal_error'");
            })
            ->where(function ($q) {
                $q->whereRaw("JSON_EXTRACT(pbx_metadata, '$.recording_available_effective') IS NULL")
                    ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(pbx_metadata, '$.recording_available_effective')) = 'true'");
            });

        if (is_numeric($companyId)) {
            $query->where('company_id', (int) $companyId);
        }

        $total = (clone $query)->count();
        $this->info("Matched calls: {$total}");

        if ($dryRun) {
            $this->line('Dry run enabled. No updates were applied.');
            return self::SUCCESS;
        }

        $updated = 0;

        $query->orderBy('id')->chunkById(200, function ($calls) use (&$updated) {
            foreach ($calls as $call) {
                $meta = is_array($call->pbx_metadata) ? $call->pbx_metadata : [];
                $meta['transcription_verification_status'] = 'pending';
                $meta['transcription_last_decision'] = 'manual_reset_stuck';
                $meta['transcription_last_decision_at'] = now()->toIso8601String();
                $meta['transcription_retry_attempts'] = 0;
                unset($meta['transcription_first_attempt_at'], $meta['transcription_last_attempt_at']);

                $call->has_transcription = true;
                $call->transcription_checked_at = null;
                $call->pbx_metadata = $meta;
                $call->save();
                $updated++;
            }
        });

        $this->info("Updated calls: {$updated}");

        return self::SUCCESS;
    }
}
