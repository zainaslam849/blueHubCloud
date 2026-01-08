<?php

namespace App\Services;

use App\Jobs\ProcessCallRecording;
use App\Models\CallRecording;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;

class RecordingIngestionService
{
    /**
     * Create a new recording row, or return an existing one when idempotency_key is reused.
     *
     * @param  array{
     *   company_id:int,
     *   pbx_provider_id:int,
     *   call_id:int,
     *   recording_url:string,
     *   recording_duration?:int,
     *   storage_provider?:string,
     *   storage_path?:string|null,
     *   file_size?:int|null,
     *   idempotency_key?:string|null
     * }  $payload
     */
    public function ingest(array $payload): CallRecording
    {
        $idempotencyKey = $payload['idempotency_key'] ?? null;

        if ($idempotencyKey) {
            $existing = CallRecording::query()
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing) {
                $this->assertIdempotencyMatches($existing, $payload);

                return $this->ensureQueuedAndDispatch($existing);
            }
        }

        $recording = new CallRecording();
        $recording->company_id = (int) $payload['company_id'];
        $recording->pbx_provider_id = (int) $payload['pbx_provider_id'];
        $recording->call_id = (int) $payload['call_id'];
        $recording->recording_url = $payload['recording_url'];
        $recording->recording_duration = (int) ($payload['recording_duration'] ?? 0);
        $recording->storage_provider = $payload['storage_provider'] ?? 's3';
        $recording->storage_path = $payload['storage_path'] ?? null;
        $recording->file_size = $payload['file_size'] ?? null;
        $recording->idempotency_key = $idempotencyKey;
        $recording->status = CallRecording::STATUS_UPLOADED;

        try {
            $recording->save();

            return $this->ensureQueuedAndDispatch($recording);
        } catch (QueryException $e) {
            if (! $idempotencyKey) {
                throw $e;
            }

            $existing = CallRecording::query()
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if (! $existing) {
                throw $e;
            }

            $this->assertIdempotencyMatches($existing, $payload);

            return $this->ensureQueuedAndDispatch($existing);
        }
    }

    private function ensureQueuedAndDispatch(CallRecording $recording): CallRecording
    {
        $shouldDispatch = false;

        DB::transaction(function () use ($recording, &$shouldDispatch) {
            $locked = CallRecording::query()
                ->lockForUpdate()
                ->find($recording->id);

            if (! $locked) {
                return;
            }

            if (in_array($locked->status, [
                CallRecording::STATUS_QUEUED,
                CallRecording::STATUS_PROCESSING,
                CallRecording::STATUS_COMPLETED,
                CallRecording::STATUS_FAILED,
            ], true)) {
                return;
            }

            if ($locked->status === CallRecording::STATUS_UPLOADED) {
                $locked->markQueued();
                $shouldDispatch = true;
            }
        });

        if ($shouldDispatch) {
            ProcessCallRecording::dispatch($recording->id)->afterCommit();
        }

        return $recording->fresh() ?? $recording;
    }

    /**
     * @param  array{company_id:int,pbx_provider_id:int,call_id:int}  $payload
     */
    private function assertIdempotencyMatches(CallRecording $existing, array $payload): void
    {
        $matches = (int) $existing->call_id === (int) $payload['call_id']
            && (int) $existing->company_id === (int) $payload['company_id']
            && (int) $existing->pbx_provider_id === (int) $payload['pbx_provider_id'];

        if (! $matches) {
            throw new DomainException('Idempotency key already used for a different recording.');
        }
    }
}
