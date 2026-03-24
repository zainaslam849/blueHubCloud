<?php

namespace App\Services\Normalization;

use App\Models\Call;
use App\Models\Company;
use App\Models\CompanyPbxAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * PHASE 3: NORMALIZATION LAYER (PROVIDER AGNOSTIC)
 * 
 * Converts raw PBXware JSON payloads into our internal normalized schema.
 * Key principles:
 * - Does NOT assume field names remain constant across API versions
 * - Maps defensively with null coalescing
 * - Logs unmapped fields for debugging
 * - Supports future provider versions (v8, etc.) without code changes
 * 
 * Normalized Fields (provider-agnostic):
 * - provider: 'pbxware', '3cx', 'zoom_phone', etc.
 * - provider_call_id: Unique ID from provider (e.g., CDR uniqueid)
 * - server_id: Server/Tenant ID
 * - company_id: Our internal company identifier
 * - direction: 'inbound', 'outbound', 'internal', 'unknown'
 * - duration: Seconds
 * - status: 'completed', 'no_answer', 'busy', 'failed', 'unknown'
 * - started_at, answered_at, hangup_cause: Timestamps and codes
 * - source_number, destination_number: E.164 or DID format
 * - answering_extension, ring_group: If available
 * - call_transcriptions: Separate table for transcription data
 */
class PbxPayloadNormalizer
{
    /**
     * Normalize a raw CDR payload from PBXware.
     * 
     * PBXware CDR returns 12 fields (as of v7):
     * From, To, Date/Time, Duration, Status, Unique ID, Recording Path,
     * Recording Available, Location Type, MOS, Rating Duration, Rating Cost
     * 
     * @param array $cdrRow Single CDR row from PBXware API
     * @param string $serverId Server ID from provider
     * @param CompanyPbxAccount $pbxAccount Linked PBX account
     * @return array Normalized call data ready for database storage
     */
    public static function normalizeCdr(
        array $cdrRow,
        string $serverId,
        CompanyPbxAccount $pbxAccount
    ): array {
        // Extract PBXware v7 fields with defensive null coalescing
        $from = $cdrRow['From'] ?? $cdrRow['from'] ?? null;
        $to = $cdrRow['To'] ?? $cdrRow['to'] ?? null;
        $dateTime = $cdrRow['Date/Time'] ?? $cdrRow['datetime'] ?? $cdrRow['date_time'] ?? null;
        $duration = (int) ($cdrRow['Duration'] ?? $cdrRow['duration'] ?? 0);
        $status = $cdrRow['Status'] ?? $cdrRow['status'] ?? 'unknown';
        $uniqueId = $cdrRow['Unique ID'] ?? $cdrRow['unique_id'] ?? $cdrRow['uniqueid'] ?? null;

        // Optional fields that may or may not be present
        $recordingPath = $cdrRow['Recording Path'] ?? $cdrRow['recording_path'] ?? null;
        $recordingAvailable = $cdrRow['Recording Available'] ?? $cdrRow['recording_available'] ?? false;
        $locationXtype = $cdrRow['Location Type'] ?? $cdrRow['location_type'] ?? null;
        $mos = $cdrRow['MOS'] ?? $cdrRow['mos'] ?? null;

        // Infer direction from From/To (heuristic - will be overridden if API provides it)
        $direction = self::inferDirection($from, $to);

        // Normalize timestamp
        $startedAt = self::normalizeTimestamp($dateTime);

        // Normalize status
        $normalizedStatus = self::normalizeStatus($status);

        // Log unmapped fields for debugging (future-proofing for v8)
        $unmappedFields = array_diff_key($cdrRow, array_flip([
            'From', 'from',
            'To', 'to',
            'Date/Time', 'datetime', 'date_time',
            'Duration', 'duration',
            'Status', 'status',
            'Unique ID', 'unique_id', 'uniqueid',
            'Recording Path', 'recording_path',
            'Recording Available', 'recording_available',
            'Location Type', 'location_type',
            'MOS', 'mos',
        ]));

        if (!empty($unmappedFields)) {
            Log::info('PbxPayloadNormalizer: Unmapped CDR fields detected (possible v8 fields)', [
                'unmapped_fields' => array_keys($unmappedFields),
                'sample_values' => array_slice($unmappedFields, 0, 3),
            ]);
        }

        return [
            'company_id' => $pbxAccount->company_id,
            'company_pbx_account_id' => $pbxAccount->id,
            'server_id' => $serverId,
            'pbx_unique_id' => $uniqueId,
            'provider' => 'pbxware', // Will be parameterized for multi-provider support
            'provider_call_id' => $uniqueId,
            'from' => $from,
            'to' => $to,
            'direction' => $direction,
            'duration_seconds' => $duration,
            'status' => $normalizedStatus,
            'started_at' => $startedAt,
            'has_transcription' => $recordingAvailable ? true : false,
            'pbx_metadata' => json_encode([
                'location_type' => $locationXtype,
                'mos_score' => $mos,
                'recording_path' => $recordingPath,
                'recording_available' => $recordingAvailable,
                'raw_status' => $status,
            ]),
        ];
    }

