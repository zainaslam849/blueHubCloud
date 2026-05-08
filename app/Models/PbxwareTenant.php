<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PbxwareTenant extends Model
{
    protected $table = 'pbxware_tenants';

    protected $fillable = [
        'pbx_provider_id',
        'server_id',
        'tenant_code',
        'name',
        'package_name',
        'package_id',
        'ext_length',
        'country_id',
        'country_code',
        'raw_data',
        'synced_at',
    ];

    protected $casts = [
        'synced_at' => 'datetime',
        'raw_data' => 'array',
    ];

    public function pbxProvider(): BelongsTo
    {
        return $this->belongsTo(PbxProvider::class);
    }

    /**
     * Resolve the CompanyPbxAccount that maps this tenant.
     *
     * NOTE: PBX server_id values are NOT globally unique — they only identify
     * a tenant within a single PBX provider. The relation MUST be scoped by
     * pbx_provider_id, otherwise a tenant on Provider A can falsely match an
     * unrelated CompanyPbxAccount on Provider B.
     */
    public function companyPbxAccount(): HasOne
    {
        return $this->hasOne(CompanyPbxAccount::class, 'server_id', 'server_id')
            ->where('company_pbx_accounts.pbx_provider_id', $this->pbx_provider_id);
    }
}
