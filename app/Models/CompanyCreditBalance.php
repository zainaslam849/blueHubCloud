<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-company credit balance (derived cache of the credit_transactions
 * ledger) plus auto-topup configuration. Always mutate the balance through
 * CreditService so ledger and cache stay consistent.
 */
class CompanyCreditBalance extends Model
{
    protected $fillable = [
        'company_id',
        'balance',
        'auto_topup_enabled',
        'auto_topup_threshold',
        'auto_topup_credits',
        'stripe_customer_id',
        'stripe_payment_method_id',
        'auto_topup_last_failed_at',
        'auto_topup_failure_count',
        'auto_topup_paused_at',
    ];

    protected $casts = [
        'balance' => 'decimal:4',
        'auto_topup_enabled' => 'boolean',
        'auto_topup_threshold' => 'decimal:4',
        'auto_topup_credits' => 'decimal:4',
        'auto_topup_last_failed_at' => 'datetime',
        'auto_topup_failure_count' => 'integer',
        'auto_topup_paused_at' => 'datetime',
    ];

    protected $hidden = ['stripe_customer_id', 'stripe_payment_method_id'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function isAutoTopupActive(): bool
    {
        return $this->auto_topup_enabled
            && $this->auto_topup_paused_at === null
            && $this->stripe_customer_id !== null
            && $this->stripe_payment_method_id !== null
            && $this->auto_topup_threshold !== null
            && $this->auto_topup_credits !== null;
    }
}
