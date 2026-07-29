<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A PBX server: one API key (stored in AWS Secrets Manager under
 * secret_name), possibly sharing a hostname with other rows but with its
 * own tenant visibility. provider_type keeps the protocol family
 * ('pbxware') expressible separately from the server instance.
 */
class PbxProvider extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'provider_type',
        'secret_name',
        'base_url',
        'is_default',
        'status',
    ];

    protected $casts = [
        'is_default' => 'boolean',
    ];

    public function companyPbxAccounts(): HasMany
    {
        return $this->hasMany(CompanyPbxAccount::class);
    }

    public function pbxwareTenants(): HasMany
    {
        return $this->hasMany(PbxwareTenant::class);
    }

    public function tenantSyncSetting(): HasOne
    {
        return $this->hasOne(TenantSyncSetting::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    /**
     * The legacy/default server. Env credential fallback (PBXWARE_*) only
     * applies to this row.
     */
    public static function defaultServer(): ?self
    {
        return static::query()->where('is_default', true)->first()
            ?? static::query()->where('slug', 'pbxware')->first();
    }
}
