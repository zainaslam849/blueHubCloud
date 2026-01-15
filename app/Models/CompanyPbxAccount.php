<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CompanyPbxAccount extends Model
{
    protected $fillable = [
        'company_id',
        'pbx_provider_id',
        'pbx_name',
        'api_endpoint',
        'api_key',
        'api_secret',
        'server_id',
        'status',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function pbxProvider(): BelongsTo
    {
        return $this->belongsTo(PbxProvider::class);
    }

    public function calls(): HasMany
    {
        return $this->hasMany(Call::class);
    }
}
