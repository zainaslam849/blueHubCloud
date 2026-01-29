# Call Categorization Setup & Usage Guide

## Quick Start

### 1. Add OpenAI API Key to .env

```env
OPENAI_API_KEY=sk-your-api-key-here
OPENAI_MODEL=gpt-4o-mini
```

### 2. Create Categories (if not already done)

```bash
php artisan tinker
```

```php
\App\Models\CallCategory::create(['name' => 'General', 'description' => 'Default category', 'is_enabled' => true]);
\App\Models\CallCategory::create(['name' => 'Support', 'description' => 'Customer support', 'is_enabled' => true]);
\App\Models\CallCategory::create(['name' => 'Sales', 'description' => 'Sales inquiries', 'is_enabled' => true]);
\App\Models\CallCategory::create(['name' => 'Billing', 'description' => 'Billing issues', 'is_enabled' => true]);
\App\Models\CallCategory::create(['name' => 'Other', 'description' => 'Low confidence', 'is_enabled' => true]);
exit
```

### 3. Queue Categorization Jobs

```bash
# Categorize all uncategorized calls (default)
php artisan calls:categorize

# Categorize with options
php artisan calls:categorize --uncategorized --limit=50

# Re-categorize already categorized calls
php artisan calls:categorize --force --limit=10
```

### 4. Start Queue Worker

```bash
# Process categorization jobs
php artisan queue:work --queue=categorization --tries=3

# Or run in background (Linux/Mac)
nohup php artisan queue:work --queue=categorization --tries=3 &

# Or use Windows
start /B php artisan queue:work --queue=categorization --tries=3
```

### 5. Check Results

```bash
php check_categorization.php
```

---

## Daily Workflow

### Automated Setup (Production)

**1. Schedule in app/Console/Kernel.php:**

```php
protected function schedule(Schedule $schedule): void
{
    // Ingest calls every hour
    $schedule->command('pbx:ingest-test')
        ->hourly()
        ->withoutOverlapping();

    // Categorize new calls every 10 minutes
    $schedule->command('calls:categorize --uncategorized --limit=100')
        ->everyTenMinutes()
        ->withoutOverlapping();

    // Generate weekly reports Sunday at 11 PM
    $schedule->command('pbx:generate-weekly-reports')
        ->weekly()
        ->sundays()
        ->at('23:00');
}
```

**2. Run Laravel scheduler:**

```bash
# Add to crontab
* * * * * cd /path/to/project && php artisan schedule:run >> /dev/null 2>&1
```

**3. Queue worker via Supervisor (Linux):**

Create `/etc/supervisor/conf.d/laravel-categorization.conf`:

```ini
[program:laravel-categorization-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/artisan queue:work --queue=categorization --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
numprocs=3
user=www-data
redirect_stderr=true
stdout_logfile=/path/to/storage/logs/worker.log
```

Then:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start laravel-categorization-worker:*
```

---

## Manual Testing

### Test Single Call Categorization

```bash
php artisan tinker
```

```php
// Get a call with transcript
$call = \App\Models\Call::whereNotNull('transcript_text')->first();

if ($call) {
    echo "Testing call ID: {$call->id}\n";
    echo "Transcript: " . substr($call->transcript_text, 0, 200) . "...\n\n";

    // Dispatch job
    \App\Jobs\CategorizeSingleCallJob::dispatch($call->id)
        ->onQueue('categorization');

    echo "Job queued! Now run:\n";
    echo "php artisan queue:work --queue=categorization --once\n";
} else {
    echo "No calls with transcripts found\n";
}
exit
```

Then in another terminal:

```bash
php artisan queue:work --queue=categorization --once
```

### Check Job Status

```bash
# View failed jobs
php artisan queue:failed

# Retry failed jobs
php artisan queue:retry all

# Monitor queue in real-time
php artisan queue:monitor categorization
```

---

## Command Options

### `php artisan calls:categorize`

| Option            | Description                             | Default |
| ----------------- | --------------------------------------- | ------- |
| `--uncategorized` | Only process calls without category_id  | true    |
| `--limit=N`       | Maximum number of calls to queue        | 100     |
| `--batch=N`       | Batch size (delay between batches)      | 10      |
| `--force`         | Re-categorize already categorized calls | false   |

**Examples:**

```bash
# Categorize 500 uncategorized calls
php artisan calls:categorize --limit=500

# Force re-categorize 20 calls (testing)
php artisan calls:categorize --force --limit=20

# Large batch with small batches
php artisan calls:categorize --limit=1000 --batch=50
```

---

## Troubleshooting

### No calls queued?

```bash
# Check if calls have transcripts
php artisan tinker
```

```php
\App\Models\Call::whereNotNull('transcript_text')->count();
\App\Models\Call::whereNull('category_id')->whereNotNull('transcript_text')->count();
```

### Jobs failing?

```bash
# Check logs
tail -f storage/logs/laravel.log

# View failed jobs
php artisan queue:failed

# Check specific job
php artisan queue:failed-table
php artisan migrate
```

### OpenAI API errors?

```bash
# Verify API key
php artisan tinker
```

```php
config('services.openai.api_key');
```

Common errors:

- `401 Unauthorized` → Invalid API key
- `429 Rate limit` → Too many requests (add delays)
- `Timeout` → Increase timeout in config

### Rate limiting?

Add delays between jobs:

```php
// In CategorizeCallsCommand.php, increase delay:
->delay(now()->addSeconds($chunkIndex * 5)); // 5 seconds instead of 2
```

---

## Monitoring

### Real-time Dashboard

```bash
# Install Laravel Horizon (optional)
composer require laravel/horizon
php artisan horizon:install
php artisan horizon
```

Then visit: `http://localhost/horizon`

### Custom Monitoring Script

```bash
php artisan tinker
```

```php
// Check queue depth
$pending = \Illuminate\Support\Facades\DB::table('jobs')
    ->where('queue', 'categorization')
    ->count();

$failed = \Illuminate\Support\Facades\DB::table('failed_jobs')->count();

echo "Pending jobs: {$pending}\n";
echo "Failed jobs: {$failed}\n";

// Categorization stats
$total = \App\Models\Call::whereNotNull('transcript_text')->count();
$categorized = \App\Models\Call::whereNotNull('category_id')->count();
$progress = $total > 0 ? round(($categorized / $total) * 100, 1) : 0;

echo "\nCategorization Progress: {$progress}% ({$categorized}/{$total})\n";
```

---

## Performance Tips

1. **Use gpt-4o-mini** - Faster and cheaper than gpt-4
2. **Process in batches** - Use `--batch` option to avoid rate limits
3. **Multiple workers** - Run 3-5 queue workers in parallel
4. **Cache categories** - Categories are loaded fresh each time
5. **Monitor costs** - Each call costs ~$0.001-0.01 depending on model

---

## Cost Estimation

**Using gpt-4o-mini:**

- ~500 tokens per categorization
- $0.15 per 1M input tokens
- $0.60 per 1M output tokens
- **~$0.001 per call**

**Example:**

- 1,000 calls/day = ~$1/day = ~$30/month
- 10,000 calls/day = ~$10/day = ~$300/month

---

## Next Steps

1. ✅ API key configured
2. ✅ Categories created
3. ✅ Jobs queued
4. ✅ Worker running
5. 📊 Monitor results
6. 🔄 Schedule automation
7. 📈 Generate weekly reports

**Need help?** Check logs in `storage/logs/laravel.log`
