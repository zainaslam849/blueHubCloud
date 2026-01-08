<?php

namespace App\Jobs;

use App\Contracts\TranscriptionService;
use App\Models\CallRecording;
use App\Models\CallTranscription;
use App\Models\TranscriptionUsage;
use DomainException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class TranscribeCallRecording implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * Backoff in seconds for retries.
     *
     * @var int[]
     */
    public array $backoff = [5, 15, 60];

    public function __construct(
        public int $callRecordingId,
        public string $language = 'en',
    ) {
    }

    public function handle(TranscriptionService $transcriptionService): void
    {
        try {
            $recording = DB::transaction(function () {
                $locked = CallRecording::query()
                    ->lockForUpdate()
                    ->find($this->callRecordingId);

                if (! $locked) {
                    return null;
                }

                // Idempotency: if already transcribed, nothing to do.
                if ($locked->status === CallRecording::STATUS_TRANSCRIBED) {
                    return null;
                }

                // Only transcribe when processing is completed.
                if ($locked->status !== CallRecording::STATUS_COMPLETED) {
                    return null;
                }

                $locked->markTranscribing();

                return $locked;
            });

            if (! $recording) {
                return;
            }

            $result = $transcriptionService->transcribe($recording);

            $didTranscribe = false;

            DB::transaction(function () use ($recording, $result) {
                $locked = CallRecording::query()
                    ->lockForUpdate()
                    ->find($recording->id);

                if (! $locked) {
                    return;
                }

                if ($locked->status === CallRecording::STATUS_TRANSCRIBED) {
                    return;
                }

                if ($locked->status !== CallRecording::STATUS_TRANSCRIBING) {
                    // Another worker/job changed status; don't fight it.
                    return;
                }

                CallTranscription::query()->updateOrCreate(
                    [
                        'call_id' => $locked->call_id,
                        'provider_name' => $result->provider_name,
                        'language' => $this->language,
                    ],
                    [
                        'transcript_text' => $result->transcript_text,
                        'duration_seconds' => $result->duration_seconds,
                    ],
                );

                $currency = (string) config('services.transcription.currency', 'USD');
                $pricing = (array) config('services.transcription.pricing_per_minute', []);
                $ratePerMinute = (float) ($pricing[$result->provider_name] ?? ($pricing['default'] ?? 0));
                $cost = (($result->duration_seconds / 60) * $ratePerMinute);

                TranscriptionUsage::query()->updateOrCreate(
                    [
                        'call_recording_id' => $locked->id,
                        'provider_name' => $result->provider_name,
                        'language' => $this->language,
                    ],
                    [
                        'company_id' => $locked->company_id,
                        'duration_seconds' => $result->duration_seconds,
                        'cost_estimate' => round($cost, 4),
                        'currency' => $currency,
                    ],
                );

                $locked->markTranscribed();

                $didTranscribe = true;
            });

            if ($didTranscribe && (bool) config('services.recordings.delete_audio_after_transcription', false)) {
                $disk = $recording->storage_provider ?: 's3';
                $path = $recording->storage_path;

                if (is_string($path) && trim($path) !== '') {
                    try {
                        Storage::disk($disk)->delete($path);

                        Log::info('Deleted call recording audio after transcription.', [
                            'call_recording_id' => $recording->id,
                            'disk' => $disk,
                            'path' => $path,
                        ]);
                    } catch (Throwable $e) {
                        // Never fail transcription on deletion errors.
                        Log::warning('Failed to delete call recording audio after transcription.', [
                            'call_recording_id' => $recording->id,
                            'disk' => $disk,
                            'path' => $path,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }
        } catch (Throwable $e) {
            $this->markFailedOrRetry($e);
        }
    }

    private function markFailedOrRetry(Throwable $e): void
    {
        DB::transaction(function () use ($e) {
            $locked = CallRecording::query()
                ->lockForUpdate()
                ->find($this->callRecordingId);

            if (! $locked) {
                return;
            }

            $message = mb_substr($e->getMessage(), 0, 2000);

            // On intermediate retries, keep status as transcribing and record error.
            if ($this->attempts() < $this->tries) {
                $locked->error_message = $message;
                $locked->save();
                return;
            }

            // Final attempt: mark failed, but only if transition is valid.
            if ($locked->status === CallRecording::STATUS_TRANSCRIBING) {
                $locked->markFailed($message);
                return;
            }

            $locked->error_message = $message;
            $locked->save();
        });

        if ($this->attempts() >= $this->tries) {
            $this->fail($e);
            return;
        }

        throw $e;
    }
}
