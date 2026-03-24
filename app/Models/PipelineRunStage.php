<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PipelineRunStage extends Model
{
    protected $fillable = [
        'pipeline_run_id',
        'stage_key',
        'status',
        'error_message',
        'metrics',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'metrics' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function pipelineRun(): BelongsTo
    {
        return $this->belongsTo(PipelineRun::class);
    }
}
