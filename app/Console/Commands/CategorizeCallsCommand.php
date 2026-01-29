<?php

namespace App\Console\Commands;

use App\Jobs\CategorizeSingleCallJob;
use App\Models\Call;
use Illuminate\Console\Command;

class CategorizeCallsCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'calls:categorize
                          {--uncategorized : Only categorize calls without category}
                          {--limit=100 : Maximum number of calls to categorize}
                          {--batch=10 : Process in batches (delay between batches)}
                          {--force : Force re-categorization of already categorized calls}';

    /**
     * The console command description.
     */
    protected $description = 'Queue call categorization jobs using AI';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $query = Call::whereNotNull('transcript_text');

        if ($this->option('uncategorized') || !$this->option('force')) {
            $query->whereNull('category_id');
        }

        $limit = (int) $this->option('limit');
        $batch = (int) $this->option('batch');

        $calls = $query->limit($limit)->get();

        if ($calls->isEmpty()) {
            $this->info('No calls to categorize');
            $this->newLine();
            $this->line('Tips:');
            $this->line('  • Make sure calls have transcripts (transcript_text not null)');
            $this->line('  • Use --force to re-categorize already categorized calls');
            return 0;
        }

        $this->info("Found {$calls->count()} call(s) to categorize");
        $this->newLine();

        if (!$this->confirm('Queue these calls for AI categorization?', true)) {
            $this->warn('Cancelled');
            return 1;
        }

        $this->newLine();
        $this->info('Queuing categorization jobs...');

        $bar = $this->output->createProgressBar($calls->count());
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %message%');

        $queued = 0;

        foreach ($calls->chunk($batch) as $chunkIndex => $chunk) {
            foreach ($chunk as $call) {
                // Dispatch job with slight delay to spread load
                CategorizeSingleCallJob::dispatch($call->id)
                    ->onQueue('categorization')
                    ->delay(now()->addSeconds($chunkIndex * 2));
                
                $queued++;
                $bar->setMessage("Queued call ID: {$call->id}");
                $bar->advance();
            }
            
            // Small delay between batches to avoid rate limiting
            if ($chunkIndex < ceil($calls->count() / $batch) - 1) {
                usleep(500000); // 0.5 second
            }
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("✓ Successfully queued {$queued} call(s) for categorization");
        $this->newLine();
        
        $this->line('Next steps:');
        $this->line('  1. Start queue worker: <fg=cyan>php artisan queue:work --queue=categorization</>');
        $this->line('  2. Monitor progress: <fg=cyan>php artisan queue:monitor categorization</>');
        $this->line('  3. Check results: <fg=cyan>php check_categorization.php</>');
        $this->newLine();

        $this->comment('Note: Categorization happens asynchronously. Jobs will process when queue worker is running.');

        return 0;
    }
}
