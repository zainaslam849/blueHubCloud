<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CallRecording;
use App\Models\CallTranscription;
use App\Models\CallSpeakerSegment;
use App\Models\TranscriptionUsage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TranscriptionCallbackController extends Controller
{
    public function __invoke(Request $request)
    {
        $raw = $request->getContent();
        $sigHeader = $request->header('X-Signature') ?? $request->header('X-Hub-Signature-256');

        Log::info('Transcription callback received', ['headers' => array_keys($request->headers->all())]);

        $secret = env('TRANSCRIPTION_CALLBACK_SECRET');
        if (empty($secret)) {
            Log::error('Transcription callback secret not configured');
            return response()->json(['error' => 'server misconfigured'], 500);
        }

        if (empty($sigHeader)) {
            Log::warning('Transcription callback missing signature header');
            return response()->json(['error' => 'missing signature'], 401);
        }

        $expected = hash_hmac('sha256', $raw, $secret);
        if (! hash_equals($expected, preg_replace('/^sha256=/', '', $sigHeader))) {
            Log::warning('Transcription callback signature mismatch', ['provided' => $sigHeader]);
            return response()->json(['error' => 'invalid signature'], 401);
        }

        $data = json_decode($raw, true);
        if (! is_array($data)) {
            Log::warning('Transcription callback bad json');
            return response()->json(['error' => 'invalid payload'], 400);
        }

        Log::info('Transcription callback verified', ['call_id' => $data['call_id'] ?? null]);

        // Expected payload keys: call_id OR call_recording_id, provider_name, language, transcript_text, confidence_score, duration_seconds, speaker_segments

        $callId = $data['call_id'] ?? null;
        $callRecordingId = $data['call_recording_id'] ?? null;
        $provider = $data['provider_name'] ?? ($data['provider'] ?? 'unknown');
        $language = $data['language'] ?? 'en';
        $transcriptText = $data['transcript_text'] ?? ($data['transcript'] ?? null);

        if (empty($callId) && empty($callRecordingId)) {
            Log::warning('Transcription callback missing identifiers', $data);
            return response()->json(['error' => 'missing identifiers'], 400);
        }

        if (empty($transcriptText)) {
            Log::warning('Transcription callback missing transcript', $data);
            return response()->json(['error' => 'missing transcript'], 400);
        }

        DB::beginTransaction();
        try {
            // Resolve call_recording if not provided
            if (empty($callRecordingId) && ! empty($callId) && ! empty($data['recording_id'])) {
                $callRecordingId = CallRecording::where('call_id', $callId)->where('idempotency_key', $data['recording_id'])->value('id');
            }

            if (empty($callRecordingId) && ! empty($callId)) {
                // try to find a recording for this call
                $callRecordingId = CallRecording::where('call_id', $callId)->value('id');
            }

            // Upsert call_transcription (idempotent by call_id+provider+language)
            $transcription = CallTranscription::updateOrCreate(
                ['call_id' => $callId, 'provider_name' => $provider, 'language' => $language],
                [
                    'transcript_text' => $transcriptText,
                    'duration_seconds' => $data['duration_seconds'] ?? 0,
                    'confidence_score' => $data['confidence_score'] ?? null,
                ]
            );

            Log::info('CallTranscription upserted', ['id' => $transcription->id, 'call_id' => $callId]);

            // Insert/update speaker segments if present (replace existing matching ranges to keep idempotent)
            if (! empty($data['speaker_segments']) && is_array($data['speaker_segments'])) {
                foreach ($data['speaker_segments'] as $seg) {
                    $start = isset($seg['start_second']) ? (int)$seg['start_second'] : null;
                    $end = isset($seg['end_second']) ? (int)$seg['end_second'] : null;
                    $label = $seg['speaker_label'] ?? ($seg['speaker'] ?? 'speaker');
                    $text = $seg['text'] ?? '';

                    if ($callId && $start !== null && $end !== null) {
                        // delete existing exact-match segment to avoid duplicates on replay
                        CallSpeakerSegment::where('call_id', $callId)
                            ->where('start_second', $start)
                            ->where('end_second', $end)
                            ->delete();

                        CallSpeakerSegment::create([
                            'call_id' => $callId,
                            'speaker_label' => $label,
                            'start_second' => $start,
                            'end_second' => $end,
                            'text' => $text,
                        ]);
                    }
                }
                Log::info('Speaker segments processed', ['call_id' => $callId, 'count' => count($data['speaker_segments'])]);
            }

            // Update transcription_usages if we can resolve a call_recording
            if (! empty($callRecordingId)) {
                TranscriptionUsage::updateOrCreate(
                    ['call_recording_id' => $callRecordingId, 'provider_name' => $provider, 'language' => $language],
                    [
                        'company_id' => $data['company_id'] ?? null,
                        'duration_seconds' => $data['duration_seconds'] ?? 0,
                        'cost_estimate' => $data['cost_estimate'] ?? 0,
                        'currency' => $data['currency'] ?? 'USD',
                    ]
                );

                // Mark call_recording as transcribed
                CallRecording::where('id', $callRecordingId)->update(['status' => CallRecording::STATUS_TRANSCRIBED]);
                Log::info('CallRecording marked transcribed', ['call_recording_id' => $callRecordingId]);
            }

            DB::commit();

            Log::info('Transcription callback processed successfully', ['call_id' => $callId, 'call_recording_id' => $callRecordingId]);
            return response()->json(['status' => 'ok'], 200);

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Error processing transcription callback', ['error' => $e->getMessage(), 'payload' => $data]);
            return response()->json(['error' => 'internal server error'], 500);
        }
    }
}
