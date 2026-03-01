<?php

namespace App\Jobs;

use App\Models\Call;
use App\Models\CompanyPbxAccount;
use App\Services\Pbx\PbxClientResolver;
use App\Jobs\SummarizeSingleCallJob;
use Illuminate\Support\Facades\Config;
use App\Exceptions\PbxwareClientException;
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

        $company = $pbxAccount->company;
        if (! $company) {
            Log::warning('Company not found for PBX account', ['company_pbx_account_id' => $this->companyPbxAccountId]);
            return;
        }

        if ($company->status !== 'active' || $pbxAccount->status !== 'active') {
            Log::info('Skipping PBX ingest for inactive company/account', [
                'company_id' => $company->id,
                'company_status' => $company->status,
                'company_pbx_account_id' => $this->companyPbxAccountId,
                'pbx_account_status' => $pbxAccount->status,
            ]);
            return;
        }

        $serverId = is_string($pbxAccount->server_id ?? null) ? trim((string) $pbxAccount->server_id) : '';
        if ($serverId === '') {
            throw new PbxwareClientException('PBX server_id must be configured for this account');
        }

        $client = PbxClientResolver::resolve();

        try {
            Log::info('Starting PBX calls ingestion', ['company_id' => $this->companyId, 'company_pbx_account_id' => $this->companyPbxAccountId]);

            // Log mock mode active for developer clarity
            if (Config::get('pbx.mode') === 'mock') {
                Log::info('🟢 Mock PBX mode active — using mock PBXware client', ['company_id' => $this->companyId]);
            }

            // Authoritative PBXware flow:
            // - pbxware.cdr.download returns CDR records for a server/date range
            // - For each NEW call, pull transcription via pbxware.transcription.get
            // PBXware does not expose call media downloads. This system relies on PBX-provided transcriptions only.

            $range = $this->determineRequestedRange();

            $callsCreated = 0;
            $callsSkipped = 0;
            $transcriptionsStored = 0;
            $cdrRowsReturned = 0;

            $params = $this->buildCdrDownloadParams($range['from'], $range['to'], $serverId);

            Log::info('PBXware CDR date range used', [
                'company_id' => $this->companyId,
                'company_pbx_account_id' => $this->companyPbxAccountId,
                'server_id' => $serverId,
                'start' => $params['start'],
                'end' => $params['end'],
                'status' => $params['status'],
            ]);

            $result = method_exists($client, 'fetchCdrRecords')
                ? $client->fetchCdrRecords($params)
                : (method_exists($client, 'fetchAction') ? $client->fetchAction('pbxware.cdr.download', $params) : []);

            $headers = (is_array($result) && isset($result['header']) && is_array($result['header']))
                ? $result['header']
                : [];

            $csvRows = $this->extractCsvRows($result);
            $rowCount = count($csvRows);
            $cdrRowsReturned += $rowCount;

            Log::info('PBXware CDR rows received', [
                'company_id' => $this->companyId,
                'company_pbx_account_id' => $this->companyPbxAccountId,
                'server_id' => $serverId,
                'rows' => $rowCount,
            ]);

            foreach ($csvRows as $row) {
                if (! is_array($row)) {
                    continue;
                }

                // Bluehub PBXWare API contract (fixed column indexes):
                // - csv[0] = From
                // - csv[1] = To
                // - csv[2] = Date/Time (epoch seconds)
                // - csv[3] = Total Duration
                // - csv[6] = Status
                // - csv[7] = Unique ID
                // - csv[10] = Location Type
                $fromValue = array_key_exists(0, $row) ? trim((string) ($row[0] ?? '')) : null;
                $toValue = array_key_exists(1, $row) ? trim((string) ($row[1] ?? '')) : null;
                $epoch = $row[2] ?? null;
                $status = $row[6] ?? null;
                $callUid = $row[7] ?? null;
                $locationType = array_key_exists(10, $row) ? trim((string) ($row[10] ?? '')) : null;

                if ($fromValue === '') {
                    $fromValue = null;
                }
                if ($toValue === '') {
                    $toValue = null;
                }
                if ($locationType === '') {
                    $locationType = null;
                }

                $durationSeconds = null;
                if (array_key_exists(3, $row) && is_numeric($row[3])) {
                    $durationSeconds = (int) $row[3];
                }

                $callUid = is_string($callUid) ? trim($callUid) : trim((string) $callUid);
                $status = is_string($status) ? trim($status) : trim((string) $status);

                $hasEpoch = $epoch !== null && $epoch !== '' && is_numeric($epoch);
                if ($callUid === '' || $status === '' || ! $hasEpoch) {
                    continue;
                }

                $startedAt = \Carbon\Carbon::createFromTimestamp((int) $epoch)->toDateTimeString();

                // Map PBX CDR disposition/status to final internal status
                $rawDisposition = is_string($status) ? strtoupper(trim($status)) : strtoupper((string) $status);
                if ($rawDisposition === 'ANSWERED') {
                    $finalStatus = 'answered';
                } elseif (in_array($rawDisposition, ['NO ANSWER', 'NO_ANSWER', 'BUSY', 'FAILED'], true)) {
                    $finalStatus = 'missed';
                } else {
                    $finalStatus = 'unknown';
                }

                // Ensure duration is an integer billsec
                $billsec = is_numeric($durationSeconds) ? (int) $durationSeconds : 0;

                // Best-effort caller extension extraction from CDR From field.
                // Keep this conservative to avoid false positives from full phone numbers.
                $callerExtension = null;
                if (is_string($fromValue) && preg_match('/^\d{2,6}$/', $fromValue)) {
                    $callerExtension = $fromValue;
                }

                $pbxMetadata = null;
                if (! empty($headers) && count($headers) === count($row)) {
                    try {
                        $pbxMetadata = array_combine($headers, $row) ?: null;
                    } catch (\Throwable $e) {
                        $pbxMetadata = null;
                    }
                }

                // Include raw row for diagnostics and future column mapping enhancements.
                if (is_array($pbxMetadata)) {
                    $pbxMetadata['raw_row'] = $row;
                } else {
                    $pbxMetadata = ['raw_row' => $row];
                }

                // Compute ended_at when possible (started epoch + billsec)
                $endedAt = null;
                if ($billsec > 0 && is_numeric($epoch)) {
                    $endedAt = \Carbon\Carbon::createFromTimestamp((int) $epoch + $billsec)->toDateTimeString();
                }

                $call = Call::updateOrCreate(
                    [
                        'company_pbx_account_id' => $this->companyPbxAccountId,
                        'server_id' => $serverId,
                        'pbx_unique_id' => $callUid,
                    ],
                    array_filter([
                        'company_id' => $this->companyId,
                        // Direction is not provided by this CDR payload, keep stable placeholder.
                        'direction' => 'unknown',
                        'from' => $fromValue,
                        'to' => $toValue,
                        'caller_extension' => $callerExtension,
                        'department' => $locationType,
                        'pbx_metadata' => $pbxMetadata,
                        'started_at' => $startedAt,
                        // Final mapped status (do not set PROCESSING for CDR-based calls)
                        'status' => $finalStatus,
                        // Final duration from CDR billsec
                        'duration_seconds' => $billsec,
                    ], static function ($v) {
                        return $v !== null && $v !== '';
                    })
                );

                // Persist ended_at if available (not mass assignable in model fillable)
                if ($endedAt !== null) {
                    try {
                        $call->ended_at = $endedAt;
                        $call->save();
                    } catch (\Throwable $e) {
                        // non-fatal
                    }
                }

                if ($call->wasRecentlyCreated) {
                    $callsCreated++;
                } else {
                    $callsSkipped++;
                }

                // If we already have transcription stored on the call, skip.
                if ((bool) ($call->has_transcription ?? false) && is_string($call->transcript_text ?? null) && trim((string) $call->transcript_text) !== '') {
                    continue;
                }

                // Important: "No transcription found" is not an error. Do not throw/retry.
                try {
                    $transcriptionResult = method_exists($client, 'fetchTranscription')
                        ? $client->fetchTranscription(['server' => $serverId, 'uniqueid' => $callUid])
                        : (method_exists($client, 'fetchAction') ? $client->fetchAction('pbxware.transcription.get', ['server' => $serverId, 'uniqueid' => $callUid]) : []);
                } catch (PbxwareClientException $e) {
                    Log::info('PBXware transcription not available (non-fatal)', [
                        'company_id' => $this->companyId,
                        'company_pbx_account_id' => $this->companyPbxAccountId,
                        'server_id' => $serverId,
                        'pbx_unique_id' => $callUid,
                        'message' => $e->getMessage(),
                    ]);
                    continue;
                }

                $transcriptText = null;

                if (is_string($transcriptionResult)) {
                    $transcriptText = trim($transcriptionResult);
                } elseif (is_array($transcriptionResult)) {
                    $lower = [];
                    foreach ($transcriptionResult as $k => $v) {
                        $lower[strtolower((string) $k)] = $v;
                    }

                    if (
                        (! is_numeric($call->duration_seconds ?? null) || (int) $call->duration_seconds === 0)
                        && isset($lower['duration_seconds'])
                        && is_numeric($lower['duration_seconds'])
                    ) {
                        $call->duration_seconds = (int) $lower['duration_seconds'];
                    }

                    $transcriptText = $this->firstNonEmpty([
                        $lower['transcript_text'] ?? null,
                        $lower['transcription'] ?? null,
                        $lower['transcript'] ?? null,
                        $lower['text'] ?? null,
                    ]);
                    $transcriptText = is_string($transcriptText) ? trim($transcriptText) : null;
                }

                    if (is_string($transcriptText)) {
                        $normalized = trim($transcriptText);
                        if ($normalized !== '' && stripos($normalized, 'no transcription') === false) {
                            $call->has_transcription = true;
                            $call->transcript_text = $normalized;
                            $call->transcription_checked_at = now();
                            $call->save();
                            $transcriptionsStored++;

                            if (empty($call->ai_summary)) {
                                SummarizeSingleCallJob::dispatch($call->id)
                                    ->onQueue('summarization');
                            }
                        } else {
                            // Explicitly mark as no transcription when the API returns a sentinel string.
                            $call->has_transcription = false;
                            $call->transcription_checked_at = now();
                            $call->save();
                        }
                    } else {
                        // No transcription provided (non-fatal).
                        $call->has_transcription = false;
                        $call->transcription_checked_at = now();
                        $call->save();
                    }
            }

            Log::info('PBXware ingestion summary', [
                'company_id' => $this->companyId,
                'company_pbx_account_id' => $this->companyPbxAccountId,
                'server_id' => $serverId,
                'cdr_rows_returned' => $cdrRowsReturned,
                'calls_created' => $callsCreated,
                'calls_skipped_existing' => $callsSkipped,
                'transcriptions_stored' => $transcriptionsStored,
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
        // Official contract for this project: ONLY pass server/start/end/status.
        $out = [];
        foreach ($params as $key => $value) {
            if (! in_array($key, ['start', 'end', 'status', 'server'], true)) {
                continue;
            }

            $out[$key] = $value;
        }

        return $out;
    }

    private function buildCdrDownloadParams(\Carbon\Carbon $from, \Carbon\Carbon $to, string $serverId): array
    {
        $params = [
            'server' => $serverId,
            'start' => $from->format('M-d-Y'),
            'end' => $to->format('M-d-Y'),
            'status' => 8,
        ];

        return $this->normalizePbxwareQueryParams($params);
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
     * Bluehub PBXware contract: the only supported row source is response['csv'].
     * @return array<int, array<int, mixed>>
     */
    private function extractCsvRows(array|string $result): array
    {
        if (! is_array($result)) {
            return [];
        }

        $csv = $result['csv'] ?? null;
        if (! is_array($csv)) {
            return [];
        }

        return $csv;
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
}
