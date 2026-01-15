<?php

namespace App\Services\Pbx;

use Illuminate\Support\Facades\Log;

/**
 * Mock PBX client for local development.
 *
 * Deterministic, returns tenant servers, CDR records, and transcriptions.
 */
class MockPbxwareClient
{
    /**
     * Authoritative-contract helper: pbxware.cdr.download returns CDR records.
     */
    public function fetchCdrRecords(array $params): array
    {
        $now = time();

        $rows = [];
        for ($i = 1; $i <= 3; $i++) {
            $uniqueid = "mock-uniqueid-{$i}";
            $rows[$uniqueid] = [
                'started_at' => date('c', $now - (60 * $i)),
                'duration' => 30 * $i,
                'direction' => $i % 2 === 0 ? 'inbound' : 'outbound',
                'status' => '8',
                'src' => '+611000000' . $i,
                'dst' => '+612000000' . $i,
            ];
        }

        Log::info('MockPbxwareClient: fetchCdrRecords', ['params' => $params, 'count' => count($rows)]);
        return $rows;
    }

    /**
     * Authoritative-contract helper: pbxware.transcription.get returns per-call transcript.
     */
    public function fetchTranscription(array $params): array
    {
        $uniqueid = (string) ($params['uniqueid'] ?? '');
        $uniqueid = trim($uniqueid);

        return [
            'provider_name' => 'pbxware',
            'language' => 'en',
            'confidence_score' => 0.9,
            'duration_seconds' => 60,
            'transcript_text' => $uniqueid !== '' ? "Mock transcript for {$uniqueid}" : 'Mock transcript',
        ];
    }

    /**
     * Dynamic PBXware-style action fetch for mock mode.
     * Returns JSON arrays/objects or plain text matching the real client.
     */
    public function fetchAction(string $action, array $params = []): array|string
    {
        if ($action === 'pbxware.cdr.download') {
            return $this->fetchCdrRecords($params);
        }

        if ($action === 'pbxware.transcription.get') {
            return $this->fetchTranscription($params);
        }

        if ($action === 'pbxware.ext.list') {
            return [
                ['extension' => '1001', 'name' => 'Mock User 1'],
                ['extension' => '1002', 'name' => 'Mock User 2'],
            ];
        }

        // Unknown action
        return ['error' => 'Invalid action'];
    }
}
