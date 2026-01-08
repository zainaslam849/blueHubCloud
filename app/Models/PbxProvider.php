<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PbxProvider extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'status',
    ];

    public function companyPbxAccounts(): HasMany
    {
        return $this->hasMany(CompanyPbxAccount::class);
    }
}
