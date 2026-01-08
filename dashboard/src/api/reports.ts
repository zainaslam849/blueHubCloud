import { http } from "./http";

export type WeeklyReportStatus =
    | "pending"
    | "processing"
    | "generating"
    | "completed"
    | "failed"
    | string;

export type WeeklyReportSummary = {
    id: string;
    week_start?: string;
    week_end?: string;
    week?: string;
    status: WeeklyReportStatus;
    generated_at?: string | null;
};

export type WeeklyReportDetail = WeeklyReportSummary & {
    pdf_url?: string | null;
    csv_url?: string | null;
};

function unwrap<T>(payload: any): T {
    if (payload && typeof payload === "object") {
        if ("data" in payload) return payload.data as T;
        if ("report" in payload) return payload.report as T;
        if ("reports" in payload) return payload.reports as T;
    }
    return payload as T;
}

function normalizeId(value: unknown): string {
    if (typeof value === "string") return value;
    if (typeof value === "number") return String(value);
    return String(value ?? "");
}

function normalizeSummary(raw: any): WeeklyReportSummary {
    return {
        id: normalizeId(raw?.id),
        week_start: raw?.week_start ?? raw?.weekStart ?? undefined,
        week_end: raw?.week_end ?? raw?.weekEnd ?? undefined,
        week: raw?.week ?? undefined,
        status: raw?.status ?? "pending",
        generated_at: raw?.generated_at ?? raw?.generatedAt ?? null,
    };
}

function normalizeDetail(raw: any): WeeklyReportDetail {
    const summary = normalizeSummary(raw);
    return {
        ...summary,
        pdf_url: raw?.pdf_url ?? raw?.pdfUrl ?? null,
        csv_url: raw?.csv_url ?? raw?.csvUrl ?? null,
    };
}

export async function listWeeklyReports(): Promise<WeeklyReportSummary[]> {
    const { data } = await http.get("/api/v1/reports");
    const items = unwrap<any[]>(data) ?? [];
    return Array.isArray(items) ? items.map(normalizeSummary) : [];
}

export async function getWeeklyReport(id: string): Promise<WeeklyReportDetail> {
    const { data } = await http.get(
        `/api/v1/reports/${encodeURIComponent(id)}`
    );
    const item = unwrap<any>(data);
    return normalizeDetail(item);
}
