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
            'From',
            'To',
            'Date/Time',
            'Duration',
            'col4',
            'col5',
            'Status',
            'Unique ID',
            'Recording Path',
            'Recording Available',
        ];

        $csv = [];
        for ($i = 1; $i <= 3; $i++) {
            $epoch = $now - (3600 * $i);
            $uniqueid = "mock-uniqueid-{$i}";
            $csv[] = [
                '100',
                '200',
                $epoch,
                // 90s, 180s, 270s — exercises prorated credit deduction.
                (string) (90 * $i),
                '',
                '',
                'ANSWERED',
                $uniqueid,
                "/recordings/{$uniqueid}.wav",
                '1',
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
     * Authoritative-contract helper: pbxware.tenant.list returns tenants
     * keyed by their PBX tenant id (server_id).
     */
    public function fetchTenantList(): array
    {
        return [
            '2' => [
                'name' => 'Mock Tenant Alpha',
                'tenantcode' => 'mockalpha',
                'package' => 'Mock Package',
            ],
            '3' => [
                'name' => 'Mock Tenant Beta',
                'tenantcode' => 'mockbeta',
                'package' => 'Mock Package',
            ],
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

        if ($action === 'pbxware.tenant.list') {
            return $this->fetchTenantList();
        }

        // Unknown action
        return ['error' => 'Invalid action'];
    }
}
