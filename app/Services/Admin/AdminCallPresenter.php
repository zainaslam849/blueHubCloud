<?php

namespace App\Services\Admin;

use App\Models\Call;

class AdminCallPresenter
{
    /**
     * Map a Call model to the existing calls-index response contract.
     * Keep field names stable for the dashboard.
     *
     * @return array<string,mixed>
     */
    public function toIndexRow(Call $call): array
    {
        $providerName = $call->companyPbxAccount?->pbxProvider?->name ?? '—';
        $aiRecovery = $this->buildAiRecoveryState($call);

        return [
            'id' => $call->id,
            'callId' => $call->pbx_unique_id,
            'callTime' => optional($call->started_at ?? $call->created_at)->toISOString(),
            'fromNumber' => $call->from,
            'toNumber' => $call->to,
            'direction' => $call->direction,
            'company' => $call->company?->name ?? '—',
            'provider' => $providerName,
            'durationSeconds' => (int) ($call->duration_seconds ?? 0),
            'status' => $this->normalizeOperationalStatus($call->status),
            'hasTranscription' => (bool) ($call->has_transcription ?? false),
            'transcriptionStatus' => $call->transcription_status,
            'transcriptSnippet' => $call->transcript_text ? mb_substr($call->transcript_text, 0, 160) : null,
            'createdAt' => optional($call->created_at)->toISOString(),
            'category' => $call->category?->name,
            'categoryId' => $call->category_id,
            'subCategory' => $call->subCategory?->name ?? $call->sub_category_label,
            'categorySource' => $call->category_source,
            'categoryConfidence' => $call->category_confidence,
            'aiSummaryStatus' => $call->ai_summary_status,
            'aiCategoryStatus' => $call->ai_category_status,
            'hasAiSummary' => $this->hasAiSummary($call),
            'aiRecovery' => $aiRecovery,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function buildAiRecoveryState(Call $call): array
    {
        $hasTranscript = $this->hasUsableTranscript($call);
        $hasSummary = $this->hasAiSummary($call);
        $hasCategory = $call->category_id !== null;
        $summaryStatus = (string) ($call->ai_summary_status ?? '');
        $categoryStatus = (string) ($call->ai_category_status ?? '');

        if (! $hasTranscript) {
            return [
                'hasTranscript' => false,
                'hasSummary' => $hasSummary,
                'hasCategory' => $hasCategory,
                'canRegenerate' => false,
                'action' => 'none',
                'actionLabel' => null,
                'statusText' => 'Transcript is not available for that call.',
            ];
        }

        if (! $hasSummary && in_array($summaryStatus, ['queued', 'running'], true)) {
            return [
                'hasTranscript' => true,
                'hasSummary' => false,
                'hasCategory' => $hasCategory,
                'canRegenerate' => false,
                'action' => 'processing',
                'actionLabel' => null,
                'statusText' => 'AI summary and category generation are already queued for this call.',
            ];
        }

        if (! $hasSummary) {
            return [
                'hasTranscript' => true,
                'hasSummary' => false,
                'hasCategory' => $hasCategory,
                'canRegenerate' => true,
                'action' => 'summary_and_category',
                'actionLabel' => 'Generate summary + category',
                'statusText' => 'Transcript is available. Generate AI summary first, then AI category.',
            ];
        }

        if (! $hasCategory && in_array($categoryStatus, ['queued', 'running'], true)) {
            return [
                'hasTranscript' => true,
                'hasSummary' => true,
                'hasCategory' => false,
                'canRegenerate' => false,
                'action' => 'processing',
                'actionLabel' => null,
                'statusText' => 'AI category generation is already queued for this call.',
            ];
        }

        if (! $hasCategory) {
            return [
                'hasTranscript' => true,
                'hasSummary' => true,
                'hasCategory' => false,
                'canRegenerate' => true,
                'action' => 'category_only',
                'actionLabel' => 'Generate category',
                'statusText' => 'AI summary is available. Generate AI category for this call.',
            ];
        }

        return [
            'hasTranscript' => true,
            'hasSummary' => true,
            'hasCategory' => true,
            'canRegenerate' => false,
            'action' => 'complete',
            'actionLabel' => null,
            'statusText' => 'AI summary and category are already available for this call.',
        ];
    }

    public function normalizeOperationalStatus(?string $statusRaw): string
    {
        $status = strtolower((string) $statusRaw);

        $normalized = in_array($status, ['completed', 'complete', 'success', 'answered'], true)
            ? 'completed'
            : (in_array($status, ['processing', 'queued', 'running', 'in_progress'], true)
                ? 'processing'
                : (in_array($status, ['failed', 'error', 'missed'], true) ? 'failed' : $status));

        if (! in_array($normalized, ['completed', 'processing', 'failed'], true)) {
            return 'processing';
        }

        return $normalized;
    }

    private function hasUsableTranscript(Call $call): bool
    {
        return is_string($call->transcript_text) && trim($call->transcript_text) !== '';
    }

    private function hasAiSummary(Call $call): bool
    {
        return is_string($call->ai_summary) && trim($call->ai_summary) !== '';
    }
}
