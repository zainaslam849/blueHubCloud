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
    protected ?string $cdrActionName;

    public function __construct(int $companyId, int $companyPbxAccountId, array $params = [], ?string $cdrActionName = null)
    {
        $this->companyId = $companyId;
        $this->companyPbxAccountId = $companyPbxAccountId;
        $this->params = $params;
        $this->cdrActionName = $cdrActionName;
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

            // PBXware expects query params like startdate/enddate/limit.
            // Never send ISO-8601 `since`. For first-time ingestion (no cursor),
            // do NOT send since at all. Default to last 24 hours.
            $defaultParams = [
                'startdate' => time() - 86400,
                'enddate' => time(),
            ];

            $pbxParams = $this->normalizePbxwareQueryParams(array_merge($defaultParams, $this->params));
            
            Log::info('PBXware CDR query params (excluding apikey)', [
                'company_id' => $this->companyId,
                'company_pbx_account_id' => $this->companyPbxAccountId,
                'params' => $pbxParams,
            ]);

            $actionName = $this->cdrActionName ?: $this->discoverCdrActionName($client);
            if (! $actionName) {
                Log::warning('PBXware ingest aborted: no usable CDR action discovered', [
                    'company_id' => $this->companyId,
                    'company_pbx_account_id' => $this->companyPbxAccountId,
                ]);
                return;
            }

            Log::info('PBXware selected CDR action', [
                'company_id' => $this->companyId,
                'company_pbx_account_id' => $this->companyPbxAccountId,
                'action' => $actionName,
            ]);

            $result = $client->fetchAction($actionName, $pbxParams);
            [$header, $rows] = $this->extractRows($result);

            Log::info('PBXware CDR rows fetched', [
                'company_id' => $this->companyId,
                'company_pbx_account_id' => $this->companyPbxAccountId,
                'action' => $actionName,
                'total_rows' => count($rows),
            ]);

            $processed = 0;
            foreach ($rows as $row) {
                $mapped = $this->normalizeCdrRow($row, $header);
                $callUid = $mapped['call_uid'] ?? null;
                if (empty($callUid)) {
                    continue;
                }

                $attributes = [
                    'company_id' => $this->companyId,
                    'company_pbx_account_id' => $this->companyPbxAccountId,
                    'started_at' => $mapped['started_at'] ?? null,
                ];

                $call = Call::firstOrCreate(
                    ['call_uid' => $callUid],
                    array_filter($attributes, function ($v) { return $v !== null; })
                );

                if (! $call->wasRecentlyCreated) {
                    Log::info('Skipping duplicate call_uid', [
                        'call_uid' => $callUid,
                        'company_id' => $this->companyId,
                        'company_pbx_account_id' => $this->companyPbxAccountId,
                    ]);
                    // Idempotency rule: skip duplicates.
                    continue;
                }

                Log::info('📞 Call ingested', [
                    'company_id' => $this->companyId,
                    'company_pbx_account_id' => $this->companyPbxAccountId,
                    'call_id' => $call->id,
                    'call_uid' => $callUid,
                ]);

                $processed++;

                // Idempotency rule: skip recording download if call_recordings exists.
                $recordingReference = $mapped['recording_reference'] ?? null;
                if (is_string($recordingReference) && $recordingReference !== '' && ($mapped['has_recording'] ?? false)) {
                    $alreadyHasRecording = CallRecording::where('call_id', $call->id)->exists();
                    if ($alreadyHasRecording) {
                        Log::info('Recording already exists for call, skipping download', [
                            'call_id' => $call->id,
                            'call_uid' => $callUid,
                        ]);
                        continue;
                    }

                    $this->downloadRecordingToS3($client, $call->id, $callUid, $recordingReference);
                }
            }

            Log::info('Finished PBX calls ingestion', ['company_id' => $this->companyId, 'processed' => $processed]);

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

    private function discoverCdrActionName($client): ?string
    {
        if (! method_exists($client, 'testAvailableActions')) {
            // Backward-compat safety: fall back to the historical action.
            return 'pbxware.cdr.list';
        }

        $diag = $client->testAvailableActions();
        $results = is_array($diag) ? ($diag['results'] ?? []) : [];

        foreach ($results as $action => $info) {
            if (($info['status'] ?? null) === 'success' && (int) ($info['rows'] ?? 0) > 0) {
                return (string) $action;
            }
        }

        // If nothing returns rows for limit=1, prefer first "empty" response over none.
        foreach ($results as $action => $info) {
            if (($info['status'] ?? null) === 'empty') {
                return (string) $action;
            }
        }

        return null;
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
                $row[2] ?? null,
            ]);
            $startedAt = $startedAtRaw !== null ? $this->parseDate($startedAtRaw) : null;

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
            ]);
            $startedAt = $startedAtRaw !== null ? $this->parseDate($startedAtRaw) : null;

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
                ? $client->fetchActionStream('pbxware.cdr.download', ['recording_id' => $recordingReference])
                : $client->downloadRecordingStream($recordingReference);

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
