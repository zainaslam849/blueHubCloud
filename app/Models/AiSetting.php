<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiSetting extends Model
{
    protected $table = 'ai_settings';

    protected $fillable = [
        'provider',
        'api_key',
        'categorization_model',
        'report_model',
        'enabled',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        // store API key encrypted at rest using Laravel's encrypted cast
        'api_key' => 'encrypted',
    ];

    // Never expose the API key in API responses
    protected $hidden = [
        'api_key',
    ];
}
