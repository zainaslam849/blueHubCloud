<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Company extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'timezone',
        'status',
    ];

    protected static function booted(): void
    {
        static::creating(function (Company $company) {
            if (! empty($company->slug)) {
                return;
            }

            $base = Str::slug($company->name) ?: 'company';
            $slug = $base;
            $suffix = 2;

            while (static::withTrashed()->where('slug', $slug)->exists()) {
                $slug = $base . '-' . $suffix;
                $suffix++;
            }

            $company->slug = $slug;
        });
    }

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
}
