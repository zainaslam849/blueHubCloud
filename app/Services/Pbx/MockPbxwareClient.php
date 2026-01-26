<?php

namespace App\Services\Pbx;

use Illuminate\Support\Facades\Log;

/**
 * Mock PBX client for local development.
 *
 * Deterministic, returns CDR records and transcriptions.
 */
class MockPbxwareClient
{
    /**
     * Authoritative-contract helper: pbxware.cdr.download returns CDR records.
     */
    public function fetchCdrRecords(array $params): array
    {
        $now = time();

        // Bluehub PBXware API contract:
        // - header: array of column names
        // - csv: array of rows (array-of-arrays)
        // Fixed indexes used by ingestion:
        //   csv[2] = epoch seconds
        //   csv[6] = status
        //   csv[7] = uniqueid
        $header = [
            'col0',
            'col1',
            'date_time_epoch',
            'col3',
            'col4',
            'col5',
            'status',
            'uniqueid',
            'col8',
            'col9',
        ];

        $csv = [];
        for ($i = 1; $i <= 3; $i++) {
            $epoch = $now - (60 * $i);
            $uniqueid = "mock-uniqueid-{$i}";
            $csv[] = [
                '',
                '',
                $epoch,
                '',
                '',
                '',
                '8',
                $uniqueid,
                '',
                '',
            ];
        }

        Log::info('MockPbxwareClient: fetchCdrRecords', ['params' => $params, 'count' => count($csv)]);
        return ['header' => $header, 'csv' => $csv];
    }

    /**
     * Authoritative-contract helper: pbxware.transcription.get returns per-call transcript.
     */
    public function fetchTranscription(array $params): array
    {
        $uniqueid = (string) ($params['uniqueid'] ?? '');
        $uniqueid = trim($uniqueid);

        return [
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

        // Unknown action
        return ['error' => 'Invalid action'];
    }
}
