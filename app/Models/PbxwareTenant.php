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

    public function companyPbxAccount(): HasOne
    {
        return $this->hasOne(CompanyPbxAccount::class, 'server_id', 'server_id');
    }
}
