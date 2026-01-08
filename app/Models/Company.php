<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    protected $fillable = [
        'name',
        'timezone',
        'status',
    ];

    public function companyPbxAccounts(): HasMany
    {
        return $this->hasMany(CompanyPbxAccount::class);
    }
}
