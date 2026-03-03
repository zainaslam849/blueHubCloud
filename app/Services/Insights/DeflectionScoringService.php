<?php

namespace App\Services\Insights;

use App\Models\Call;

/**
 * PHASE 6: DEFLECTION SCORING ENGINE
 * 
 * Provides advanced deflection scoring with breakdown and reasoning.
 * 
 * Deflection = customer can self-serve instead of talking to agent
 * 
 * Scoring formula:
 * confidence = (intent_weight * 0.4) + (duration_weight * 0.2) + (repetition_weight * 0.4)
 * 
 * Returns:
 * - score: 0-100 confidence that this could be deflected
 * - reasoning: Human-readable explanation
 * - priority: HIGH/MEDIUM/LOW based on frequency
 * - estimated_savings: Minutes of agent time if deflected
 */
class DeflectionScoringService
{
    /**
     * Calculate deflection score for a call.
     *
     * @param Call $call
     * @return array Deflection analysis
     */
    public static function scoreCall(Call $call): array
    {
        if (!$call->call_intent || $call->deflection_confidence === null) {
            return self::defaultScore();
        }

        // Get baseline score from insight analyzer
        $baseScore = (int) $call->deflection_confidence;

        // Calculate scoring components
        $intentWeight = self::getIntentWeight($call->call_intent);
        $durationWeight = self::getDurationWeight($call->duration_seconds);
        $repetitionWeight = $call->repetitive_flag ? 100 : 0;

        // Weighted formula
        $score = (int) (
            ($intentWeight * 0.4) +
            ($durationWeight * 0.2) +
            ($repetitionWeight * 0.4)
        );

        // Clamp to 0-100
        $score = max(0, min(100, $score));

        return [
            'score' => $score,
            'confidence_level' => self::getConfidenceLevel($score),
            'reasoning' => self::generateReasoning($call, $score),
            'priority' => self::getPriority($call, $score),
            'estimated_minutes_saveable' => self::estimateSavings($call),
            'suggested_automation' => $call->suggested_automation,
            'components' => [
                'intent_weight' => $intentWeight,
                'duration_weight' => $durationWeight,
                'repetition_weight' => $repetitionWeight,
            ],
        ];
    }

    /**
     * Get weight (0-100) for intent type.
     *
     * @param string $intent
     * @return int
     */
    private static function getIntentWeight(string $intent): int
    {
        return match ($intent) {
            // Highly automatable (passwords, FAQ, links)
            'password_support' => 95,
            'pricing_inquiry' => 80,
            'feature_inquiry' => 75,

            // Moderately automatable (portals, auto-replies)
            'billing_issue' => 65,
            'booking_modification' => 70,

            // Less automatable (needs judgment)
            'order_issue' => 50,
            'technical_issue' => 35,

            // Not automatable
            'complaint' => 10,

            default => 30,
        };
    }

    /**
     * Get weight (0-100) for call duration.
     * Shorter calls = more likely to be automatable.
     *
     * @param int $durationSeconds
     * @return int
     */
    private static function getDurationWeight(int $durationSeconds): int
    {
        return match (true) {
            $durationSeconds <= 60 => 95,    // Under 1 min
            $durationSeconds <= 180 => 80,   // 1-3 min
            $durationSeconds <= 300 => 60,   // 3-5 min
            $durationSeconds <= 600 => 40,   // 5-10 min
            $durationSeconds <= 1200 => 20,  // 10-20 min
            default => 5,                     // Over 20 min
        };
    }

    /**
     * Convert numeric score to confidence level.
     *
     * @param int $score 0-100
     * @return string
     */
    private static function getConfidenceLevel(int $score): string
    {
        return match (true) {
            $score >= 90 => 'Very High',
            $score >= 70 => 'High',
            $score >= 50 => 'Medium',
            $score >= 30 => 'Low',
            default => 'Very Low',
        };
    }

    /**
     * Determine priority for automation implementation.
     *
     * @param Call $call
     * @param int $score
     * @return string HIGH/MEDIUM/LOW
     */
    private static function getPriority(Call $call, int $score): string
    {
        // High priority: high score + common issue
        if ($score >= 75) {
            return 'HIGH';
        }

        // Medium priority: moderate score or repetitive
        if ($score >= 50 || $call->repetitive_flag) {
            return 'MEDIUM';
        }

        return 'LOW';
    }

    /**
     * Estimate minutes of agent time that could be saved.
     *
     * @param Call $call
     * @return float Minutes saved
     */
    private static function estimateSavings(Call $call): float
    {
        // If deflected, the customer handles it themselves
        // Savings = full call duration (agent would have handled it)
        return round($call->duration_seconds / 60, 1);
    }

    /**
     * Generate human-readable reasoning for the score.
     *
     * @param Call $call
     * @param int $score
     * @return string
     */
    private static function generateReasoning(Call $call, int $score): string
    {
        $parts = [];

        // Intent analysis
        if ($call->call_intent) {
            $parts[] = "Intent: {$call->call_intent}";
        }

        // Duration analysis
        $minutes = round($call->duration_seconds / 60, 1);
        $durationText = $minutes < 1 ? 'under 1 minute' : "{$minutes} minutes";
        if ($call->duration_seconds <= 60) {
            $parts[] = "Quick call ({$durationText}) - good for automation";
        } elseif ($call->duration_seconds <= 300) {
            $parts[] = "Moderate call ({$durationText}) - may be automatable";
        } else {
            $parts[] = "Extended call ({$durationText}) - likely needs human support";
        }

        // Repetition analysis
        if ($call->repetitive_flag) {
            $parts[] = "Repetitive issue - automation could prevent future calls";
        }

        // Final verdict
        if ($score >= 75) {
            $parts[] = "✓ STRONG CANDIDATE for self-service automation";
        } elseif ($score >= 50) {
            $parts[] = "◐ MODERATE CANDIDATE - consider with other factors";
        } else {
            $parts[] = "✗ Likely needs human agent assistance";
        }

        return implode(". ", $parts) . ".";
    }

    /**
     * Default score for calls without sufficient data.
     */
    private static function defaultScore(): array
    {
        return [
            'score' => 0,
            'confidence_level' => 'Unknown',
            'reasoning' => 'Insufficient data for deflection analysis',
            'priority' => 'LOW',
            'estimated_minutes_saveable' => 0,
            'suggested_automation' => null,
            'components' => [
                'intent_weight' => 0,
                'duration_weight' => 0,
                'repetition_weight' => 0,
            ],
        ];
    }
}
