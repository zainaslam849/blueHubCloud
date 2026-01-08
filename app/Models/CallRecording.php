<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CallRecording extends Model
{
    public const STATUS_UPLOADED = 'uploaded';
    public const STATUS_QUEUED = 'queued';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_TRANSCRIBING = 'transcribing';
    public const STATUS_TRANSCRIBED = 'transcribed';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'company_id',
        'pbx_provider_id',
        'call_id',
        'recording_url',
        'codec',
        'recording_duration',
        'storage_provider',
        'status',
        'idempotency_key',
        'storage_path',
        'file_size',
        'error_message',
    ];

    /**
     * Only allow valid transitions:
     * uploaded → queued → processing → completed
     * processing → failed
     */
    private const ALLOWED_TRANSITIONS = [
        self::STATUS_UPLOADED => [self::STATUS_QUEUED],
        self::STATUS_QUEUED => [self::STATUS_PROCESSING],
        self::STATUS_PROCESSING => [self::STATUS_COMPLETED, self::STATUS_FAILED],
        self::STATUS_COMPLETED => [self::STATUS_TRANSCRIBING],
        self::STATUS_TRANSCRIBING => [self::STATUS_TRANSCRIBED, self::STATUS_FAILED],
        self::STATUS_TRANSCRIBED => [],
        self::STATUS_FAILED => [],
    ];

    public function call(): BelongsTo
    {
        return $this->belongsTo(Call::class);
    }

    public function canTransitionTo(string $to): bool
    {
        $from = (string) $this->status;
        $allowed = self::ALLOWED_TRANSITIONS[$from] ?? [];

        return in_array($to, $allowed, true);
    }

    public function transitionTo(string $to): void
    {
        if (! $this->canTransitionTo($to)) {
            throw new DomainException("Invalid CallRecording status transition: {$this->status} → {$to}.");
        }

        $this->status = $to;

        if ($to !== self::STATUS_FAILED) {
            $this->error_message = null;
        }

        $this->save();
    }

    public function markQueued(): void
    {
        $this->transitionTo(self::STATUS_QUEUED);
    }

    public function markProcessing(): void
    {
        $this->transitionTo(self::STATUS_PROCESSING);
    }

    public function markCompleted(): void
    {
        $this->transitionTo(self::STATUS_COMPLETED);
    }

    public function markTranscribing(): void
    {
        $this->transitionTo(self::STATUS_TRANSCRIBING);
    }

    public function markTranscribed(): void
    {
        $this->transitionTo(self::STATUS_TRANSCRIBED);
    }

    public function markFailed(string $message): void
    {
        if (! $this->canTransitionTo(self::STATUS_FAILED)) {
            throw new DomainException("Invalid CallRecording status transition: {$this->status} → failed.");
        }

        $this->status = self::STATUS_FAILED;
        $this->error_message = $message;
        $this->save();
    }
}
