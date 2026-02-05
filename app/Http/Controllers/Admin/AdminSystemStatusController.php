<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Repositories\AiSettingsRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class AdminSystemStatusController extends Controller
{
    public function show(AiSettingsRepository $aiSettingsRepo): JsonResponse
    {
        $schedulerLast = Cache::get('system:scheduler:last_run');
        $queueLast = Cache::get('system:queue:last_heartbeat');

        $schedulerOk = $this->isRecent($schedulerLast, 5);
        $queueOk = $this->isRecent($queueLast, 10);

        $aiSettings = $aiSettingsRepo->getActive();
        $aiReady = (bool) ($aiSettings && $aiSettings->enabled && $aiSettings->api_key && $aiSettings->categorization_model);
        $reportModelReady = (bool) ($aiSettings && $aiSettings->report_model);

        return response()->json([
            'data' => [
                'queue_connection' => config('queue.default'),
                'scheduler' => [
                    'last_run' => $schedulerLast,
                    'ok' => $schedulerOk,
                ],
                'queue_worker' => [
                    'last_heartbeat' => $queueLast,
                    'ok' => $queueOk,
                ],
                'pbx_ingest_enabled' => (bool) env('PBXWARE_INGEST_ENABLED', false),
                'reports_ai_enabled' => (bool) env('REPORTS_AI_ENABLED', false),
                'ai_settings_enabled' => $aiReady,
                'report_model_ready' => $reportModelReady,
            ],
        ]);
    }

    private function isRecent(?string $isoDate, int $maxMinutes): bool
    {
        if (! $isoDate) {
            return false;
        }

        try {
            $last = now()->parse($isoDate);
        } catch (\Throwable $e) {
            return false;
        }

        return $last->diffInMinutes(now()) <= $maxMinutes;
    }
}
