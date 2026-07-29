<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\PipelineRun;
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

        // Weekly pipeline status
        $settings = null;
        $weeklyEnabled = true;
        $weeklySchedule = '';
        try {
            $settings = AppSetting::first();
            $weeklyEnabled = (bool) ($settings?->weekly_run_enabled ?? true);
            $day = max(0, min(6, (int) ($settings?->weekly_run_day ?? 1)));
            $dayNames = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
            $weeklySchedule = ($dayNames[$day] ?? 'Monday').' at '.($settings?->weekly_run_time ?? '02:00').' '.($settings?->weekly_run_timezone ?? 'UTC');
        } catch (\Throwable) {}

        $lastWeeklyRun = null;
        try {
            $lastWeeklyRun = PipelineRun::query()
                ->where('trigger_type', 'scheduled')
                ->latest('id')
                ->first(['status', 'started_at']);
        } catch (\Throwable) {}

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
                'weekly_pipeline' => [
                    'enabled'       => $weeklyEnabled,
                    'schedule'      => $weeklySchedule,
                    'last_run_at'   => $lastWeeklyRun?->started_at?->toIso8601String(),
                    'last_run_status' => $lastWeeklyRun?->status,
                ],
                'pbx_ingest_enabled' => (bool) config('services.pbxware.ingest_enabled', false),
                'reports_ai_enabled' => (bool) config('services.reports.ai_enabled', false),
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
