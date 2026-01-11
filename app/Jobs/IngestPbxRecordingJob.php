<?php

namespace App\Jobs;

use App\Models\Call;
use App\Models\CallRecording;
use App\Services\PbxwareClient;
use App\Services\Pbx\PbxClientResolver;
use App\Exceptions\PbxwareClientException;
use Aws\S3\S3Client;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

class IngestPbxRecordingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 30;

    protected int $companyId;
    protected int $callId;
    protected string $pbxRecordingId;

    /**
     * @param int $companyId
     * @param int $callId
     * @param string $pbxRecordingId
     */
    public function __construct(int $companyId, int $callId, string $pbxRecordingId)
    {
        $this->companyId = $companyId;
        $this->callId = $callId;
        $this->pbxRecordingId = $pbxRecordingId;
        $this->onQueue('ingest-pbx');
    }

    public function handle(): void
    {
        Log::info('IngestPbxRecordingJob started', ['company_id' => $this->companyId, 'call_id' => $this->callId, 'pbx_recording_id' => $this->pbxRecordingId]);

        $mock = filter_var(env('PBXWARE_MOCK_MODE', false), FILTER_VALIDATE_BOOLEAN);
        if ($mock) {
            Log::info('🟢 Mock PBX mode active for recording ingestion (per PBXWARE_MOCK_MODE env var)', ['company_id' => $this->companyId, 'pbx_recording_id' => $this->pbxRecordingId]);
        } else {
            Log::info('🔵 Real PBX mode active for recording ingestion (per PBXWARE_MOCK_MODE env var)', ['company_id' => $this->companyId, 'pbx_recording_id' => $this->pbxRecordingId]);
        }

        $call = Call::find($this->callId);
        if (! $call) {
            Log::warning('Call not found for recording ingestion', ['call_id' => $this->callId]);
            return;
        }

        $callUid = $call->call_uid ?? 'call-' . $call->id;
        $s3Key = sprintf('recordings/incoming/%s/%s.mp3', $this->companyId, $callUid);

        // Idempotency: if recording already exists for this idempotency key, skip
        $existing = CallRecording::where('idempotency_key', $this->pbxRecordingId)->where('call_id', $this->callId)->first();
        if ($existing) {
            Log::info('Recording already ingested, skipping', ['call_id' => $this->callId, 'idempotency_key' => $this->pbxRecordingId]);
            return;
        }

            $client = PbxClientResolver::resolve();

        try {

            // Resolve client (mock or real) via resolver
            $s3Region = Config::get('filesystems.disks.s3.region') ?: env('AWS_DEFAULT_REGION');
            $s3Bucket = Config::get('filesystems.disks.s3.bucket') ?: env('AWS_BUCKET');

            $s3 = new S3Client([
                'version' => 'latest',
                'region' => $s3Region,
            ]);

            // If running in mock mode, obtain a local stream resource from the mock client
            if ($mock && method_exists($client, 'downloadRecordingStream')) {
                $streamResource = $client->downloadRecordingStream($this->pbxRecordingId);

                $uploadStart = microtime(true);
                $result = $s3->putObject([
                    'Bucket' => $s3Bucket,
                    'Key' => $s3Key,
                    'Body' => $streamResource,
                    'ContentType' => 'audio/mpeg',
                ]);

                // if resource, ensure it's closed after upload
                if (is_resource($streamResource)) {
                    fclose($streamResource);
                }
            } else {
                // Production flow: client should return a PSR-7 stream for Body
                $psrStream = $client->downloadRecordingStream($this->pbxRecordingId);

                $uploadStart = microtime(true);
                $result = $s3->putObject([
                    'Bucket' => $s3Bucket,
                    'Key' => $s3Key,
                    'Body' => $psrStream,
                    'ContentType' => 'audio/mpeg',
                ]);
            }

            $uploadMs = round((microtime(true) - $uploadStart) * 1000, 2);

            Log::info('S3 upload succeeded', ['company_id' => $this->companyId, 'call_id' => $this->callId, 's3_key' => $s3Key, 'latency_ms' => $uploadMs]);

            // Emoji-friendly log for uploaded recording and S3 path
            Log::info('🎧 Recording uploaded', ['call_id' => $this->callId, 's3_key' => $s3Key, 'object_url' => $result['ObjectURL'] ?? null]);
            Log::info('🚀 Lambda will trigger (S3 event)', ['s3_key' => $s3Key, 'bucket' => $s3Bucket]);

            // Determine pbx_provider_id from the call's PBX account if available
            $pbxProviderId = null;
            try {
                if ($call && $call->company_pbx_account_id) {
                    $acct = \App\Models\CompanyPbxAccount::find($call->company_pbx_account_id);
                    if ($acct) {
                        $pbxProviderId = $acct->pbx_provider_id ?? null;
                    }
                }
            } catch (\Throwable $ignore) {
                // best-effort only
            }

            // Insert call_recordings row
            $recording = CallRecording::create([
                'company_id' => $this->companyId,
                'call_id' => $this->callId,
                'pbx_provider_id' => $pbxProviderId,
                'recording_url' => $result['ObjectURL'] ?? null,
                'storage_provider' => 's3',
                'storage_path' => $s3Key,
                'status' => CallRecording::STATUS_UPLOADED,
                'idempotency_key' => $this->pbxRecordingId,
                'file_size' => $result['ContentLength'] ?? null,
            ]);

            Log::info('CallRecording created', ['call_recording_id' => $recording->id, 'call_id' => $this->callId]);

        } catch (PbxwareClientException $e) {
            Log::error('PBX client failed to download recording', ['pbx_recording_id' => $this->pbxRecordingId, 'error' => $e->getMessage()]);
            // record failed state
            CallRecording::updateOrCreate([
                'call_id' => $this->callId,
                'idempotency_key' => $this->pbxRecordingId,
            ], [
                'company_id' => $this->companyId,
                'storage_provider' => 's3',
                'storage_path' => $s3Key,
                'status' => CallRecording::STATUS_FAILED,
                'error_message' => $e->getMessage(),
            ]);
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Unexpected error ingesting recording', ['pbx_recording_id' => $this->pbxRecordingId, 'error' => $e->getMessage()]);
            CallRecording::updateOrCreate([
                'call_id' => $this->callId,
                'idempotency_key' => $this->pbxRecordingId,
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
