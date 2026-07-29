<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyWeeklyFetch extends Model
{
    public const STATUS_COMPLETE = 'complete';
    public const STATUS_PARTIAL  = 'partial';  // capped by the credit budget (or legacy monthly limit)
    public const STATUS_PAUSED   = 'paused';   // legacy: limit period expired
    public const STATUS_INSUFFICIENT_CREDITS = 'insufficient_credits'; // zero credits at run time

    protected $fillable = [
        'company_id',
        'company_pbx_account_id',
        'week_start_date',
        'week_end_date',
        'calls_available',
        'calls_fetched',
        'calls_blocked',
        'status',
        'last_attempted_at',
        'completed_at',
    ];

    protected $casts = [
        'week_start_date'   => 'date',
        'week_end_date'     => 'date',
        'calls_available'   => 'integer',
        'calls_fetched'     => 'integer',
        'calls_blocked'     => 'integer',
        'last_attempted_at' => 'datetime',
        'completed_at'      => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
