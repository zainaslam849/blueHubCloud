<?php

namespace App\Jobs;

use App\Models\Call;
use App\Models\CompanyPbxAccount;
use App\Models\CallTranscription;
use App\Services\PbxwareClient;
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
            Log::error('PBX calls ingestion aborted: company_pbx_accounts.server_id is not set', [
                'company_id' => $this->companyId,
                'company_pbx_account_id' => $this->companyPbxAccountId,
            ]);
            return;
        }

        $client = PbxClientResolver::resolve();

        try {
            Log::info('Starting PBX calls ingestion', ['company_id' => $this->companyId, 'company_pbx_account_id' => $this->companyPbxAccountId]);

            // Log mock mode active for developer clarity
            if (Config::get('pbx.mode') === 'mock') {
                Log::info('🟢 Mock PBX mode active — using local test audio and mock client', ['company_id' => $this->companyId]);
            }

            // Authoritative PBXware flow:
            // - pbxware.cdr.download returns CDR records for a server/date range
            // - For each NEW call, pull transcription via pbxware.transcription.get
            // - No recording downloads

            $range = $this->determineRequestedRange();
            $chunks = $this->monthChunks($range['from'], $range['to']);

            $callsCreated = 0;
            $callsSkipped = 0;
            $transcriptionsStored = 0;

            foreach ($chunks as $chunk) {
                $params = $this->buildCdrDownloadParams($chunk['from'], $chunk['to'], $serverId);

                Log::info('PBXware CDR date range used', [
                    'company_id' => $this->companyId,
                    'company_pbx_account_id' => $this->companyPbxAccountId,
                    'server_id' => $serverId,
                    'start' => $params['start'],
                    'end' => $params['end'],
                    'status' => $params['status'] ?? null,
                    'limit' => $params['limit'],
                ]);

                $result = method_exists($client, 'fetchCdrRecords')
                    ? $client->fetchCdrRecords($params)
                    : (method_exists($client, 'fetchAction') ? $client->fetchAction('pbxware.cdr.download', $params) : []);

                [, $rows] = $this->extractRows($result);

                Log::info('PBXware CDR rows received', [
                    'company_id' => $this->companyId,
                    'company_pbx_account_id' => $this->companyPbxAccountId,
                    'server_id' => $serverId,
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

                    $normalized = $this->normalizeCdrRow($row, null);
                    $callUid = $normalized['call_uid'] ?? null;
                    if (! is_string($callUid) || trim($callUid) === '') {
                        continue;
                    }

                    // The calls table requires direction/status/started_at.
                    // Do not guess; if the CSV doesn't provide these fields, skip the row.
                    $direction = $normalized['direction'] ?? null;
                    $status = $normalized['status'] ?? (isset($params['status']) ? (string) $params['status'] : null);
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
                        CallTranscription::updateOrCreate(
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
                        $transcriptionsStored++;
                    }
                }
            }

            Log::info('PBXware ingestion summary', [
                'company_id' => $this->companyId,
                'company_pbx_account_id' => $this->companyPbxAccountId,
                'server_id' => $serverId,
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
        // PBXware expects query params like start/end/status/server/limit.
        // Do NOT guess or pass unknown keys.
        $out = [];
        foreach ($params as $key => $value) {
            if (! in_array($key, ['start', 'end', 'starttime', 'endtime', 'status', 'server', 'limit', 'export'], true)) {
                continue;
            }

            $out[$key] = $value;
        }

        return $out;
    }

    private function buildCdrDownloadParams(\Carbon\Carbon $from, \Carbon\Carbon $to, string $serverId): array
    {
        $limit = (int) ($this->params['limit'] ?? 1000);
        if ($limit <= 0) {
            $limit = 1000;
        }
        $limit = min($limit, 1000);

        $status = $this->params['status'] ?? 8;

        $params = [
            'server' => $serverId,
            'start' => $from->format('M-d-Y'),
            'end' => $to->format('M-d-Y'),
            'status' => $status,
            'limit' => $limit,
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

            return [
                'call_uid' => (string) $callUid,
                'started_at' => $startedAt,
                'direction' => $direction !== null ? (string) $direction : null,
                'status' => $status !== null ? (string) $status : null,
                'from_number' => $fromNumber !== null ? (string) $fromNumber : null,
                'to_number' => $toNumber !== null ? (string) $toNumber : null,
                'ended_at' => $endedAt,
                'duration_seconds' => $durationSeconds,
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

            return [
                'call_uid' => (string) $callUid,
                'started_at' => $startedAt,
                'direction' => $direction !== null ? (string) $direction : null,
                'status' => $status !== null ? (string) $status : null,
                'from_number' => $fromNumber !== null ? (string) $fromNumber : null,
                'to_number' => $toNumber !== null ? (string) $toNumber : null,
                'ended_at' => $endedAt,
                'duration_seconds' => $durationSeconds,
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
}
