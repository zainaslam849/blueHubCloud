<?php

namespace App\Http\Controllers\Admin;

use App\Models\TenantSyncSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class AdminTenantSyncController extends Controller
{
    /**
     * Get sync settings for all providers
     */
    public function index(): JsonResponse
    {
        $providers = \App\Models\PbxProvider::all();
        
        $settings = $providers->map(function ($provider) {
            $setting = TenantSyncSetting::where('pbx_provider_id', $provider->id)->first();
            
            if ($setting) {
                return [
                    'id' => $setting->id,
                    'pbx_provider_id' => $setting->pbx_provider_id,
                    'pbx_provider_name' => $provider->name,
                    'enabled' => $setting->enabled,
                    'frequency' => $setting->frequency,
                    'interval_minutes' => $setting->interval_minutes,
                    'scheduled_time' => $this->normalizeScheduledTime($setting->scheduled_time) ?? '02:00',
                    'scheduled_day' => $setting->scheduled_day,
                    'last_synced_at' => $setting->last_synced_at?->toIso8601String(),
                    'last_sync_count' => $setting->last_sync_count,
                    'last_sync_log' => $setting->last_sync_log,
                ];
            } else {
                // Return defaults for providers without settings
                return [
                    'id' => null,
                    'pbx_provider_id' => $provider->id,
                    'pbx_provider_name' => $provider->name,
                    'enabled' => false,
                    'frequency' => 'daily',
                    'interval_minutes' => 5,
                    'scheduled_time' => '02:00',
                    'scheduled_day' => 'monday',
                    'last_synced_at' => null,
                    'last_sync_count' => 0,
                    'last_sync_log' => null,
                ];
            }
        });

        return response()->json($settings);
    }

    /**
     * Get sync settings for a specific provider
     */
    public function show(int $providerId): JsonResponse
    {
        $setting = TenantSyncSetting::where('pbx_provider_id', $providerId)
            ->with('pbxProvider')
            ->first();

        if (!$setting) {
            // Return default settings if not existing
            $pbxProvider = \App\Models\PbxProvider::findOrFail($providerId);
            $setting = [
                'id' => null,
                'pbx_provider_id' => $providerId,
                'pbx_provider_name' => $pbxProvider->name,
                'enabled' => false,
                'frequency' => 'daily',
                'interval_minutes' => 5,
                'scheduled_time' => '02:00',
                'scheduled_day' => 'monday',
                'last_synced_at' => null,
                'last_sync_count' => 0,
                'last_sync_log' => null,
            ];
        } else {
            $setting = [
                'id' => $setting->id,
                'pbx_provider_id' => $setting->pbx_provider_id,
                'pbx_provider_name' => $setting->pbxProvider?->name,
                'enabled' => $setting->enabled,
                'frequency' => $setting->frequency,
                'interval_minutes' => $setting->interval_minutes,
                'scheduled_time' => $this->normalizeScheduledTime($setting->scheduled_time) ?? '02:00',
                'scheduled_day' => $setting->scheduled_day,
                'last_synced_at' => $setting->last_synced_at?->toIso8601String(),
                'last_sync_count' => $setting->last_sync_count,
                'last_sync_log' => $setting->last_sync_log,
            ];
        }

        return response()->json(['data' => $setting]);
    }

    /**
     * Update sync settings for a provider
     */
    public function update(Request $request, int $providerId): JsonResponse
    {
        $request->merge([
            'scheduled_time' => $this->normalizeScheduledTime($request->input('scheduled_time')),
        ]);

        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'frequency' => ['required', 'in:every_minutes,hourly,daily,weekly'],
            'interval_minutes' => ['nullable', 'integer', 'min:1', 'max:59'],
            'scheduled_time' => ['exclude_unless:frequency,daily,weekly', 'required', 'date_format:H:i'],
            'scheduled_day' => ['exclude_unless:frequency,weekly', 'required', 'string', 'in:monday,tuesday,wednesday,thursday,friday,saturday,sunday'],
        ]);

        // Verify provider exists
        $pbxProvider = \App\Models\PbxProvider::findOrFail($providerId);

        // Create or update setting
        $setting = TenantSyncSetting::updateOrCreate(
            ['pbx_provider_id' => $providerId],
            [
                'enabled' => $validated['enabled'],
                'frequency' => $validated['frequency'],
                'interval_minutes' => ($validated['frequency'] === 'every_minutes')
                    ? ($validated['interval_minutes'] ?? 5)
                    : 5,
                'scheduled_time' => $validated['scheduled_time'] ?? '02:00',
                'scheduled_day' => $validated['scheduled_day'] ?? 'monday',
            ]
        );

        return response()->json([
            'data' => [
                'id' => $setting->id,
                'pbx_provider_id' => $setting->pbx_provider_id,
                'pbx_provider_name' => $pbxProvider->name,
                'enabled' => $setting->enabled,
                'frequency' => $setting->frequency,
                'interval_minutes' => $setting->interval_minutes,
                'scheduled_time' => $this->normalizeScheduledTime($setting->scheduled_time) ?? '02:00',
                'scheduled_day' => $setting->scheduled_day,
                'last_synced_at' => $setting->last_synced_at?->toIso8601String(),
                'last_sync_count' => $setting->last_sync_count,
                'last_sync_log' => $setting->last_sync_log,
            ],
            'message' => 'Sync settings updated successfully',
        ]);
    }

    private function normalizeScheduledTime(mixed $time): ?string
    {
        if ($time === null) {
            return null;
        }

        $value = trim((string) $time);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^\d{2}:\d{2}$/', $value) === 1) {
            return $value;
        }

        if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $value) === 1) {
            return substr($value, 0, 5);
        }

        foreach (['g:i A', 'g:i a', 'h:i A', 'h:i a'] as $format) {
            $parsed = \DateTime::createFromFormat($format, $value);
            if ($parsed !== false) {
                return $parsed->format('H:i');
            }
        }

        return $value;
    }

    /**
     * Manually trigger sync for a provider
     */
    public function triggerSync(int $providerId): JsonResponse
    {
        $pbxProvider = \App\Models\PbxProvider::findOrFail($providerId);

        // Dispatch sync command
        $exitCode = \Illuminate\Support\Facades\Artisan::call('pbx:sync-tenants', [
            '--provider-id' => $providerId,
        ]);

        if ($exitCode === 0) {
            return response()->json([
                'message' => 'Tenant sync triggered successfully for ' . $pbxProvider->name,
            ]);
        } else {
            return response()->json([
                'message' => 'Failed to trigger tenant sync',
            ], 500);
        }
    }
}
