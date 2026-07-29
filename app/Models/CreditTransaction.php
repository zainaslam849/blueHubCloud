<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Append-only credit ledger entry. credits is signed: positive when credits
 * are added (purchase, auto_topup, adjustment, refund), negative for
 * deductions.
 */
class CreditTransaction extends Model
{
    public const TYPE_PURCHASE = 'purchase';
    public const TYPE_AUTO_TOPUP = 'auto_topup';
    public const TYPE_DEDUCTION = 'deduction';
    public const TYPE_ADJUSTMENT = 'adjustment';
    public const TYPE_REFUND = 'refund';

    protected $fillable = [
        'company_id',
        'type',
        'credits',
        'balance_after',
        'reference_type',
        'reference_id',
        'meta',
        'created_by',
    ];

    protected $casts = [
        'credits' => 'decimal:4',
        'balance_after' => 'decimal:4',
        'meta' => 'array',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }
}
