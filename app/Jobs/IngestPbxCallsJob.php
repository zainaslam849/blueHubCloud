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

        $client = PbxClientResolver::resolve($pbxAccount->api_endpoint ?? null);

        try {
            Log::info('Starting PBX calls ingestion', ['company_id' => $this->companyId, 'company_pbx_account_id' => $this->companyPbxAccountId]);

            // Log mock mode active for developer clarity
            if (Config::get('pbx.mode') === 'mock') {
                Log::info('🟢 Mock PBX mode active — using local test audio and mock client', ['company_id' => $this->companyId]);
            }

            $calls = $client->fetchCalls(array_merge($this->params, ['company_id' => $this->companyId]), ['company_id' => $this->companyId, 'company_pbx_account_id' => $this->companyPbxAccountId]);

            $processed = 0;
            foreach ($calls as $item) {
                // Map fields conservatively
                $callUid = $item['call_uid'] ?? $item['id'] ?? null;
                if (! $callUid) {
                    Log::warning('Skipping call with missing uid', ['item' => $this->safelog($item)]);
                    continue;
                }

                $attributes = [
                    'company_id' => $this->companyId,
                    'company_pbx_account_id' => $this->companyPbxAccountId,
                    'direction' => $item['direction'] ?? null,
                    'from_number' => $item['from'] ?? $item['from_number'] ?? null,
                    'to_number' => $item['to'] ?? $item['to_number'] ?? null,
                    'started_at' => isset($item['started_at']) ? $this->parseDate($item['started_at']) : null,
                    'ended_at' => isset($item['ended_at']) ? $this->parseDate($item['ended_at']) : null,
                    'duration_seconds' => $item['duration'] ?? $item['duration_seconds'] ?? null,
                    'status' => $item['status'] ?? null,
                ];

                $call = Call::updateOrCreate([
                    'call_uid' => $callUid,
                ], array_merge(['company_id' => $this->companyId, 'company_pbx_account_id' => $this->companyPbxAccountId], array_filter($attributes, function ($v) { return $v !== null; })));

                Log::info('📞 Call ingested', [
                    'company_id' => $this->companyId,
                    'company_pbx_account_id' => $this->companyPbxAccountId,
                    'call_id' => $call->id,
                    'call_uid' => $callUid,
                ]);

                $processed++;

                // Detect recordings
                if (! empty($item['recordings']) && is_array($item['recordings'])) {
                    foreach ($item['recordings'] as $rec) {
                        $recId = is_array($rec) ? ($rec['id'] ?? $rec['recording_id'] ?? null) : $rec;
                        if (! $recId) {
                            continue;
                        }

                        // Idempotency: skip if a recording with this idempotency key already exists
                        $exists = CallRecording::where('idempotency_key', (string)$recId)->where('call_id', $call->id)->exists();
                        if ($exists) {
                            Log::info('Skipping dispatch for already-ingested recording', ['call_id' => $call->id, 'idempotency_key' => $recId]);
                            continue;
                        }

                        IngestPbxRecordingJob::dispatch($this->companyId, $call->id, (string) $recId)
                            ->onQueue('ingest-pbx');
                    }
                } elseif (! empty($item['recording_id'])) {
                    $recId = (string) $item['recording_id'];
                    $exists = CallRecording::where('idempotency_key', $recId)->where('call_id', $call->id)->exists();
                    if (! $exists) {
                        IngestPbxRecordingJob::dispatch($this->companyId, $call->id, $recId)
                            ->onQueue('ingest-pbx');
                    } else {
                        Log::info('Skipping dispatch for already-ingested recording', ['call_id' => $call->id, 'idempotency_key' => $recId]);
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
            return \Carbon\Carbon::parse($value)->toDateTimeString();
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function safelog(array $payload): array
    {
        // Remove potentially sensitive keys
        $s = $payload;
        unset($s['password'], $s['api_key'], $s['api_secret'], $s['secret']);
        return $s;
    }
}
