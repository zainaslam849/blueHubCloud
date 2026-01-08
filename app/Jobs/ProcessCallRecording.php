<?php

namespace App\Jobs;

use App\Models\CallRecording;
use DomainException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProcessCallRecording implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 5;

    public function __construct(
        public int $callRecordingId,
    ) {
    }

    public function handle(): void
    {
        try {
            $context = DB::transaction(function () {
                $recording = CallRecording::query()
                    ->lockForUpdate()
                    ->find($this->callRecordingId);

                if (! $recording) {
                    return null;
                }

                if ($recording->status === CallRecording::STATUS_COMPLETED) {
                    return null;
                }

                if (! in_array($recording->status, [
                    CallRecording::STATUS_QUEUED,
                    CallRecording::STATUS_PROCESSING,
                ], true)) {
                    return null;
                }

                if ($recording->status === CallRecording::STATUS_QUEUED) {
                    $recording->markProcessing();
                }

                return [
                    'id' => $recording->id,
                    'storage_provider' => $recording->storage_provider,
                    'storage_path' => $recording->storage_path,
                ];
            });

            if (! $context) {
                return;
            }

            $storagePath = $context['storage_path'] ?? null;
            if (! is_string($storagePath) || trim($storagePath) === '') {
                throw new DomainException('Missing storage_path for recording.');
            }

            $disk = $context['storage_provider'] ?: 's3';
            if (! Storage::disk($disk)->exists($storagePath)) {
                throw new DomainException('Recording file not found at storage_path.');
            }

            // Placeholder: simulate processing work.
            // Keep this fast and side-effect free for now.

            DB::transaction(function () {
                $recording = CallRecording::query()
                    ->lockForUpdate()
                    ->find($this->callRecordingId);

                if (! $recording || $recording->status === CallRecording::STATUS_COMPLETED) {
                    return;
                }

                if ($recording->status === CallRecording::STATUS_PROCESSING) {
                    $recording->markCompleted();
                }
            });
        } catch (Throwable $e) {
            $this->markFailedOrRetry($e);
        }
    }

    private function markFailedOrRetry(Throwable $e): void
    {
        DB::transaction(function () use ($e) {
            $recording = CallRecording::query()
                ->lockForUpdate()
                ->find($this->callRecordingId);

            if (! $recording) {
                return;
            }

            $message = mb_substr($e->getMessage(), 0, 2000);

            if ($this->attempts() >= $this->tries) {
                if ($recording->status === CallRecording::STATUS_PROCESSING) {
                    $recording->markFailed($message);
                } else {
                    $recording->error_message = $message;
                    $recording->save();
                }
            } else {
                // Keep status as-is for retries; only record the error.
                $recording->error_message = $message;
                $recording->save();
            }
        });

        if ($this->attempts() >= $this->tries) {
            $this->fail($e);
            return;
        }

        throw $e;
    }
}
