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
        'interval_minutes',
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
        $scheduledTime = $this->scheduled_time ?? '02:00';
        $scheduledDay = strtolower((string) ($this->scheduled_day ?? 'monday'));
        $intervalMinutes = max(1, (int) ($this->interval_minutes ?? 5));

        $isDueByInterval = static function (int $minutes) use ($lastSync): bool {
            if (!$lastSync) {
                return true;
            }

            return $lastSync->copy()->addMinutes($minutes)->isPast();
        };

        $isDueDaily = static function () use ($now, $lastSync, $scheduledTime): bool {
            $scheduledToday = $now->copy()->setTimeFromTimeString($scheduledTime);

            if ($now->lt($scheduledToday)) {
                return false;
            }

            if (!$lastSync) {
                return true;
            }

            return $lastSync->lt($scheduledToday);
        };

        $isDueWeekly = static function () use ($now, $lastSync, $scheduledTime, $scheduledDay): bool {
            $dayMap = [
                'monday' => 0,
                'tuesday' => 1,
                'wednesday' => 2,
                'thursday' => 3,
                'friday' => 4,
                'saturday' => 5,
                'sunday' => 6,
            ];

            $dayOffset = $dayMap[$scheduledDay] ?? 0;

            $scheduledThisWeek = $now->copy()
                ->startOfWeek()
                ->addDays($dayOffset)
                ->setTimeFromTimeString($scheduledTime);

            if ($now->lt($scheduledThisWeek)) {
                return false;
            }

            if (!$lastSync) {
                return true;
            }

            return $lastSync->lt($scheduledThisWeek);
        };

        return match ($this->frequency) {
            'every_minutes' => $isDueByInterval($intervalMinutes),
            'hourly' => $isDueByInterval(60),
            'daily' => $isDueDaily(),
            'weekly' => $isDueWeekly(),
            default => false,
        };
    }
}
