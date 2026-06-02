<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyMinuteBalance extends Model
{
    protected $fillable = [
        'company_id',
        'plan_id',
        'purchased_minutes',
        'used_minutes',
    ];

    protected $casts = [
        'purchased_minutes' => 'integer',
        'used_minutes' => 'integer',
    ];

    protected $appends = ['available_minutes'];

    public function getAvailableMinutesAttribute(): int
    {
        return max(0, $this->purchased_minutes - $this->used_minutes);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
