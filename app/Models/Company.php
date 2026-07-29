<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Company extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'timezone',
        'status',
        'monthly_call_limit',
        'call_limit_used',
        'call_limit_expires_at',
    ];

    protected $casts = [
        'monthly_call_limit'    => 'integer',
        'call_limit_used'       => 'integer',
        'call_limit_expires_at' => 'date',
    ];

    public function companyPbxAccounts(): HasMany
    {
        return $this->hasMany(CompanyPbxAccount::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function minuteBalance(): HasOne
    {
        return $this->hasOne(CompanyMinuteBalance::class);
    }

    public function creditBalance(): HasOne
    {
        return $this->hasOne(CompanyCreditBalance::class);
    }

    public function creditTransactions(): HasMany
    {
        return $this->hasMany(CreditTransaction::class);
    }

    public function weeklyFetches(): HasMany
    {
        return $this->hasMany(CompanyWeeklyFetch::class);
    }

    /**
     * Calls still available in the current period.
     * Null monthly_call_limit means unlimited (returns PHP_INT_MAX).
     */
    public function getCallLimitRemainingAttribute(): int
    {
        if ($this->monthly_call_limit === null) {
            return PHP_INT_MAX;
        }

        return max(0, (int) $this->monthly_call_limit - (int) $this->call_limit_used);
    }

    /**
     * Whether the call-limit period is currently active (unlimited, or not yet expired).
     */
    public function isCallLimitActive(): bool
    {
        if ($this->monthly_call_limit === null) {
            return true;
        }

        if ($this->call_limit_expires_at === null) {
            return true;
        }

        return ! $this->isCallLimitPeriodCompleted();
    }

    /**
     * Whether the current limit period has ended (expiry date has passed).
     */
    public function isCallLimitPeriodCompleted(): bool
    {
        if ($this->call_limit_expires_at === null) {
            return false;
        }

        $today = CarbonImmutable::now($this->timezone ?: 'UTC')->startOfDay();

        return $today->greaterThan(
            CarbonImmutable::parse($this->call_limit_expires_at)->endOfDay()
        );
    }
}
