<?php

namespace App\Services\Pbx;

use DateTimeInterface;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Mock PBX client for local development.
 *
 * Deterministic, returns three calls (two with recordings) and streams
 * local test audio files from storage/app/test-audio/ for downloads.
 */
class MockPbxwareClient
{
    protected array $recordingMap = [
        // recording_id => local storage path (storage/app/test-audio)
        'mock-rec-1' => 'test-audio/sample_1.mp3',
        'mock-rec-2' => 'test-audio/sample_2.mp3',
    ];

    /**
     * Return an array of calls since the provided DateTime.
     * Signature: fetchCalls(DateTimeInterface $since): array
     */
    public function fetchCalls(DateTimeInterface $since): array
    {
        // Create three deterministic calls spaced after $since
        $calls = [];
        $base = (new \DateTimeImmutable($since->format('c')));

        for ($i = 1; $i <= 3; $i++) {
            $started = $base->modify("+{$i} minutes");
            $duration = 30 * $i; // seconds

            $call = [
                'call_uid' => "mock-call-{$i}",
                'started_at' => $started->format('c'),
                'duration' => $duration,
                'direction' => $i % 2 === 0 ? 'inbound' : 'outbound',
                'extension' => '100' . $i,
                'from_number' => '+611000000' . $i,
                'to_number' => '+612000000' . $i,
                'status' => 'completed',
            ];

            // Attach recordings for calls 1 and 2
            if ($i <= 2) {
                $recId = "mock-rec-{$i}";
                $call['recordings'] = [
                    [
                        'id' => $recId,
                        'recording_id' => $recId,
                        'duration' => $duration,
                        'file_name' => $this->recordingMap[$recId] ?? null,
                    ],
                ];
            }

            $calls[] = $call;
        }

        Log::info('MockPbxwareClient: fetchCalls', ['since' => $since->format('c'), 'count' => count($calls)]);
        return $calls;
    }

    /**
     * Authoritative-contract helper: return available PBXware server IDs.
     */
    public function fetchTenantServers(): array
    {
        return [
            ['id' => '2', 'name' => 'BHubcomms'],
            ['id' => '3', 'name' => 'BHubAMWU'],
        ];
    }

    public function fetchTenantServerIds(): array
    {
        return array_map(static fn ($s) => $s['id'], $this->fetchTenantServers());
    }

