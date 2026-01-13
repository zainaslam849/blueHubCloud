<?php

namespace App\Jobs;

use App\Models\Call;
use App\Models\CompanyPbxAccount;
use App\Models\CallRecording;
use App\Services\PbxwareClient;
use App\Services\Pbx\PbxClientResolver;
use Illuminate\Support\Facades\Config;
use App\Exceptions\PbxwareClientException;
use Aws\S3\S3Client;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class IngestPbxCallsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public int $backoff = 60;

    protected int $companyId;
    protected int $companyPbxAccountId;
    protected array $params;

    public function __construct(int $companyId, int $companyPbxAccountId, array $params = [])
    {
        $this->companyId = $companyId;
        $this->companyPbxAccountId = $companyPbxAccountId;
        $this->params = $params;
        $this->onQueue('ingest-pbx');
    }

    public function handle()
    {
        $pbxAccount = CompanyPbxAccount::find($this->companyPbxAccountId);
        if (! $pbxAccount) {
            Log::warning('Company PBX account not found', ['company_pbx_account_id' => $this->companyPbxAccountId]);
            return;
        }

        $client = PbxClientResolver::resolve();

        try {
            Log::info('Starting PBX calls ingestion', ['company_id' => $this->companyId, 'company_pbx_account_id' => $this->companyPbxAccountId]);

            // Log mock mode active for developer clarity
            if (Config::get('pbx.mode') === 'mock') {
                Log::info('🟢 Mock PBX mode active — using local test audio and mock client', ['company_id' => $this->companyId]);
            }

            // PBXware two-step CDR flow:
            // 1) Fetch CDR CSV rows via a documented CDR LIST/EXPORT action (client->fetchCdrCsv)
            // 2) Download a single recording via pbxware.cdr.download&recording=<path>

            $range = $this->determineRequestedRange();
            $chunks = $this->monthChunks($range['from'], $range['to']);

            $callsCreated = 0;
            $callsSkipped = 0;
            $recordingsDownloaded = 0;
            $recordingsSkipped = 0;

            foreach ($chunks as $chunk) {
                $params = $this->buildCdrCsvParams($chunk['from'], $chunk['to']);

                Log::info('PBXware CDR date range used', [
                    'company_id' => $this->companyId,
                    'company_pbx_account_id' => $this->companyPbxAccountId,
                    'start' => $params['start'],
                    'end' => $params['end'],
                    'starttime' => $params['starttime'],
                    'endtime' => $params['endtime'],
                    'limit' => $params['limit'],
                ]);

                $csv = $client->fetchCdrCsv($params);
                $header = is_array($csv) ? ($csv['header'] ?? null) : null;
                $rows = is_array($csv) ? ($csv['rows'] ?? []) : [];

                Log::info('PBXware CDR CSV rows received', [
                    'company_id' => $this->companyId,
                    'company_pbx_account_id' => $this->companyPbxAccountId,
                    'rows' => is_array($rows) ? count($rows) : 0,
                ]);

                if (! is_array($rows)) {
                    continue;
                }

                // Pagination support depends on the configured PBXware CDR action contract.
                // Do not auto-paginate unless the vendor documentation explicitly requires it.

                foreach ($rows as $row) {
                    if (! is_array($row)) {
                        continue;
                    }

                    $normalized = $this->normalizeCdrRow($row, is_array($header) ? $header : null);
                    $callUid = $normalized['call_uid'] ?? null;
                    if (! is_string($callUid) || trim($callUid) === '') {
                        continue;
                    }

                    // The calls table requires direction/status/started_at.
                    // Do not guess; if the CSV doesn't provide these fields, skip the row.
                    $direction = $normalized['direction'] ?? null;
                    $status = $normalized['status'] ?? null;
                    $startedAt = $normalized['started_at'] ?? null;

                    if (! is_string($direction) || trim($direction) === '' || ! is_string($status) || trim($status) === '' || ! is_string($startedAt) || trim($startedAt) === '') {
                        Log::warning('Skipping PBX call: missing required call fields from CDR CSV', [
                            'company_id' => $this->companyId,
                            'company_pbx_account_id' => $this->companyPbxAccountId,
                            'call_uid' => $callUid,
                            'has_direction' => is_string($direction) && trim($direction) !== '',
                            'has_status' => is_string($status) && trim($status) !== '',
                            'has_started_at' => is_string($startedAt) && trim($startedAt) !== '',
                        ]);
                        continue;
                    }

                    $call = Call::firstOrCreate(
                        ['call_uid' => $callUid],
                        array_filter([
                            'company_id' => $this->companyId,
                            'company_pbx_account_id' => $this->companyPbxAccountId,
                            'direction' => trim($direction),
                            'from_number' => $normalized['from_number'] ?? null,
                            'to_number' => $normalized['to_number'] ?? null,
                            'started_at' => $startedAt,
                            'ended_at' => $normalized['ended_at'] ?? null,
                            'duration_seconds' => $normalized['duration_seconds'] ?? null,
                            'status' => trim($status),
                        ], static function ($v) {
                            return $v !== null && $v !== '';
                        })
                    );

                    if (! $call->wasRecentlyCreated) {
                        $callsSkipped++;
                        continue;
                    }

                    $callsCreated++;

                    $recordingAvailable = (bool) ($normalized['has_recording'] ?? false);
                    $recordingPath = $normalized['recording_reference'] ?? null;

                    if (! $recordingAvailable) {
                        Log::info('Recording skipped (not available)', [
                            'company_id' => $this->companyId,
                            'company_pbx_account_id' => $this->companyPbxAccountId,
                            'call_uid' => $callUid,
                        ]);
                        $recordingsSkipped++;
                        continue;
                    }
                    if (! is_string($recordingPath) || trim($recordingPath) === '') {
                        Log::info('Recording skipped (missing recording path)', [
                            'company_id' => $this->companyId,
                            'company_pbx_account_id' => $this->companyPbxAccountId,
                            'call_uid' => $callUid,
                        ]);
                        $recordingsSkipped++;
                        continue;
                    }

                    // Idempotency: do not re-download if a recording already exists for this call.
                    if (CallRecording::where('call_id', $call->id)->exists()) {
                        Log::info('Recording skipped (already exists for call)', [
                            'company_id' => $this->companyId,
                            'company_pbx_account_id' => $this->companyPbxAccountId,
                            'call_id' => $call->id,
                            'call_uid' => $callUid,
                        ]);
                        $recordingsSkipped++;
                        continue;
                    }

                    $this->downloadRecordingToS3($client, $call->id, $callUid, $recordingPath);
                    $recordingsDownloaded++;
                }
            }

            Log::info('PBXware ingestion summary', [
                'company_id' => $this->companyId,
                'company_pbx_account_id' => $this->companyPbxAccountId,
                'calls_created' => $callsCreated,
                'calls_skipped_existing' => $callsSkipped,
                'recordings_downloaded' => $recordingsDownloaded,
                'recordings_skipped' => $recordingsSkipped,
            ]);

        } catch (PbxwareClientException $e) {
            Log::error('PBX client error during calls ingestion', ['company_id' => $this->companyId, 'error' => $e->getMessage()]);
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Unexpected error during calls ingestion', ['company_id' => $this->companyId, 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    protected function parseDate($value)
    {
        if (empty($value)) {
            return null;
        }
        try {
            if (is_numeric($value)) {
                return \Carbon\Carbon::createFromTimestamp((int) $value)->toDateTimeString();
            }
            return \Carbon\Carbon::parse($value)->toDateTimeString();
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function normalizePbxwareQueryParams(array $params): array
    {
        // PBXware expects query params like start/end/starttime/endtime/limit/export.
        // Recording downloads use pbxware.cdr.download&recording=<path>.
        // Do NOT guess or pass unknown keys.
        $out = [];
        foreach ($params as $key => $value) {
            if (! in_array($key, ['start', 'end', 'starttime', 'endtime', 'limit', 'export'], true)) {
                continue;
            }

            $out[$key] = $value;
        }

        return $out;
    }

    private function determineRequestedRange(): array
    {
        $to = \Carbon\Carbon::now();
        $from = $to->copy()->subDay();

        if (! empty($this->params['from'])) {
            try {
                $from = $this->parseRangeDate($this->params['from']);
            } catch (\Throwable $e) {
                // ignore
            }
        }

        if (! empty($this->params['to'])) {
            try {
                $to = $this->parseRangeDate($this->params['to']);
            } catch (\Throwable $e) {
                // ignore
            }
        }

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        return ['from' => $from, 'to' => $to];
    }

    private function parseRangeDate($value): \Carbon\Carbon
    {
        if ($value instanceof \DateTimeInterface) {
            return \Carbon\Carbon::instance($value);
        }
        return \Carbon\Carbon::parse((string) $value);
    }

    /**
     * @return array<int, array{from: \Carbon\Carbon, to: \Carbon\Carbon}>
     */
    private function monthChunks(\Carbon\Carbon $from, \Carbon\Carbon $to): array
    {
        $chunks = [];

        $cursor = $from->copy()->startOfMonth();
        $endMonth = $to->copy()->startOfMonth();

        while ($cursor->lessThanOrEqualTo($endMonth)) {
            $monthStart = $cursor->copy()->startOfMonth();
            $monthEnd = $cursor->copy()->endOfMonth();

            $chunkFrom = $from->copy()->greaterThan($monthStart) ? $from->copy() : $monthStart;
            $chunkTo = $to->copy()->lessThan($monthEnd) ? $to->copy() : $monthEnd;

            $chunks[] = ['from' => $chunkFrom, 'to' => $chunkTo];

            $cursor = $cursor->copy()->addMonth()->startOfMonth();
        }

        return $chunks;
    }

    private function buildCdrCsvParams(\Carbon\Carbon $from, \Carbon\Carbon $to): array
    {
        $limit = (int) ($this->params['limit'] ?? 1000);
        if ($limit <= 0) {
            $limit = 1000;
        }
        $limit = min($limit, 1000);

        $params = [
            'start' => $from->format('M-d-Y'),
            'end' => $to->format('M-d-Y'),
            'starttime' => $from->format('H:i:s'),
            'endtime' => $to->format('H:i:s'),
            'limit' => $limit,
        ];

        // Some PBXware CDR export actions require an explicit export flag.
        // Do not assume; allow the operator to pass it via params if required.
        if (array_key_exists('export', $this->params)) {
            $params['export'] = $this->params['export'];
        }

        return $this->normalizePbxwareQueryParams($params);
    }

    /**
     * @return array{0: array<int,string>|null, 1: array<int, mixed>}
     */
    private function extractRows(array|string $result): array
    {
        if (is_string($result) || $result === []) {
            return [null, []];
        }

        if (isset($result['header'], $result['rows']) && is_array($result['rows'])) {
            return [is_array($result['header']) ? $result['header'] : null, $result['rows']];
        }

        if (isset($result['data']) && is_array($result['data'])) {
            return [null, $result['data']];
        }
        if (isset($result['items']) && is_array($result['items'])) {
            return [null, $result['items']];
        }

        // If it's a list, treat it as rows.
        if (array_values($result) === $result) {
            return [null, $result];
        }

        return [null, []];
    }

    /**
     * Normalize a PBXware CDR record into the fields used by ingestion.
     *
     * Output:
     * - call_uid (required)
     * - started_at (string|null)
        * - direction (string|null)
        * - status (string|null)
        * - from_number (string|null)
        * - to_number (string|null)
        * - ended_at (string|null)
        * - duration_seconds (int|null)
     * - has_recording (bool)
     * - recording_reference (string|null)
     */
    private function normalizeCdrRow($row, ?array $header = null): array
    {
        // CSV-style row: numeric array with optional header
        if (is_array($row) && array_values($row) === $row) {
            $byName = [];
            if (is_array($header) && count($header) === count($row)) {
                foreach ($header as $i => $name) {
                    $byName[strtolower(trim((string) $name))] = $row[$i] ?? null;
                }
            }

            $callUid = $this->firstNonEmpty([
                $byName['uniqueid'] ?? null,
                $byName['unique_id'] ?? null,
                $byName['call_uid'] ?? null,
                // Legacy fixed-index fallback (known PBXware CDR CSV layout)
                $row[7] ?? null,
            ]);

            if ($callUid === null) {
                Log::warning('Skipping PBX call: no unique identifier present', [
                    'company_id' => $this->companyId,
                    'company_pbx_account_id' => $this->companyPbxAccountId,
                ]);
                return ['call_uid' => null];
            }

            $startedAtRaw = $this->firstNonEmpty([
                $byName['timestamp'] ?? null,
                $byName['started_at'] ?? null,
                $byName['start'] ?? null,
                $byName['calldate'] ?? null,
                $byName['call_date'] ?? null,
                $row[2] ?? null,
            ]);
            $startedAt = $startedAtRaw !== null ? $this->parseDate($startedAtRaw) : null;

            $direction = $this->firstNonEmpty([
                $byName['direction'] ?? null,
                $byName['call_direction'] ?? null,
                $byName['calltype'] ?? null,
                $byName['call_type'] ?? null,
            ]);

            $status = $this->firstNonEmpty([
                $byName['status'] ?? null,
                $byName['disposition'] ?? null,
                $byName['call_status'] ?? null,
            ]);

            $fromNumber = $this->firstNonEmpty([
                $byName['from_number'] ?? null,
                $byName['src'] ?? null,
                $byName['source'] ?? null,
                $byName['callerid'] ?? null,
                $byName['caller_id'] ?? null,
            ]);

            $toNumber = $this->firstNonEmpty([
                $byName['to_number'] ?? null,
                $byName['dst'] ?? null,
                $byName['destination'] ?? null,
                $byName['callee'] ?? null,
            ]);

            $endedAtRaw = $this->firstNonEmpty([
                $byName['ended_at'] ?? null,
                $byName['end'] ?? null,
            ]);
            $endedAt = $endedAtRaw !== null ? $this->parseDate($endedAtRaw) : null;

            $durationRaw = $this->firstNonEmpty([
                $byName['duration_seconds'] ?? null,
                $byName['duration'] ?? null,
                $byName['billsec'] ?? null,
                $byName['bill_sec'] ?? null,
            ]);
            $durationSeconds = null;
            if ($durationRaw !== null && is_numeric($durationRaw)) {
                $durationSeconds = (int) $durationRaw;
            }

            $recordingRef = $this->firstNonEmpty([
                $byName['recording_reference'] ?? null,
                $byName['recording_path'] ?? null,
                $byName['recording'] ?? null,
                $byName['recording_id'] ?? null,
                $row[8] ?? null,
            ]);

            $availableRaw = $this->firstNonEmpty([
                $byName['recording_available'] ?? null,
                $byName['has_recording'] ?? null,
                $byName['hasrecording'] ?? null,
                $row[9] ?? null,
            ]);
            $hasRecording = $this->truthy($availableRaw) || ($recordingRef !== null && $recordingRef !== '');

            return [
                'call_uid' => (string) $callUid,
                'started_at' => $startedAt,
                'direction' => $direction !== null ? (string) $direction : null,
                'status' => $status !== null ? (string) $status : null,
                'from_number' => $fromNumber !== null ? (string) $fromNumber : null,
                'to_number' => $toNumber !== null ? (string) $toNumber : null,
                'ended_at' => $endedAt,
                'duration_seconds' => $durationSeconds,
                'has_recording' => $hasRecording,
                'recording_reference' => ($recordingRef !== null && (string) $recordingRef !== '') ? (string) $recordingRef : null,
            ];
        }

        // JSON-style row: associative array
        if (is_array($row)) {
            $lower = [];
            foreach ($row as $k => $v) {
                $lower[strtolower((string) $k)] = $v;
            }

            $callUid = $this->firstNonEmpty([
                $lower['call_uid'] ?? null,
                $lower['uniqueid'] ?? null,
                $lower['unique_id'] ?? null,
                $lower['callid'] ?? null,
                $lower['call_id'] ?? null,
            ]);

            if ($callUid === null) {
                Log::warning('Skipping PBX call: no unique identifier present', [
                    'company_id' => $this->companyId,
                    'company_pbx_account_id' => $this->companyPbxAccountId,
                ]);
                return ['call_uid' => null];
            }

            $startedAtRaw = $this->firstNonEmpty([
                $lower['started_at'] ?? null,
                $lower['start'] ?? null,
                $lower['timestamp'] ?? null,
                $lower['date'] ?? null,
                $lower['calldate'] ?? null,
            ]);
            $startedAt = $startedAtRaw !== null ? $this->parseDate($startedAtRaw) : null;

            $direction = $this->firstNonEmpty([
                $lower['direction'] ?? null,
                $lower['call_direction'] ?? null,
                $lower['calltype'] ?? null,
                $lower['call_type'] ?? null,
            ]);

            $status = $this->firstNonEmpty([
                $lower['status'] ?? null,
                $lower['disposition'] ?? null,
                $lower['call_status'] ?? null,
            ]);

            $fromNumber = $this->firstNonEmpty([
                $lower['from_number'] ?? null,
                $lower['src'] ?? null,
                $lower['source'] ?? null,
                $lower['callerid'] ?? null,
                $lower['caller_id'] ?? null,
            ]);

            $toNumber = $this->firstNonEmpty([
                $lower['to_number'] ?? null,
                $lower['dst'] ?? null,
                $lower['destination'] ?? null,
                $lower['callee'] ?? null,
            ]);

            $endedAtRaw = $this->firstNonEmpty([
                $lower['ended_at'] ?? null,
                $lower['end'] ?? null,
            ]);
            $endedAt = $endedAtRaw !== null ? $this->parseDate($endedAtRaw) : null;

            $durationRaw = $this->firstNonEmpty([
                $lower['duration_seconds'] ?? null,
                $lower['duration'] ?? null,
                $lower['billsec'] ?? null,
                $lower['bill_sec'] ?? null,
            ]);
            $durationSeconds = null;
            if ($durationRaw !== null && is_numeric($durationRaw)) {
                $durationSeconds = (int) $durationRaw;
            }

            $recordingRef = $this->firstNonEmpty([
                $lower['recording_reference'] ?? null,
                $lower['recording_path'] ?? null,
                $lower['recording'] ?? null,
                $lower['recording_id'] ?? null,
                $lower['file'] ?? null,
            ]);

            $availableRaw = $this->firstNonEmpty([
                $lower['recording_available'] ?? null,
                $lower['has_recording'] ?? null,
            ]);
            $hasRecording = $this->truthy($availableRaw) || ($recordingRef !== null && (string) $recordingRef !== '');

            return [
                'call_uid' => (string) $callUid,
                'started_at' => $startedAt,
                'direction' => $direction !== null ? (string) $direction : null,
                'status' => $status !== null ? (string) $status : null,
                'from_number' => $fromNumber !== null ? (string) $fromNumber : null,
                'to_number' => $toNumber !== null ? (string) $toNumber : null,
                'ended_at' => $endedAt,
                'duration_seconds' => $durationSeconds,
                'has_recording' => $hasRecording,
                'recording_reference' => ($recordingRef !== null && (string) $recordingRef !== '') ? (string) $recordingRef : null,
            ];
        }

        return ['call_uid' => null];
    }

    private function firstNonEmpty(array $values): ?string
    {
        foreach ($values as $v) {
            if ($v === null) {
                continue;
            }
            $s = trim((string) $v);
            if ($s !== '') {
                return $s;
            }
        }
        return null;
    }

    private function truthy($value): bool
    {
        if ($value === null) {
            return false;
        }
        if (is_bool($value)) {
            return $value;
        }
        $s = strtolower(trim((string) $value));
        return in_array($s, ['1', 'true', 'yes', 'y', 'on'], true);
    }

    private function downloadRecordingToS3($client, int $callId, string $callUid, string $recordingReference): void
    {
        $s3Key = sprintf('recordings/incoming/%s/%s.wav', $this->companyId, $callUid);

        Log::info('Recording download started', [
            'company_id' => $this->companyId,
            'call_id' => $callId,
            'call_uid' => $callUid,
            's3_key' => $s3Key,
        ]);

        $s3Region = Config::get('filesystems.disks.s3.region') ?: env('AWS_DEFAULT_REGION');
        $s3Bucket = Config::get('filesystems.disks.s3.bucket') ?: env('AWS_BUCKET');

        $s3 = new S3Client([
            'version' => 'latest',
            'region' => $s3Region,
        ]);

        try {
            // Download stream directly from PBXware and upload without writing to disk.
            $stream = method_exists($client, 'fetchActionStream')
                ? $client->fetchActionStream('pbxware.cdr.download', ['recording' => $recordingReference])
                : (method_exists($client, 'downloadRecordingStreamByRecordingPath')
                    ? $client->downloadRecordingStreamByRecordingPath($recordingReference)
                    : $client->downloadRecordingStream($recordingReference));

            $uploadStart = microtime(true);
            $result = $s3->putObject([
                'Bucket' => $s3Bucket,
                'Key' => $s3Key,
                'Body' => $stream,
                'ContentType' => 'audio/wav',
            ]);

            if (is_resource($stream)) {
                fclose($stream);
            }
            $uploadMs = round((microtime(true) - $uploadStart) * 1000, 2);

            Log::info('Recording download finished', [
                'company_id' => $this->companyId,
                'call_id' => $callId,
                'call_uid' => $callUid,
                's3_key' => $s3Key,
                'latency_ms' => $uploadMs,
            ]);
            Log::info('🚀 Lambda will trigger (S3 event)', ['s3_key' => $s3Key, 'bucket' => $s3Bucket]);

            // Determine pbx_provider_id from the call's PBX account if available
            $pbxProviderId = null;
            try {
                $acct = \App\Models\CompanyPbxAccount::find($this->companyPbxAccountId);
                if ($acct) {
                    $pbxProviderId = $acct->pbx_provider_id ?? null;
                }
            } catch (\Throwable $ignore) {
                // best-effort only
            }

            CallRecording::create([
                'company_id' => $this->companyId,
                'call_id' => $callId,
                'pbx_provider_id' => $pbxProviderId,
                'recording_url' => $result['ObjectURL'] ?? null,
                'storage_provider' => 's3',
                'storage_path' => $s3Key,
                'status' => CallRecording::STATUS_UPLOADED,
                'idempotency_key' => $recordingReference,
                'file_size' => $result['ContentLength'] ?? null,
            ]);
        } catch (PbxwareClientException $e) {
            Log::error('PBX client failed to download recording', [
                'company_id' => $this->companyId,
                'call_id' => $callId,
                'call_uid' => $callUid,
                'error' => $e->getMessage(),
            ]);

            CallRecording::updateOrCreate([
                'call_id' => $callId,
                'idempotency_key' => $recordingReference,
            ], [
                'company_id' => $this->companyId,
                'storage_provider' => 's3',
                'storage_path' => $s3Key,
                'status' => CallRecording::STATUS_FAILED,
                'error_message' => $e->getMessage(),
            ]);
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Unexpected error ingesting recording', [
                'company_id' => $this->companyId,
                'call_id' => $callId,
                'call_uid' => $callUid,
                'error' => $e->getMessage(),
            ]);

            CallRecording::updateOrCreate([
                'call_id' => $callId,
                'idempotency_key' => $recordingReference,
            ], [
                'company_id' => $this->companyId,
                'storage_provider' => 's3',
                'storage_path' => $s3Key,
                'status' => CallRecording::STATUS_FAILED,
                'error_message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