    /**
     * Normalize a raw transcription payload from PBXware.
     * 
     * @param array $transcriptionData Transcription response from API
     * @param Call $call Related call record
     * @return array Normalized transcription data ready for storage
     */
    public static function normalizeTranscription(
        array $transcriptionData,
        Call $call
    ): array {
        $transcriptText = self::extractTranscriptText($transcriptionData);
        $confidence = self::extractTranscriptConfidence($transcriptionData);

        return [
            'call_id' => $call->id,
            'transcript_text' => $transcriptText,
            'transcript_confidence' => $confidence,
            'processed_at' => now(),
        ];
    }

    /**
     * Extract transcript text from known PBXware response shapes.
     */
    private static function extractTranscriptText(array $transcriptionData): ?string
    {
        $candidateKeys = [
            'Transcript',
            'transcript',
            'text',
            'message',
        ];

        foreach ($candidateKeys as $key) {
            $value = $transcriptionData[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        $nestedCandidates = [
            data_get($transcriptionData, 'result.transcript'),
            data_get($transcriptionData, 'result.text'),
            data_get($transcriptionData, 'data.transcript'),
            data_get($transcriptionData, 'data.text'),
            data_get($transcriptionData, 'payload.transcript'),
            data_get($transcriptionData, 'payload.text'),
        ];

        foreach ($nestedCandidates as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        $vttWords = data_get($transcriptionData, 'vtt.words');
        if (is_array($vttWords) && ! empty($vttWords)) {
            $tokens = [];
            foreach ($vttWords as $word) {
                $token = is_array($word) ? ($word['word'] ?? null) : null;
                if (is_string($token) && trim($token) !== '') {
                    $tokens[] = trim($token);
                }
            }

            if (! empty($tokens)) {
                return implode(' ', $tokens);
            }
        }

        return null;
    }

    /**
     * Extract transcription confidence from known PBXware response shapes.
     */
    private static function extractTranscriptConfidence(array $transcriptionData): float
    {
        $candidates = [
            $transcriptionData['Confidence'] ?? null,
            $transcriptionData['confidence'] ?? null,
            data_get($transcriptionData, 'result.confidence'),
            data_get($transcriptionData, 'data.confidence'),
        ];

        foreach ($candidates as $candidate) {
            if (is_numeric($candidate)) {
                return (float) $candidate;
            }
        }

        return 0.0;
    }

    /**
     * Infer call direction from source and destination numbers.
     * 
     * This is a heuristic - if the PBX API provides direction, use that instead.
     * 
     * @param string|null $from Source number
     * @param string|null $to Destination number
     * @return string 'inbound', 'outbound', 'internal', or 'unknown'
     */
    private static function inferDirection(?string $from, ?string $to): string
    {
        if (!$from || !$to) {
            return 'unknown';
        }

        // Both are likely extensions (internal)
        if (is_numeric($from) && strlen($from) <= 5 && is_numeric($to) && strlen($to) <= 5) {
            return 'internal';
        }

        // From is extension, to is outside number = outbound
        if (is_numeric($from) && strlen($from) <= 5 && !is_numeric($to)) {
            return 'outbound';
        }

        // To is extension, from is outside = inbound
        if (is_numeric($to) && strlen($to) <= 5 && !is_numeric($from)) {
            return 'inbound';
        }

        // Both are long numbers (likely external) = ambiguous
        return 'unknown';
    }

    /**
     * Normalize various timestamp formats to Carbon datetime.
     * 
     * @param string|null $timestamp Raw timestamp from API
     * @return Carbon|null Normalized timestamp or null
     */
    private static function normalizeTimestamp(?string $timestamp): ?Carbon
    {
        if (!$timestamp) {
            return null;
        }

        try {
            return Carbon::parse($timestamp);
        } catch (\Exception $e) {
            Log::warning('PbxPayloadNormalizer: Unable to parse timestamp', [
                'timestamp' => $timestamp,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Normalize call status from PBXware to our standard values.
     * 
     * PBXware status field may contain:
     * - 'Answered' / 'answered' / '8'
     * - 'No Answer' / 'no_answer'
     * - 'Busy' / 'busy'
     * - 'Failed' / 'failed'
     * 
     * @param string $status Raw status from API
     * @return string Normalized status
     */
    private static function normalizeStatus(string $status): string
    {
        $status = strtolower(trim($status));

        return match ($status) {
            'answered', '8', 'completed' => 'completed',
            'no answer', 'no_answer', '9' => 'no_answer',
            'busy', '10' => 'busy',
            'failed', 'error' => 'failed',
            default => 'unknown',
        };
    }
}
