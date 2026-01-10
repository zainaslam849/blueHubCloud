<?php

namespace App\Jobs;

use App\Models\CallRecording;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class IngestPbxRecordingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 30;

    protected int $companyId;
    protected int $callId;
    protected string $testAudioPath;

    /**
     * Local-only test job.
     * @param int $companyId
     * @param int $callId
     * @param string $testAudioPath relative to storage/app, e.g. "test-audio/sample.mp3"
     */
    public function __construct(int $companyId, int $callId, string $testAudioPath = 'test-audio/sample.mp3')
    {
        $this->companyId = $companyId;
        $this->callId = $callId;
        $this->testAudioPath = $testAudioPath;
        $this->onQueue('default');
    }

    public function handle(): void
    {
        Log::info('IngestPbxRecordingJob (local test) started', [
            'company_id' => $this->companyId,
            'call_id' => $this->callId,
            'test_audio_path' => $this->testAudioPath,
        ]);

        $localDisk = Storage::disk('local');
        $s3Disk = Storage::disk('s3');

        if (! $localDisk->exists($this->testAudioPath)) {
            $msg = "Test audio not found: {$this->testAudioPath}";
            Log::error($msg, ['company_id' => $this->companyId, 'call_id' => $this->callId]);
            return;
        }

        $s3Key = sprintf('recordings/incoming/%s/%s.mp3', $this->companyId, $this->callId);

        $readResource = null;
        try {
            $readResource = $localDisk->readStream($this->testAudioPath);
            if ($readResource === false || ! is_resource($readResource)) {
                throw new \RuntimeException('Failed to open local test audio stream');
            }

            $uploadStart = microtime(true);
            $ok = $s3Disk->writeStream($s3Key, $readResource);
            $uploadMs = round((microtime(true) - $uploadStart) * 1000, 2);

            if (is_resource($readResource)) {
                @fclose($readResource);
            }

            if (! $ok) {
                throw new \RuntimeException('S3 writeStream returned false');
            }

            Log::info('S3 upload succeeded (local test)', [
                'company_id' => $this->companyId,
                'call_id' => $this->callId,
                's3_key' => $s3Key,
                'upload_latency_ms' => $uploadMs,
            ]);

            // Insert DB row (fail gracefully if DB inaccessible)
            try {
                $record = CallRecording::create([
                    'company_id' => $this->companyId,
                    'call_id' => $this->callId,
                    'storage_provider' => 's3',
                    'storage_path' => $s3Key,
                    'status' => CallRecording::STATUS_UPLOADED,
                ]);

                Log::info('CallRecording DB insert succeeded (local test)', [
                    'company_id' => $this->companyId,
                    'call_id' => $this->callId,
                    'call_recording_id' => $record->id ?? null,
                ]);
            } catch (\Throwable $e) {
                Log::warning('CallRecording DB insert failed (local test) — continuing without DB', [
                    'company_id' => $this->companyId,
                    'call_id' => $this->callId,
                    'error' => $e->getMessage(),
                ]);
            }

        } catch (\Throwable $e) {
            Log::error('IngestPbxRecordingJob (local test) failed', [
                'company_id' => $this->companyId,
                'call_id' => $this->callId,
                'test_audio_path' => $this->testAudioPath,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            // Let the job fail/retry according to queue config
            throw $e;
        }
    }
}
