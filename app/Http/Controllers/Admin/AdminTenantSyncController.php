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
        $settings = TenantSyncSetting::with('pbxProvider')
            ->orderBy('pbx_provider_id')
            ->get()
            ->map(function ($setting) {
                return [
                    'id' => $setting->id,
                    'pbx_provider_id' => $setting->pbx_provider_id,
                    'pbx_provider_name' => $setting->pbxProvider?->name,
                    'enabled' => $setting->enabled,
                    'frequency' => $setting->frequency,
                    'scheduled_time' => $setting->scheduled_time,
                    'scheduled_day' => $setting->scheduled_day,
                    'last_synced_at' => $setting->last_synced_at?->toIso8601String(),
                    'last_sync_count' => $setting->last_sync_count,
                    'last_sync_log' => $setting->last_sync_log,
                ];
            });

        return response()->json(['data' => $settings]);
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
                'scheduled_time' => $setting->scheduled_time,
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
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'frequency' => ['required', 'in:hourly,daily,weekly'],
            'scheduled_time' => ['required', 'date_format:H:i'],
            'scheduled_day' => ['sometimes', 'string', 'in:monday,tuesday,wednesday,thursday,friday,saturday,sunday'],
        ]);

        // Verify provider exists
        $pbxProvider = \App\Models\PbxProvider::findOrFail($providerId);

        // Create or update setting
        $setting = TenantSyncSetting::updateOrCreate(
            ['pbx_provider_id' => $providerId],
            [
                'enabled' => $validated['enabled'],
                'frequency' => $validated['frequency'],
                'scheduled_time' => $validated['scheduled_time'],
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
                'scheduled_time' => $setting->scheduled_time,
                'scheduled_day' => $setting->scheduled_day,
                'last_synced_at' => $setting->last_synced_at?->toIso8601String(),
                'last_sync_count' => $setting->last_sync_count,
                'last_sync_log' => $setting->last_sync_log,
            ],
            'message' => 'Sync settings updated successfully',
        ]);
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
