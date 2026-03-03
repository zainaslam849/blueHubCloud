<?php

namespace App\Jobs;

use App\Models\Call;
use App\Models\CallTranscription;
use App\Services\Insights\CallInsightAnalyzer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * InsightAnalysisJob
 * 
 * Analyzes a call's transcript using CallInsightAnalyzer to extract:
 * - call_intent (WHY did they call)
 * - inferred_department (WHERE should it route)
 * - repetitive_flag (Is this a repeat issue)
 * - estimated_automation_type (WHAT can automate this)
 * - deflection_confidence (HOW confident it could be self-served)
 * 
 * Dispatched by FetchTranscriptionsJob after transcription is fetched.
 */
class InsightAnalysisJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 60;

    private Call $call;
    private CallTranscription $transcription;

    public function __construct(Call $call, CallTranscription $transcription)
    {
        $this->call = $call;
        $this->transcription = $transcription;
    }

    public function handle(): void
    {
        try {
            if (!$this->transcription->transcript_text) {
                Log::warning('InsightAnalysisJob: No transcript text to analyze', [
                    'call_id' => $this->call->id,
                ]);
                return;
            }

            // Run insight analysis
            $insights = CallInsightAnalyzer::analyze(
                $this->transcription->transcript_text,
                $this->call->duration_seconds
            );

            // Update call with insights
            $this->call->update([
                'call_intent' => $insights['call_intent'],
                'inferred_department' => $insights['inferred_department'],
                'repetitive_flag' => $insights['repetitive_flag'],
                'deflection_confidence' => $insights['deflection_confidence'],
                'suggested_automation' => json_encode($insights['estimated_automation_type']),
            ]);

            Log::info('InsightAnalysisJob: Analyzed call', [
                'call_id' => $this->call->id,
                'intent' => $insights['call_intent'],
                'confidence' => $insights['deflection_confidence'],
            ]);
        } catch (\Exception $e) {
            Log::error('InsightAnalysisJob: Analysis failed', [
                'call_id' => $this->call->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
