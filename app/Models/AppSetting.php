<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppSetting extends Model
{
    protected $table = 'app_settings';

    protected $fillable = [
        'site_name',
        'admin_logo_url',
        'admin_favicon_url',
    ];
}
