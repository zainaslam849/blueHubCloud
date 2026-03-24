<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PipelineRun extends Model
{
    protected $fillable = [
        'company_id',
        'range_from',
        'range_to',
        'status',
        'current_stage',
        'resume_count',
        'triggered_by_user_id',
        'resumed_from_run_id',
        'active_key',
        'last_error',
        'metrics',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'range_from' => 'date',
        'range_to' => 'date',
        'resume_count' => 'integer',
        'metrics' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function stages(): HasMany
    {
        return $this->hasMany(PipelineRunStage::class);
    }

    public function markRunning(string $stageKey): void
    {
        $this->forceFill([
            'status' => 'running',
            'current_stage' => $stageKey,
            'started_at' => $this->started_at ?? now(),
            'last_error' => null,
        ])->save();
    }

    public function markFailed(string $stageKey, string $error): void
    {
        $this->forceFill([
            'status' => 'failed',
            'current_stage' => $stageKey,
            'last_error' => $error,
        ])->save();
    }

    public function markQueued(string $stageKey): void
    {
        $this->forceFill([
            'status' => 'queued',
            'current_stage' => $stageKey,
            'last_error' => null,
        ])->save();
    }

    public function markCompleted(string $stageKey): void
    {
        $this->forceFill([
            'status' => 'completed',
            'current_stage' => $stageKey,
            'finished_at' => now(),
            'last_error' => null,
        ])->save();
    }

    public function stageStatus(string $stageKey): ?string
    {
        $stage = $this->stages->firstWhere('stage_key', $stageKey);

        return $stage?->status;
    }

    public function upsertStage(string $stageKey, array $attributes): PipelineRunStage
    {
        $stage = $this->stages()->firstOrCreate([
            'stage_key' => $stageKey,
        ]);

        $stage->fill($attributes);
        $stage->save();

        return $stage;
    }
}
