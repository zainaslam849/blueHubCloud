import adminApi from "./api";

// Shape is intentionally API-friendly so we can swap the mock
// with a real endpoint later (e.g. GET /admin/api/dashboard).
//
// Expected response shape:
// {
//   kpis: { calls, recordings, jobs, users },
//   statuses: { active, processing, failed },
//   queue: { workers, jobs, failures },
//   recentActivity: Array<...>,
//   updatedAt: string
// }

export async function fetchAdminDashboard() {
    // TODO: replace with real endpoint when available
    // const { data } = await adminApi.get("/dashboard");
    // return data;

    // Mock latency to exercise skeleton loaders.
    await new Promise((r) => setTimeout(r, 700));

    return {
        kpis: {
            calls: {
                key: "calls",
                label: "Calls",
                value: 12480,
                period: "Last 7 days",
                status: "active",
            },
            recordings: {
                key: "recordings",
                label: "Recordings",
                value: 3217,
                period: "Stored securely",
                status: "processing",
            },
            jobs: {
                key: "jobs",
                label: "Jobs",
                value: 98,
                period: "Queued today",
                status: "failed",
            },
            users: {
                key: "users",
                label: "Users",
                value: 142,
                period: "Total admins",
                status: "active",
            },
        },

        statuses: {
            active: 4,
            processing: 12,
            failed: 1,
        },

        queue: {
            workers: {
                total: 4,
                busy: 3,
                idle: 1,
            },
            jobs: {
                running: 12,
                queued: 86,
            },
            failures: {
                last15m: 1,
                today: 3,
            },
        },

        recentActivity: [
            {
                id: "job-1842",
                type: "job",
                title: "Import job #1842",
                description: "company: BlueHub • type: calls",
                status: "processing",
                occurredAt: new Date(Date.now() - 2 * 60 * 1000).toISOString(),
            },
            {
                id: "rec-9911",
                type: "recording",
                title: "Recording saved",
                description: "duration: 03:14 • codec: opus",
                status: "active",
                occurredAt: new Date(Date.now() - 8 * 60 * 1000).toISOString(),
            },
            {
                id: "job-1839",
                type: "job",
                title: "Transcription job #1839",
                description: "source: recordings • language: en",
                status: "failed",
                occurredAt: new Date(Date.now() - 12 * 60 * 1000).toISOString(),
            },
        ],

        updatedAt: new Date().toISOString(),
    };
}
