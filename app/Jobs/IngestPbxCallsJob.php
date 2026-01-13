<?php

namespace App\Jobs;

use App\Jobs\IngestPbxRecordingJob;
use App\Models\Call;
use App\Models\CompanyPbxAccount;
use App\Models\CallRecording;
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

            $cdr = $client->fetchCdrList($pbxParams);
            $rows = is_array($cdr) ? ($cdr['rows'] ?? []) : [];

            $processed = 0;
            foreach ($rows as $row) {
                $mapped = $this->mapPbxwareCdr($row);
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
                }

                if ($call->wasRecentlyCreated) {
                    Log::info('📞 Call ingested', [
                        'company_id' => $this->companyId,
                        'company_pbx_account_id' => $this->companyPbxAccountId,
                        'call_id' => $call->id,
                        'call_uid' => $callUid,
                    ]);

                    $processed++;
                }

                // Fetch recordings only when PBXware indicates availability and the recording isn't already fetched.
                if (! empty($mapped['recording_available']) && ! empty($mapped['recording_path'])) {
                    $recordingPath = (string) $mapped['recording_path'];
                    $alreadyFetched = CallRecording::where('idempotency_key', $recordingPath)
                        ->where('call_id', $call->id)
                        ->exists();

                    if ($alreadyFetched) {
                        Log::info('Recording already fetched', [
                            'call_id' => $call->id,
                            'call_uid' => $callUid,
                            'idempotency_key' => $recordingPath,
                        ]);
                    } else {
                        IngestPbxRecordingJob::dispatch($this->companyId, $call->id, $recordingPath)
                            ->onQueue('ingest-pbx');
                    }
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

    private function mapPbxwareCdr(array $row): array
    {
        // PBXware CDR CSV mapping using fixed column indexes.
        // PBXware uses Unique ID only.
        $uniqueId = isset($row[7]) ? trim((string) $row[7]) : '';
        if ($uniqueId === '') {
            Log::warning('Skipping PBX call: no unique identifier present', [
                'company_id' => $this->companyId,
                'company_pbx_account_id' => $this->companyPbxAccountId,
            ]);
            Log::debug('PBX call missing Unique ID at column index 7', [
                'row_len' => count($row),
                'available_indexes' => array_keys($row),
                'company_id' => $this->companyId,
                'company_pbx_account_id' => $this->companyPbxAccountId,
            ]);
            return ['call_uid' => null];
        }

        $startedAt = isset($row[2]) ? $this->parseDate($row[2]) : null;
        $recordingPath = isset($row[8]) ? (string) $row[8] : '';
        $recordingAvailable = isset($row[9]) && ((string) $row[9] === 'True');

        return [
            'call_uid' => $uniqueId,
            'started_at' => $startedAt,
            'recording_path' => $recordingPath,
            'recording_available' => $recordingAvailable,
        ];
    }
}
