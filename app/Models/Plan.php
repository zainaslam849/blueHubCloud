<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    protected $fillable = [
        'name',
        'minute_limit',
        'credits',
        'price',
        'sale_price',
        'is_active',
    ];

    protected $casts = [
        'minute_limit' => 'integer',
        'credits' => 'decimal:4',
        'price' => 'decimal:2',
        'sale_price' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function getEffectivePriceAttribute(): string
    {
        return $this->sale_price !== null ? (string) $this->sale_price : (string) $this->price;
    }

    public function getHasSaleAttribute(): bool
    {
        return $this->sale_price !== null && (float) $this->sale_price < (float) $this->price;
    }

    public function getDiscountPercentAttribute(): int
    {
        if (! $this->has_sale || (float) $this->price === 0.0) {
            return 0;
        }
        return (int) round((1 - (float) $this->sale_price / (float) $this->price) * 100);
    }

    public function companyMinuteBalances(): HasMany
    {
        return $this->hasMany(CompanyMinuteBalance::class);
    }
}