    /**
     * Authoritative-contract helper: pbxware.cdr.download returns CDR records.
     */
    public function fetchCdrRecords(array $params): array
    {
        $now = time();

        $rows = [];
        for ($i = 1; $i <= 3; $i++) {
            $rows[] = [
                'uniqueid' => "mock-uniqueid-{$i}",
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
     * Return a PBXware-style CDR CSV structure: raw header + row arrays.
     * This allows ingestion to treat calls as numeric arrays, matching the
     * real PBXware CSV response.
     *
     * @return array{header: array<int,string>, rows: array<int, array<int,string>>}
     */
    public function fetchCdrList(array $params = []): array
    {
        $now = time();

        // Minimal header for readability; ingestion uses fixed indexes.
        $header = [
            'col0', 'col1', 'timestamp', 'col3', 'col4', 'col5', 'col6',
            'uniqueid', 'recording_path', 'recording_available',
        ];

        // Build three deterministic rows.
        // Index mapping for ingestion:
        // [2] timestamp, [7] Unique ID, [8] recording path, [9] recording available
        $rows = [];
        for ($i = 1; $i <= 3; $i++) {
            $uniqueId = "mock-uniqueid-{$i}";
            $recordingPath = $i <= 2 ? "mock-rec-{$i}" : '';
            $recordingAvailable = $i <= 2 ? 'True' : 'False';
            $rows[] = [
                '',
                '',
                (string) ($now - (60 * $i)),
                '',
                '',
                '',
                '',
                $uniqueId,
                $recordingPath,
                $recordingAvailable,
            ];
        }

        Log::info('MockPbxwareClient: fetchCdrList', ['params' => $params, 'count' => count($rows)]);
        return ['header' => $header, 'rows' => $rows];
    }

    /**
     * Preferred name in the ingestion pipeline.
     */
    public function fetchCdrCsv(array $params = []): array
    {
        return $this->fetchCdrList($params);
    }

    /**
     * Dynamic PBXware-style action fetch for mock mode.
     * Returns JSON arrays or CSV structures matching the real client.
     */
    public function fetchAction(string $action, array $params = []): array|string
    {
        if ($action === 'pbxware.tenant.list') {
            // Keys are server IDs per authoritative contract.
            return ['mock-server-1' => ['name' => 'Mock Server 1']];
        }

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

    /**
     * Streaming variant for download-style actions.
     * Returns a stream resource (compatible with S3 putObject Body).
     */
    public function fetchActionStream(string $action, array $params = [])
    {
        if ($action !== 'pbxware.cdr.download') {
            throw new \InvalidArgumentException('Unsupported mock stream action: ' . $action);
        }

        $ref = $params['recording'] ?? $params['recording_id'] ?? $params['id'] ?? $params['file'] ?? null;
        if (! is_string($ref) || $ref === '') {
            throw new \InvalidArgumentException('Missing recording reference for mock download');
        }

        return $this->downloadRecordingStream($ref);
    }

    /**
     * Official-contract helper mirroring the real client.
     */
    public function downloadRecordingStreamByRecordingPath(string $recordingPath)
    {
        return $this->downloadRecordingStream($recordingPath);
    }

    /**
     * NOTE: Action probing/discovery is intentionally not implemented.
     * Use explicitly configured/documented actions only.
     */

    /**
     * Return recording metadata for given call IDs.
     * Signature: fetchRecordings(array $callIds): array
     */
    public function fetchRecordings(array $callIds): array
    {
        $results = [];
        foreach ($callIds as $callId) {
            // map call uid like mock-call-1 => mock-rec-1, etc.
            if (preg_match('/mock-call-(\d+)/', $callId, $m)) {
                $n = (int)$m[1];
                if ($n <= 2) {
                    $recId = "mock-rec-{$n}";
                    $results[] = [
                        'call_uid' => $callId,
                        'recording_id' => $recId,
                        'file_name' => $this->recordingMap[$recId] ?? null,
                        'duration' => 30 * $n,
                    ];
                }
            }
        }

        Log::info('MockPbxwareClient: fetchRecordings', ['requested' => $callIds, 'found' => count($results)]);
        return $results;
    }

    /**
     * Stream a local recording file for the given recording id.
     * Signature: downloadRecording(string $recordingId): StreamedResponse
     */
    public function downloadRecording(string $recordingId): StreamedResponse
    {
        $path = $this->recordingMap[$recordingId] ?? null;
        if (! $path) {
            Log::error('MockPbxwareClient: unknown recording id', ['recording_id' => $recordingId]);
            throw new \InvalidArgumentException('Unknown recording id: ' . $recordingId);
        }

        if (! Storage::disk('local')->exists($path)) {
            Log::error('MockPbxwareClient: local test audio not found', ['path' => $path]);
            throw new \RuntimeException('Test audio file not found: ' . $path);
        }

        return new StreamedResponse(function () use ($path) {
            $stream = Storage::disk('local')->readStream($path);
            if ($stream === false) {
                throw new \RuntimeException('Failed to open test audio stream: ' . $path);
            }

            while (! feof($stream)) {
                echo fread($stream, 1024 * 16);
                @flush();
                @ob_flush();
            }

            if (is_resource($stream)) {
                fclose($stream);
            }
        }, 200, [
            'Content-Type' => 'audio/mpeg',
            'Content-Disposition' => 'attachment; filename="' . basename($path) . '"',
        ]);
    }

    /**
     * Return a local stream resource for the given recording id.
     * This is used by the ingestion job to upload directly to S3 without
     * writing a temporary file to disk.
     *
     * Signature: downloadRecordingStream(string $recordingId): resource
     */
    public function downloadRecordingStream(string $recordingId)
    {
        $path = $this->recordingMap[$recordingId] ?? null;
        if (! $path) {
            Log::error('MockPbxwareClient: unknown recording id for stream', ['recording_id' => $recordingId]);
            throw new \InvalidArgumentException('Unknown recording id: ' . $recordingId);
        }

        if (! Storage::disk('local')->exists($path)) {
            Log::error('MockPbxwareClient: local test audio not found for stream', ['path' => $path]);
            throw new \RuntimeException('Test audio file not found: ' . $path);
        }

        $stream = Storage::disk('local')->readStream($path);
        if ($stream === false) {
            throw new \RuntimeException('Failed to open test audio stream: ' . $path);
        }

        return $stream;
    }
}
