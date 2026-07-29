<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppSetting extends Model
{
    protected $table = 'app_settings';

    protected $fillable = [
        'site_name',
        'admin_logo_url',
        'admin_logo_light_url',
        'admin_logo_dark_url',
        'admin_favicon_url',
        'smtp_host',
        'smtp_port',
        'smtp_encryption',
        'smtp_username',
        'smtp_password',
        'smtp_from_address',
        'smtp_from_name',
        'admin_notification_email',
        'stripe_public_key',
        'stripe_secret_key',
        'stripe_webhook_secret',
        'stripe_test_mode',
        'weekly_run_enabled',
        'weekly_run_day',
        'weekly_run_time',
        'weekly_run_timezone',
        'credit_price_usd',
        'credits_per_minute',
    ];

    protected $casts = [
        'smtp_port'          => 'integer',
        'stripe_test_mode'   => 'boolean',
        'weekly_run_enabled' => 'boolean',
        'weekly_run_day'     => 'integer',
        'credit_price_usd'   => 'decimal:2',
        'credits_per_minute' => 'decimal:4',
    ];

    protected $hidden = ['smtp_password', 'stripe_secret_key', 'stripe_webhook_secret'];

    public function hasSmtp(): bool
    {
        return filled($this->smtp_host) && filled($this->smtp_username);
    }
}
