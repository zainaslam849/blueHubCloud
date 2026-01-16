<?php

namespace App\Jobs;

use App\Models\Call;
use App\Models\CompanyPbxAccount;
use App\Models\CallTranscription;
use App\Services\Pbx\PbxClientResolver;
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
            // - No recording downloads

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
                // - csv[2] = Date/Time (epoch seconds)
                // - csv[6] = Status
                // - csv[7] = Unique ID (call_uid)
                // - csv[9] = Recording Available
                $epoch = $row[2] ?? null;
                $status = $row[6] ?? null;
                $callUid = $row[7] ?? null;
                $recordingAvailableRaw = $row[9] ?? null;

                $callUid = is_string($callUid) ? trim($callUid) : trim((string) $callUid);
                $status = is_string($status) ? trim($status) : trim((string) $status);

                $hasEpoch = $epoch !== null && $epoch !== '' && is_numeric($epoch);
                if ($callUid === '' || $status === '' || ! $hasEpoch) {
                    continue;
                }

                $startedAt = \Carbon\Carbon::createFromTimestamp((int) $epoch)->toDateTimeString();
                $recordingAvailable = $this->truthy($recordingAvailableRaw);

                $call = Call::firstOrCreate(
                    ['call_uid' => $callUid],
                    array_filter([
                        'company_id' => $this->companyId,
                        'company_pbx_account_id' => $this->companyPbxAccountId,
                        // Direction is not provided/required by the Bluehub PBXware CDR contract.
                        // Use a stable placeholder to satisfy schema constraints.
                        'direction' => 'unknown',
                        'started_at' => $startedAt,
                        'status' => $status,
                        'recording_available' => $recordingAvailable,
                    ], static function ($v) {
                        return $v !== null && $v !== '';
                    })
                );

                if ($call->wasRecentlyCreated) {
                    $callsCreated++;
                } else {
                    $callsSkipped++;
                }

                // If we already have a PBXware transcription for this call, skip.
                $hasTranscription = CallTranscription::query()
                    ->where('call_id', $call->id)
                    ->where('provider_name', 'pbxware')
                    ->exists();
                if ($hasTranscription) {
                    continue;
                }

                $transcriptionResult = method_exists($client, 'fetchTranscription')
                    ? $client->fetchTranscription(['server' => $serverId, 'uniqueid' => $callUid])
                    : (method_exists($client, 'fetchAction') ? $client->fetchAction('pbxware.transcription.get', ['server' => $serverId, 'uniqueid' => $callUid]) : []);

                    $transcriptText = null;
                    $language = 'en';
                    $confidence = null;
                    $durationSeconds = $call->duration_seconds ?? 0;

                    if (is_string($transcriptionResult)) {
                        $transcriptText = trim($transcriptionResult);
                    } elseif (is_array($transcriptionResult)) {
                        $lower = [];
                        foreach ($transcriptionResult as $k => $v) {
                            $lower[strtolower((string) $k)] = $v;
                        }

                        if (isset($lower['language']) && is_string($lower['language']) && trim($lower['language']) !== '') {
                            $language = trim($lower['language']);
                        }
                        if (isset($lower['confidence']) && is_numeric($lower['confidence'])) {
                            $confidence = (float) $lower['confidence'];
                        }
                        if (isset($lower['confidence_score']) && is_numeric($lower['confidence_score'])) {
                            $confidence = (float) $lower['confidence_score'];
                        }
                        if (isset($lower['duration_seconds']) && is_numeric($lower['duration_seconds'])) {
                            $durationSeconds = (int) $lower['duration_seconds'];
                        }

                        $transcriptText = $this->firstNonEmpty([
                            $lower['transcript_text'] ?? null,
                            $lower['transcription'] ?? null,
                            $lower['transcript'] ?? null,
                            $lower['text'] ?? null,
                        ]);
                        $transcriptText = is_string($transcriptText) ? trim($transcriptText) : null;
                    }

                    if (is_string($transcriptText) && $transcriptText !== '') {
                        $created = CallTranscription::firstOrCreate(
                            [
                                'call_id' => $call->id,
                                'provider_name' => 'pbxware',
                                'language' => $language,
                            ],
                            [
                                'transcript_text' => $transcriptText,
                                'duration_seconds' => (int) $durationSeconds,
                                'confidence_score' => $confidence,
                            ]
                        );

                        if ($created->wasRecentlyCreated) {
                            $transcriptionsStored++;
                        }
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
