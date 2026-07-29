<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlanPurchase extends Model
{
    protected $fillable = [
        'user_id',
        'company_id',
        'plan_id',
        'stripe_session_id',
        'stripe_payment_intent_id',
        'amount_paid',
        'currency',
        'minutes_added',
        'credits_added',
        'plan_name',
        'plan_price',
        'status',
        'purchased_at',
        'stripe_metadata',
    ];

    protected $casts = [
        'amount_paid'      => 'decimal:2',
        'plan_price'       => 'decimal:2',
        'minutes_added'    => 'integer',
        'credits_added'    => 'decimal:4',
        'purchased_at'     => 'datetime',
        'stripe_metadata'  => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }
}
