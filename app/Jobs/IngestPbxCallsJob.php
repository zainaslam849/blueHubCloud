<?php

namespace App\Jobs;

use App\Models\Call;
use App\Models\CompanyPbxAccount;
use App\Models\PbxRawPayload;
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

    public function handle(): array
    {
        $pbxAccount = CompanyPbxAccount::find($this->companyPbxAccountId);
        if (! $pbxAccount) {
            Log::warning('Company PBX account not found', ['company_pbx_account_id' => $this->companyPbxAccountId]);
            return [];
        }

        $company = $pbxAccount->company;
        if (! $company) {
            Log::warning('Company not found for PBX account', ['company_pbx_account_id' => $this->companyPbxAccountId]);
            return [];
        }

        if ($company->status !== 'active' || $pbxAccount->status !== 'active') {
            Log::info('Skipping PBX ingest for inactive company/account', [
                'company_id' => $company->id,
                'company_status' => $company->status,
                'company_pbx_account_id' => $this->companyPbxAccountId,
                'pbx_account_status' => $pbxAccount->status,
            ]);
            return [];
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
            $cdrRowsSkippedInvalid = 0;
            $cdrRowsMissingEndpoints = 0;
            $answeredCalls = 0;
            $transcriptionAttempts = 0;
            $transcriptionSkippedNoRecording = 0;
            $transcriptionNotFound = 0;
            $splitWindowRetries = 0;
            $paginationUnresolvedSingleDay = 0;

            $windows = $this->buildCdrDateWindows($range['from'], $range['to']);
            $pendingWindows = $windows;
            $windowSequence = 0;

            while (! empty($pendingWindows)) {
                $window = array_shift($pendingWindows);
                $windowIndex = $windowSequence++;

                $params = $this->buildCdrDownloadParams($window['from'], $window['to'], $serverId);

                Log::info('PBXware CDR date range used', [
                    'company_id' => $this->companyId,
                    'company_pbx_account_id' => $this->companyPbxAccountId,
                    'server_id' => $serverId,
                    'start' => $params['start'],
                    'end' => $params['end'],
                    'status' => $params['status'],
                    'window_index' => $windowIndex,
                    'window_count' => count($pendingWindows) + 1,
                ]);

                $result = method_exists($client, 'fetchCdrRecords')
                    ? $client->fetchCdrRecords($params)
                    : (method_exists($client, 'fetchAction') ? $client->fetchAction('pbxware.cdr.download', $params) : []);

                Log::info('PBX_TRACE cdr.endpoint.response_shape', [
                    'company_id' => $this->companyId,
                    'company_pbx_account_id' => $this->companyPbxAccountId,
                    'server_id' => $serverId,
                    'window_index' => $windowIndex,
                    'php_type' => gettype($result),
                    'is_array' => is_array($result),
                    'top_level_keys' => is_array($result) ? array_slice(array_keys($result), 0, 20) : [],
                    'has_csv' => is_array($result) && array_key_exists('csv', $result),
                    'has_header' => is_array($result) && array_key_exists('header', $result),
                    'next_page' => is_array($result) ? ($result['next_page'] ?? null) : null,
                ]);

                $this->persistRawPayload(
                    endpoint: 'cdr.download',
                    serverId: $serverId,
                    externalId: null,
                    payload: $result,
                    status: 'received'
                );

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
                    'window_index' => $windowIndex,
                    'rows' => $rowCount,
                    'header_count' => count($headers),
                    'first_header_sample' => array_slice($headers, 0, 12),
                    'first_row_sample' => $rowCount > 0 && is_array($csvRows[0]) ? array_slice($csvRows[0], 0, 12) : [],
                ]);

                if (is_array($result) && ! empty($result['next_page'])) {
                    $splitWindows = $this->splitCdrWindow($window['from'], $window['to']);

                    if (empty($splitWindows)) {
                        $paginationUnresolvedSingleDay++;

                        Log::warning('PBX_TRACE cdr.pagination.unresolved_single_day', [
                            'company_id' => $this->companyId,
                            'company_pbx_account_id' => $this->companyPbxAccountId,
                            'server_id' => $serverId,
                            'window_index' => $windowIndex,
                            'start' => $params['start'],
                            'end' => $params['end'],
                            'rows' => $rowCount,
                            'action' => 'accept_partial_window_and_continue',
                            'message' => 'PBX indicated additional pages for an unsplittable single-day window. Accepting current rows and continuing.',
                        ]);
                    } else {
                        // Reprocess smaller windows first; skip current partial window rows to avoid accepting truncated data.
                        array_unshift($pendingWindows, ...array_reverse($splitWindows));
                        $splitWindowRetries++;

                        Log::warning('PBX_TRACE cdr.window.paginated', [
                            'company_id' => $this->companyId,
                            'company_pbx_account_id' => $this->companyPbxAccountId,
                            'server_id' => $serverId,
                            'window_index' => $windowIndex,
                            'start' => $params['start'],
                            'end' => $params['end'],
                            'rows' => $rowCount,
                            'action' => 'window_split_retry',
                            'message' => 'PBX indicated additional pages. Splitting window and retrying to avoid partial ingestion.',
                        ]);

                        continue;
                    }
                }

                foreach ($csvRows as $rowIndex => $row) {
                if (! is_array($row)) {
                    $cdrRowsSkippedInvalid++;
                    continue;
                }

                $rowByHeader = [];
                if (! empty($headers) && count($headers) === count($row)) {
                    try {
                        $rowByHeader = array_combine($headers, $row) ?: [];
                    } catch (\Throwable $e) {
                        $rowByHeader = [];
                    }
                }

                $sourceRow = ! empty($rowByHeader) ? $rowByHeader : $row;

                // Bluehub PBXWare API contract supports numeric csv indexes and named keys.
                // Numeric indexes:
                // - [0] From, [1] To, [2] Date/Time epoch, [3] Duration, [6] Status, [7] Unique ID, [10] Location Type
                $fromValue = $this->extractFieldValue($sourceRow, [0, 'From', 'from', 'src', 'caller']);
                $toValue = $this->extractFieldValue($sourceRow, [1, 'To', 'to', 'dst', 'destination']);
                $epoch = $this->extractFieldValue($sourceRow, [2, 'Date/Time', 'datetime', 'timestamp', 'started_at']);
                $status = $this->extractFieldValue($sourceRow, [6, 'Status', 'status', 'disposition']);
                $callUid = $this->extractFieldValue($sourceRow, [7, 'Unique ID', 'uniqueid', 'unique_id']);
                $recordingPath = $this->extractFieldValue($sourceRow, [8, 'Recording Path', 'recording_path', 'recording', 'recording_file']);
                $recordingPathSource = ! empty($rowByHeader) ? 'header_or_named' : 'raw_index_or_named';
                if (($recordingPath === null || trim((string) $recordingPath) === '') && ! empty($rowByHeader)) {
                    $recordingPath = $this->extractFieldValue($row, [8]);
                    if ($recordingPath !== null && trim((string) $recordingPath) !== '') {
                        $recordingPathSource = 'raw_index_8_fallback';
                    }
                }

                $recordingAvailableRaw = $this->extractFieldValue($sourceRow, [9, 'Recording Available', 'recording_available', 'recording status', 'is_recorded']);
                $recordingAvailableRawSource = ! empty($rowByHeader) ? 'header_or_named' : 'raw_index_or_named';
                if (($recordingAvailableRaw === null || trim((string) $recordingAvailableRaw) === '') && ! empty($rowByHeader)) {
                    $recordingAvailableRaw = $this->extractFieldValue($row, [9]);
                    if ($recordingAvailableRaw !== null && trim((string) $recordingAvailableRaw) !== '') {
                        $recordingAvailableRawSource = 'raw_index_9_fallback';
                    }
                }

                $locationType = $this->extractFieldValue($sourceRow, [10, 'Location Type', 'location_type', 'department', 'queue']);
                $recordingAvailable = $this->toBooleanLike($recordingAvailableRaw);
                $recordingPathNormalized = is_string($recordingPath) ? trim($recordingPath) : '';
                $recordingAvailableEffective = $recordingAvailable === true || $recordingPathNormalized !== '';

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
                $durationRaw = $this->extractFieldValue($sourceRow, [3, 'Total Duration', 'Duration', 'duration', 'billsec']);
                if ($durationRaw !== null && $durationRaw !== '' && is_numeric($durationRaw)) {
                    $durationSeconds = (int) $durationRaw;
                }

                $callUid = is_string($callUid) ? trim($callUid) : trim((string) $callUid);
                $status = is_string($status) ? trim($status) : trim((string) $status);

                $hasEpoch = $epoch !== null && $epoch !== '' && is_numeric($epoch);
                if ($callUid === '' || $status === '' || ! $hasEpoch) {
                    $cdrRowsSkippedInvalid++;
                    if ($rowIndex < 5) {
                        Log::warning('PBX_TRACE cdr.row.skipped_invalid', [
                            'row_index' => $rowIndex,
                            'server_id' => $serverId,
                            'call_uid' => $callUid,
                            'status' => $status,
                            'epoch' => $epoch,
                            'raw_row_sample' => array_slice($row, 0, 12),
                        ]);
                    }
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
                $isVerificationCandidate = $finalStatus === 'answered';
                if ($isVerificationCandidate) {
                    $answeredCalls++;
                }

                // Ensure duration is an integer billsec
                $billsec = is_numeric($durationSeconds) ? (int) $durationSeconds : 0;

                // Best-effort extension extraction from CDR From/To fields.
                // Keep this conservative to avoid false positives from full phone numbers.
                $callerExtension = null;
                $answeredByExtension = null;
                if (is_string($fromValue) && preg_match('/^\d{2,6}$/', $fromValue)) {
                    $callerExtension = $fromValue;
                }
                if (is_string($toValue) && preg_match('/^\d{2,6}$/', $toValue)) {
                    $answeredByExtension = $toValue;
                }

                // Ring group fallback: use PBX location type when explicit ring group is absent.
                $ringGroup = null;
                if (is_string($locationType) && trim($locationType) !== '') {
                    $ringGroup = trim($locationType);
                }

                if ($fromValue === null && $toValue === null && $answeredByExtension === null && $ringGroup === null) {
                    $cdrRowsMissingEndpoints++;
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
                    $pbxMetadata['recording_available_raw'] = $recordingAvailableRaw;
                    $pbxMetadata['recording_available_normalized'] = $recordingAvailable;
                    $pbxMetadata['recording_available_effective'] = $recordingAvailableEffective;
                    $pbxMetadata['recording_available_source'] = $recordingAvailableRawSource;
                    $pbxMetadata['recording_path_normalized'] = $recordingPathNormalized !== '' ? $recordingPathNormalized : null;
                    $pbxMetadata['recording_path_source'] = $recordingPathSource;
                } else {
                    $pbxMetadata = [
                        'raw_row' => $row,
                        'recording_available_raw' => $recordingAvailableRaw,
                        'recording_available_normalized' => $recordingAvailable,
                        'recording_available_effective' => $recordingAvailableEffective,
                        'recording_available_source' => $recordingAvailableRawSource,
                        'recording_path_normalized' => $recordingPathNormalized !== '' ? $recordingPathNormalized : null,
                        'recording_path_source' => $recordingPathSource,
                    ];
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
                        'answered_by_extension' => $answeredByExtension,
                        'caller_extension' => $callerExtension,
                        'ring_group' => $ringGroup,
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

                if ($rowIndex < 5) {
                    Log::info('PBX_TRACE cdr.row.mapped', [
                        'row_index' => $rowIndex,
                        'server_id' => $serverId,
                        'pbx_unique_id' => $callUid,
                        'mapped' => [
                            'from' => $fromValue,
                            'to' => $toValue,
                            'answered_by_extension' => $answeredByExtension,
                            'caller_extension' => $callerExtension,
                            'ring_group' => $ringGroup,
                            'department' => $locationType,
                            'recording_available' => $recordingAvailable,
                            'recording_available_effective' => $recordingAvailableEffective,
                            'recording_path' => $recordingPathNormalized,
                            'status' => $finalStatus,
                            'duration_seconds' => $billsec,
                            'started_at' => $startedAt,
                        ],
                        'raw_row_sample' => array_slice($row, 0, 12),
                    ]);
                }

                // If we already have transcription stored on the call, skip.
                if ((bool) ($call->has_transcription ?? false) && is_string($call->transcript_text ?? null) && trim((string) $call->transcript_text) !== '') {
                    $meta = is_array($call->pbx_metadata) ? $call->pbx_metadata : [];
                    $meta['transcription_verification_status'] = 'saved';
                    $meta['transcription_last_decision'] = 'ingest_already_transcripted';
                    $meta['transcription_last_decision_at'] = now()->toIso8601String();
                    $call->pbx_metadata = $meta;
                    $call->save();
                    continue;
                }

                if ($isVerificationCandidate && $recordingAvailableEffective) {
                    $meta = is_array($call->pbx_metadata) ? $call->pbx_metadata : [];
                    $meta['transcription_verification_status'] = 'pending';
                    $meta['transcription_last_decision'] = 'ingest_marked_candidate';
                    $meta['transcription_last_decision_at'] = now()->toIso8601String();
                    $meta['recording_available_effective'] = true;

                    $call->has_transcription = true;
                    if (empty($call->transcript_text)) {
                        $call->transcription_checked_at = null;
                    }
                    $call->pbx_metadata = $meta;
                    $call->save();
                } elseif ($isVerificationCandidate && ! $recordingAvailableEffective) {
                    $meta = is_array($call->pbx_metadata) ? $call->pbx_metadata : [];
                    $meta['transcription_verification_status'] = 'skipped_no_recording';
                    $meta['transcription_last_decision'] = 'ingest_skipped_no_recording';
                    $meta['transcription_last_decision_at'] = now()->toIso8601String();
                    $meta['recording_available_effective'] = false;
                    $call->has_transcription = false;
                    $call->transcription_checked_at = now();
                    $call->pbx_metadata = $meta;
                    $call->save();
                    $transcriptionSkippedNoRecording++;

                    if ($rowIndex < 5) {
                        Log::info('PBX_TRACE transcription.recording_hint_missing', [
                            'company_id' => $this->companyId,
                            'company_pbx_account_id' => $this->companyPbxAccountId,
                            'server_id' => $serverId,
                            'pbx_unique_id' => $callUid,
                            'is_verification_candidate' => $isVerificationCandidate,
                            'recording_available_raw' => $recordingAvailableRaw,
                            'recording_available_source' => $recordingAvailableRawSource,
                            'recording_available_normalized' => $recordingAvailable,
                            'recording_available_effective' => $recordingAvailableEffective,
                            'recording_path' => $recordingPathNormalized,
                            'recording_path_source' => $recordingPathSource,
                        ]);
                    }

                    continue;
                } elseif (empty($call->transcript_text)) {
                    $meta = is_array($call->pbx_metadata) ? $call->pbx_metadata : [];
                    $meta['transcription_verification_status'] = 'skipped_not_answered';
                    $meta['transcription_last_decision'] = 'ingest_not_candidate';
                    $meta['transcription_last_decision_at'] = now()->toIso8601String();
                    $meta['recording_available_effective'] = $recordingAvailableEffective;
                    $call->has_transcription = false;
                    $call->transcription_checked_at = now();
                    $call->pbx_metadata = $meta;
                    $call->save();
                }

                if (! $isVerificationCandidate || ! $recordingAvailableEffective) {
                    continue;
                }

                // Important: "No transcription found" is not an error. Do not throw/retry.
                try {
                    $transcriptionAttempts++;
                    $transcriptionResult = method_exists($client, 'fetchTranscription')
                        ? $client->fetchTranscription(['server' => $serverId, 'uniqueid' => $callUid])
                        : (method_exists($client, 'fetchAction') ? $client->fetchAction('pbxware.transcription.get', ['server' => $serverId, 'uniqueid' => $callUid]) : []);

                    $this->persistRawPayload(
                        endpoint: 'transcription.get',
                        serverId: $serverId,
                        externalId: $callUid,
                        payload: $transcriptionResult,
                        status: 'received'
                    );

                    Log::info('PBX_TRACE transcription.endpoint.response_shape', [
                        'server_id' => $serverId,
                        'pbx_unique_id' => $callUid,
                        'php_type' => gettype($transcriptionResult),
                        'is_array' => is_array($transcriptionResult),
                        'keys' => is_array($transcriptionResult) ? array_slice(array_keys($transcriptionResult), 0, 20) : [],
                    ]);
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
                            // Inline miss is non-terminal; async fallback retries this eligible call.
                            $transcriptionNotFound++;
                            Log::info('PBX_TRACE transcription.not_found', [
                                'server_id' => $serverId,
                                'pbx_unique_id' => $callUid,
                                'recording_available_raw' => $recordingAvailableRaw,
                                'recording_path' => $recordingPath,
                            ]);
                            $meta = is_array($call->pbx_metadata) ? $call->pbx_metadata : [];
                            $meta['transcription_verification_status'] = 'pending';
                            $meta['transcription_last_decision'] = 'ingest_inline_not_ready';
                            $meta['transcription_last_decision_at'] = now()->toIso8601String();
                            $meta['recording_available_effective'] = true;
                            $call->has_transcription = true;
                            $call->transcription_checked_at = now();
                            $call->pbx_metadata = $meta;
                            $call->save();
                        }
                    } else {
                        // Inline miss is non-terminal; async fallback retries this eligible call.
                        $transcriptionNotFound++;
                        Log::info('PBX_TRACE transcription.not_found', [
                            'server_id' => $serverId,
                            'pbx_unique_id' => $callUid,
                            'recording_available_raw' => $recordingAvailableRaw,
                            'recording_path' => $recordingPath,
                            'reason' => 'empty_response',
                        ]);
                        $meta = is_array($call->pbx_metadata) ? $call->pbx_metadata : [];
                        $meta['transcription_verification_status'] = 'pending';
                        $meta['transcription_last_decision'] = 'ingest_inline_empty_response';
                        $meta['transcription_last_decision_at'] = now()->toIso8601String();
                        $meta['recording_available_effective'] = true;
                        $call->has_transcription = true;
                        $call->transcription_checked_at = now();
                        $call->pbx_metadata = $meta;
                        $call->save();
                    }
                }
            }

            $skippedNoRecordingRatio = $answeredCalls > 0
                ? round(($transcriptionSkippedNoRecording / $answeredCalls) * 100, 2)
                : 0.0;

            if ($answeredCalls > 0 && $skippedNoRecordingRatio >= 80.0) {
                Log::warning('PBX_TRACE transcription.high_skip_no_recording_ratio', [
                    'company_id' => $this->companyId,
                    'company_pbx_account_id' => $this->companyPbxAccountId,
                    'server_id' => $serverId,
                    'answered_calls' => $answeredCalls,
                    'transcription_skipped_no_recording' => $transcriptionSkippedNoRecording,
                    'skip_ratio_percent' => $skippedNoRecordingRatio,
                    'message' => 'High skipped-no-recording ratio detected. Verify PBX recording policy and CDR column mapping.',
                ]);
            }

            Log::info('PBXware ingestion summary', [
                'company_id' => $this->companyId,
                'company_pbx_account_id' => $this->companyPbxAccountId,
                'server_id' => $serverId,
                'inline_transcription_fetch' => 'recording_available_only',
                'split_window_retries' => $splitWindowRetries,
                'strict_lossless_discovery' => true,
                'pagination_unresolved_single_day' => $paginationUnresolvedSingleDay,
                'cdr_rows_returned' => $cdrRowsReturned,
                'cdr_rows_skipped_invalid' => $cdrRowsSkippedInvalid,
                'cdr_rows_missing_endpoints' => $cdrRowsMissingEndpoints,
                'answered_calls' => $answeredCalls,
                'calls_created' => $callsCreated,
                'calls_skipped_existing' => $callsSkipped,
                'transcriptions_stored' => $transcriptionsStored,
                'transcription_attempts' => $transcriptionAttempts,
                'transcription_skipped_no_recording' => $transcriptionSkippedNoRecording,
                'transcription_skipped_no_recording_ratio_percent' => $skippedNoRecordingRatio,
                'transcription_not_found' => $transcriptionNotFound,
            ]);

            return [
                'calls_created' => $callsCreated,
                'calls_skipped_existing' => $callsSkipped,
                'split_window_retries' => $splitWindowRetries,
                'strict_lossless_discovery' => true,
                'pagination_unresolved_single_day' => $paginationUnresolvedSingleDay,
                'transcription_attempts' => $transcriptionAttempts,
                'transcriptions_stored' => $transcriptionsStored,
                'transcription_skipped_no_recording' => $transcriptionSkippedNoRecording,
                'transcription_skipped_no_recording_ratio_percent' => $skippedNoRecordingRatio,
                'transcription_not_found' => $transcriptionNotFound,
            ];

        } catch (PbxwareClientException $e) {
            Log::error('PBX client error during calls ingestion', ['company_id' => $this->companyId, 'error' => $e->getMessage()]);
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Unexpected error during calls ingestion', ['company_id' => $this->companyId, 'error' => $e->getMessage()]);
            throw $e;
        }

        return [];
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

    private function buildCdrDateWindows(\Carbon\Carbon $from, \Carbon\Carbon $to): array
    {
        $windows = [];
        $cursor = $from->copy()->startOfDay();
        $end = $to->copy()->endOfDay();

        while ($cursor->lte($end)) {
            $windowEnd = $cursor->copy()->addDays(2)->endOfDay();
            if ($windowEnd->gt($end)) {
                $windowEnd = $end->copy();
            }

            $windows[] = [
                'from' => $cursor->copy(),
                'to' => $windowEnd->copy(),
            ];

            $cursor = $windowEnd->copy()->addSecond()->startOfDay();
        }

        return $windows;
    }

    private function splitCdrWindow(\Carbon\Carbon $from, \Carbon\Carbon $to): array
    {
        $fromDate = $from->copy()->startOfDay();
        $toDate = $to->copy()->endOfDay();

        if ($fromDate->toDateString() === $toDate->toDateString()) {
            return [];
        }

        $days = max(1, $fromDate->diffInDays($toDate));
        $halfDays = max(1, intdiv($days, 2));

        $leftEnd = $fromDate->copy()->addDays($halfDays)->endOfDay();
        if ($leftEnd->gte($toDate)) {
            $leftEnd = $fromDate->copy()->endOfDay();
        }

        $rightStart = $leftEnd->copy()->addSecond()->startOfDay();
        if ($rightStart->gt($toDate)) {
            return [];
        }

        return [
            ['from' => $fromDate, 'to' => $leftEnd],
            ['from' => $rightStart, 'to' => $toDate],
        ];
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

    private function extractFieldValue(array $row, array $keys): mixed
    {
        // Direct key match first (supports numeric indexes and exact names).
        foreach ($keys as $key) {
            if (! array_key_exists($key, $row)) {
                continue;
            }

            return $row[$key];
        }

        // Defensive case-insensitive match for named keys.
        $normalized = [];
        foreach ($row as $k => $v) {
            if (! is_string($k)) {
                continue;
            }

            $normalized[$this->normalizeFieldKey($k)] = $v;
        }

        foreach ($keys as $key) {
            if (! is_string($key)) {
                continue;
            }

            $needle = $this->normalizeFieldKey($key);
            if (array_key_exists($needle, $normalized)) {
                return $normalized[$needle];
            }
        }

        return null;
    }

    private function normalizeFieldKey(string $key): string
    {
        return strtolower((string) preg_replace('/[^a-z0-9]+/i', '', trim($key)));
    }

    private function toBooleanLike(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if ($value === null) {
            return null;
        }

        $normalized = strtolower(trim((string) $value));
        if ($normalized === '') {
            return null;
        }

        $compact = str_replace([' ', '_', '-'], '', $normalized);

        if (in_array($compact, ['1', 'true', 'yes', 'y', 'on', 't', 'enabled', 'available', 'recorded'], true)) {
            return true;
        }

        if (in_array($compact, ['0', 'false', 'no', 'n', 'off', 'f', 'disabled', 'unavailable', 'notrecorded'], true)) {
            return false;
        }

        return null;
    }

    private function persistRawPayload(
        string $endpoint,
        ?string $serverId,
        ?string $externalId,
        mixed $payload,
        string $status = 'received'
    ): void {
        if (! \Illuminate\Support\Facades\Schema::hasTable('pbx_raw_payloads')) {
            return;
        }

        try {
            $encodedPayload = is_string($payload)
                ? $payload
                : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $saved = PbxRawPayload::create([
                'provider' => 'pbxware',
                'endpoint' => $endpoint,
                'server_id' => $serverId,
                'external_id' => $externalId,
                'payload_json' => $encodedPayload ?: '{}',
                'api_version' => 'v7',
                'fetched_at' => now(),
                'processing_status' => $status,
            ]);

            Log::info('PBX_TRACE raw_payload.persisted', [
                'id' => $saved->id,
                'endpoint' => $endpoint,
                'server_id' => $serverId,
                'external_id' => $externalId,
                'payload_bytes' => strlen((string) ($encodedPayload ?: '{}')),
                'status' => $status,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to persist raw PBX payload', [
                'endpoint' => $endpoint,
                'server_id' => $serverId,
                'external_id' => $externalId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
