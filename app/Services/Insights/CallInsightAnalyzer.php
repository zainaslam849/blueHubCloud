<?php

namespace App\Services\Insights;

use Illuminate\Support\Facades\Log;

/**
 * PHASE 5: INSIGHT ENGINE (CORE CLIENT REQUIREMENT)
 * 
 * Analyzes call transcripts to extract business intelligence:
 * - call_intent: WHY the customer called (pricing_inquiry, support_request, etc.)
 * - inferred_department: WHERE the call should be routed
 * - repetitive_flag: Is this a repeated issue?
 * - estimated_automation_type: WHAT automation could handle this
 * - deflection_confidence: HOW confident we are this could be automated (0-100)
 * 
 * Uses rule-based detection (pattern matching) for MVP.
 * Ready for ML/NLP enhancement later.
 */
class CallInsightAnalyzer
{
    /**
     * Intent types - WHY customers call
     */
    private const INTENT_PATTERNS = [
        'pricing_inquiry' => [
            'keywords' => ['price', 'cost', 'rate', 'quote', 'charge', 'fee', 'plan', 'billing'],
            'partial_matches' => ['how much', 'what does it cost', 'pricing'],
            'weight' => 0.8,
        ],
        'password_support' => [
            'keywords' => ['password', 'reset', 'login', 'access', 'forgot', 'locked out', 'can\'t log in'],
            'partial_matches' => ['reset password', 'locked out', 'can\'t access'],
            'weight' => 0.9,
        ],
        'technical_issue' => [
            'keywords' => ['error', 'broken', 'not working', 'down', 'slow', 'crash', 'bug', 'issue', 'problem'],
            'partial_matches' => ['not working', 'keeps crashing', 'very slow'],
            'weight' => 0.85,
        ],
        'billing_issue' => [
            'keywords' => ['invoice', 'bill', 'charged', 'refund', 'payment', 'overcharge', 'duplicate'],
            'partial_matches' => ['wrong charge', 'charged twice', 'billing error'],
            'weight' => 0.8,
        ],
        'booking_modification' => [
            'keywords' => ['cancel', 'reschedule', 'change', 'modify', 'update', 'reservation', 'appointment'],
            'partial_matches' => ['need to cancel', 'want to reschedule', 'change reservation'],
            'weight' => 0.85,
        ],
        'feature_inquiry' => [
            'keywords' => ['how to', 'how do i', 'how can i', 'tutorial', 'guide', 'instructions', 'feature'],
            'partial_matches' => ['how to use', 'how do i', 'show me how'],
            'weight' => 0.75,
        ],
        'complaint' => [
            'keywords' => ['unhappy', 'terrible', 'terrible service', 'never again', 'disappointed', 'awful', 'rude'],
            'partial_matches' => ['terrible service', 'very unhappy', 'unacceptable service'],
            'weight' => 0.8,
        ],
        'order_issue' => [
            'keywords' => ['order', 'delivery', 'shipped', 'tracking', 'received', 'wrong item', 'damaged'],
            'partial_matches' => ['tracking number', 'when will it arrive', 'never received'],
            'weight' => 0.85,
        ],
    ];

    /**
     * Department routing - WHERE the call should go
     */
    private const DEPARTMENT_PATTERNS = [
        'sales' => [
            'keywords' => ['price', 'upgrade', 'plan', 'feature', 'package', 'quote', 'new customer'],
            'weight' => 0.8,
        ],
        'support' => [
            'keywords' => ['broken', 'error', 'not working', 'help', 'issue', 'problem', 'troubleshoot'],
            'weight' => 0.85,
        ],
        'billing' => [
            'keywords' => ['invoice', 'bill', 'payment', 'refund', 'charge', 'overcharge'],
            'weight' => 0.9,
        ],
        'technical' => [
            'keywords' => ['slow', 'crash', 'connection', 'network', 'server', 'database', 'api'],
            'weight' => 0.85,
        ],
        'customer_success' => [
            'keywords' => ['unhappy', 'complaint', 'terrible', 'disappointed', 'retention', 'account'],
            'weight' => 0.8,
        ],
        'operations' => [
            'keywords' => ['delivery', 'logistics', 'shipped', 'tracking', 'warehouse', 'order'],
            'weight' => 0.8,
        ],
    ];

    /**
     * Deflection scoring factors
     */
    private const DEFLECTION_FACTORS = [
        'amenable_intents' => [
            'password_support' => 100,    // Highly automatable
            'feature_inquiry' => 80,      // Can direct to FAQ
            'pricing_inquiry' => 85,      // Can provide self-service quote
            'billing_issue' => 70,        // Depends on complexity
            'booking_modification' => 75, // Can offer self-service portal
            'order_issue' => 60,          // May need manual verification
            'technical_issue' => 40,      // Often needs human support
            'complaint' => 10,            // Needs human empathy
        ],
        'duration_threshold' => [
            60 => 90,     // Under 1 min call = easily automatable
            300 => 70,    // 1-5 min = medium complexity
            900 => 40,    // 5-15 min = complex, needs escalation
            3600 => 10,   // Over 1 hour = definitely needs human
        ],
        'escalation_keywords' => [
            'manager' => -30,
            'complaint' => -25,
            'angry' => -20,
            'serious' => -15,
            'urgent' => -15,
            'critical' => -20,
            'immediate' => -10,
        ],
    ];

