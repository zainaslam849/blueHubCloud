<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantSyncSetting extends Model
{
    protected $fillable = [
        'pbx_provider_id',
        'enabled',
        'frequency',
        'scheduled_time',
        'scheduled_day',
        'last_synced_at',
        'last_sync_count',
        'last_sync_log',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'last_synced_at' => 'datetime',
    ];

    /**
     * Get the PBX provider
     */
    public function pbxProvider(): BelongsTo
    {
        return $this->belongsTo(PbxProvider::class);
    }

    /**
     * Check if sync should run now
     */
    public function shouldSyncNow(): bool
    {
        if (!$this->enabled) {
            return false;
        }

        $now = now();
        $lastSync = $this->last_synced_at;

        return match ($this->frequency) {
            'hourly' => !$lastSync || $lastSync->addHour()->isPast(),
            'daily' => !$lastSync || $lastSync->addDay()->isPast(),
            'weekly' => !$lastSync || $lastSync->addWeek()->isPast(),
            default => false,
        };
    }
}
