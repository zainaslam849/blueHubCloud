<?php

namespace App\Services;

use App\Contracts\TranscriptionResult;
use App\Contracts\TranscriptionService;
use App\Models\CallRecording;
use DomainException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class OpenAIWhisperTranscriptionService implements TranscriptionService
{
    public function transcribe(CallRecording $callRecording): TranscriptionResult
    {
        $storagePath = $callRecording->storage_path;
        if (! is_string($storagePath) || trim($storagePath) === '') {
            throw new DomainException('CallRecording.storage_path is required for transcription.');
        }

        $apiKey = (string) config('services.openai.api_key');
        if (trim($apiKey) === '') {
            throw new RuntimeException('OpenAI API key is not configured. Set OPENAI_API_KEY via env/secrets.');
        }

        $diskName = $callRecording->storage_provider ?: 's3';
        $disk = Storage::disk($diskName);

        $maxBytes = (int) config('services.openai.whisper.max_bytes', 25 * 1024 * 1024);
        try {
            $size = $disk->size($storagePath);
            if (is_numeric($size) && (int) $size > 0 && (int) $size > $maxBytes) {
                throw new DomainException('Audio file exceeds maximum allowed size for transcription.');
            }
        } catch (Throwable $e) {
            // If size check fails (permissions/driver), continue; the API call will be the ultimate validator.
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'whisper_');
        if ($tmpPath === false) {
            throw new RuntimeException('Unable to allocate temp file for transcription.');
        }

        try {
            $readStream = $disk->readStream($storagePath);
            if (! is_resource($readStream)) {
                throw new RuntimeException('Unable to read audio stream from storage.');
            }

            $writeHandle = fopen($tmpPath, 'wb');
            if ($writeHandle === false) {
                throw new RuntimeException('Unable to open temp file for writing.');
            }

            try {
                stream_copy_to_stream($readStream, $writeHandle);
            } finally {
                fclose($writeHandle);
                fclose($readStream);
            }

            $timeoutSeconds = (int) config('services.openai.whisper.timeout', 120);
            $connectTimeoutSeconds = (int) config('services.openai.whisper.connect_timeout', 10);
            $model = (string) config('services.openai.whisper.model', 'whisper-1');

            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->asMultipart()
                ->connectTimeout($connectTimeoutSeconds)
                ->timeout($timeoutSeconds)
                ->retry(3, 500)
                ->attach('file', fopen($tmpPath, 'rb'), basename($storagePath))
                ->post('https://api.openai.com/v1/audio/transcriptions', [
                    'model' => $model,
                    'response_format' => 'json',
                ]);

            if (! $response->successful()) {
                $body = (string) $response->body();
                $snippet = mb_substr($body, 0, 2000);

                throw new RuntimeException("OpenAI Whisper transcription failed ({$response->status()}): {$snippet}");
            }

            $json = $response->json();
            $text = is_array($json) ? ($json['text'] ?? null) : null;
            if (! is_string($text)) {
                throw new RuntimeException('OpenAI Whisper response did not include transcript text.');
            }

            return new TranscriptionResult(
                transcript_text: $text,
                duration_seconds: (int) ($callRecording->recording_duration ?? 0),
                provider_name: 'openai_whisper',
            );
        } catch (ConnectionException $e) {
            throw new RuntimeException('OpenAI Whisper transcription timed out or could not connect.', 0, $e);
        } finally {
            @unlink($tmpPath);
        }
    }
}