    /**
     * Analyze a transcript and extract business insights.
     *
     * @param string $transcriptText Full transcript text
     * @param int|null $durationSeconds Call duration
     * @return array Insights including intent, department, deflection score
     */
    public static function analyze(string $transcriptText, ?int $durationSeconds = null): array
    {
        if (empty(trim($transcriptText))) {
            return self::defaultInsights();
        }

        $transcript = strtolower($transcriptText);

        $intent = self::extractIntent($transcript);
        $department = self::extractDepartment($transcript);
        $deflectionScore = self::calculateDeflectionScore(
            $intent,
            $durationSeconds,
            $transcript
        );

        return [
            'call_intent' => $intent,
            'inferred_department' => $department,
            'repetitive_flag' => self::isRepetitive($transcript),
            'estimated_automation_type' => self::getAutomationType($intent),
            'deflection_confidence' => $deflectionScore,
            'insights_version' => 'v1_rule_based',
        ];
    }

    /**
     * Extract primary intent from transcript.
     *
     * @param string $transcript Lowercased transcript text
     * @return string Intent category
     */
    private static function extractIntent(string $transcript): string
    {
        $scores = [];

        foreach (self::INTENT_PATTERNS as $intent => $pattern) {
            $score = 0;

            // Keyword matches (whole words)
            foreach ($pattern['keywords'] as $keyword) {
                if (preg_match('/\b' . preg_quote($keyword) . '\b/', $transcript)) {
                    $score += 1;
                }
            }

            // Partial phrase matches
            foreach ($pattern['partial_matches'] as $phrase) {
                if (str_contains($transcript, $phrase)) {
                    $score += 2;
                }
            }

            if ($score > 0) {
                $scores[$intent] = $score * $pattern['weight'];
            }
        }

        if (empty($scores)) {
            return 'general_inquiry';
        }

        // Return highest-scoring intent
        return array_key_first(array_reverse(asort($scores, SORT_NUMERIC) || $scores));
    }

    /**
     * Infer department routing from transcript.
     *
     * @param string $transcript Lowercased transcript
     * @return string Department category
     */
    private static function extractDepartment(string $transcript): string
    {
        $scores = [];

        foreach (self::DEPARTMENT_PATTERNS as $dept => $pattern) {
            $score = 0;

            foreach ($pattern['keywords'] as $keyword) {
                if (preg_match('/\b' . preg_quote($keyword) . '\b/', $transcript)) {
                    $score += 1;
                }
            }

            if ($score > 0) {
                $scores[$dept] = $score * $pattern['weight'];
            }
        }

        if (empty($scores)) {
            return 'general';
        }

        return array_key_first(array_reverse(asort($scores, SORT_NUMERIC) || $scores));
    }

    /**
     * Calculate deflection confidence score (0-100).
     * 
     * Score based on:
     * - Intent automation suitability (password_support=100, complaint=10)
     * - Call duration (short calls more automatable)
     * - Escalation keywords (reduces score)
     *
     * @param string $intent Intent type
     * @param int|null $durationSeconds Call duration
     * @param string $transcript Transcript text
     * @return int Deflection confidence (0-100)
     */
    private static function calculateDeflectionScore(
        string $intent,
        ?int $durationSeconds,
        string $transcript
    ): int {
        $factors = self::DEFLECTION_FACTORS;

        // Start with intent base score
        $intentScore = $factors['amenable_intents'][$intent] ?? 30;

        // Duration modifier
        $durationScore = $intentScore;
        if ($durationSeconds !== null) {
            foreach ($factors['duration_threshold'] as $threshold => $modifier) {
                if ($durationSeconds <= $threshold) {
                    $durationScore = ($intentScore * $modifier) / 100;
                    break;
                }
            }
            // If longer than any threshold, penalize heavily
            if ($durationSeconds > 3600) {
                $durationScore = 5;
            }
        }

        // Escalation keyword penalties
        $escalationPenalty = 0;
        foreach ($factors['escalation_keywords'] as $keyword => $penalty) {
            if (str_contains($transcript, $keyword)) {
                $escalationPenalty += abs($penalty);
            }
        }

        // Calculate final score
        $finalScore = (int) ($durationScore - $escalationPenalty);

        // Clamp to 0-100
        return max(0, min(100, $finalScore));
    }

    /**
     * Detect if this is a repetitive call issue.
     *
     * @param string $transcript Transcript text
     * @return bool
     */
    private static function isRepetitive(string $transcript): bool
    {
        $repetitiveIndicators = [
            'again' => 1,
            'still' => 1,
            'another time' => 2,
            'third time' => 3,
            'keep happening' => 2,
            'happening again' => 2,
            'same issue' => 2,
            'already reported' => 2,
        ];

        foreach ($repetitiveIndicators as $indicator => $weight) {
            if (str_contains($transcript, $indicator)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get automation type based on intent.
     *
     * @param string $intent Intent type
     * @return array Suggested automation strategies
     */
    private static function getAutomationType(string $intent): array
    {
        $automationMap = [
            'password_support' => ['self_service_portal', 'ivr', 'sms_link'],
            'pricing_inquiry' => ['chatbot', 'email_template', 'web_quote'],
            'feature_inquiry' => ['knowledge_base', 'faq', 'video_tutorial'],
            'billing_issue' => ['self_service_portal', 'email_template'],
            'booking_modification' => ['self_service_portal', 'ivr'],
            'order_issue' => ['tracking_link', 'email_notification'],
            'technical_issue' => ['knowledge_base', 'ticket_system'],
            'complaint' => ['escalation_protocol', 'manager_callback'],
        ];

        return $automationMap[$intent] ?? ['ticket_system', 'knowledge_base'];
    }

    /**
     * Default insights for empty/invalid transcripts.
     */
    private static function defaultInsights(): array
    {
        return [
            'call_intent' => 'unknown',
            'inferred_department' => 'general',
            'repetitive_flag' => false,
            'estimated_automation_type' => [],
            'deflection_confidence' => 0,
            'insights_version' => 'v1_rule_based',
        ];
    }
}
