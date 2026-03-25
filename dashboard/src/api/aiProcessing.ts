import { adminApi } from "./adminApi";

export type AiProcessingStep = "transcript" | "summary" | "categories";

export type AiPendingScope = {
    company_id?: number | null;
    from_date?: string;
    to_date?: string;
    steps?: AiProcessingStep[];
};

export type AiPendingReport = {
    report_id: number;
    pending_count: number;
    company_id: number;
    company_name: string;
    week_start_date?: string | null;
    week_end_date?: string | null;
};

export type AiPendingStats = {
    scope: {
        company_id: number | null;
        from_date?: string;
        to_date?: string;
        steps: AiProcessingStep[];
    };
    summary_pending: number;
    category_pending: number;
    transcript_pending_estimate: number;
    total_pending: number;
    affected_reports: number;
    per_report: AiPendingReport[];
};

export type AiRegeneratePayload = {
    company_id?: number;
    from_date: string;
    to_date: string;
    steps: AiProcessingStep[];
};

function toQueryParams(scope: AiPendingScope): Record<string, string | string[]> {
    const params: Record<string, string | string[]> = {};

    if (typeof scope.company_id === "number") {
        params.company_id = String(scope.company_id);
    }

    if (scope.from_date) {
        params.from_date = scope.from_date;
    }

    if (scope.to_date) {
        params.to_date = scope.to_date;
    }

    if (scope.steps && scope.steps.length > 0) {
        params.steps = scope.steps;
    }

    return params;
}

export async function getAiPendingStats(
    scope: AiPendingScope = {},
): Promise<AiPendingStats> {
    const response = await adminApi.get<{ data: AiPendingStats }>("/ai/pending", {
        params: toQueryParams(scope),
    });

    return response.data.data;
}

export async function triggerAiRegenerate(
    payload: AiRegeneratePayload,
): Promise<{ queued_jobs: number; company_ids: number[] }> {
    const response = await adminApi.post<{
        data: { queued_jobs: number; company_ids: number[] };
    }>("/ai/regenerate", payload);

    return response.data.data;
}
