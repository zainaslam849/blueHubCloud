<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8" />
        <title>Weekly Call Report</title>
        <style>
            body {
                font-family: DejaVu Sans, sans-serif;
                font-size: 12px;
            }
            h1,
            h2 {
                margin: 0 0 8px 0;
            }
            table {
                width: 100%;
                border-collapse: collapse;
                margin: 10px 0;
            }
            th,
            td {
                border: 1px solid #ddd;
                padding: 6px;
                text-align: left;
            }
            th {
                background: #f5f5f5;
            }
            .muted {
                color: #666;
            }
        </style>
    </head>
    <body>
        <h1>Weekly Call Report</h1>
        <p class="muted">
            Company ID: {{ $report["company_id"] }}<br />
            Period: {{ $report["reporting_period_start"] }} →
            {{ $report["reporting_period_end"] }}
        </p>

        <h2>Summary</h2>
        <table>
            <tbody>
                <tr>
                    <th>Total Calls</th>
                    <td>{{ $report["total_calls"] }}</td>
                </tr>
                <tr>
                    <th>Total Duration (seconds)</th>
                    <td>{{ $report["total_duration_seconds"] }}</td>
                </tr>
                <tr>
                    <th>Unresolved Calls</th>
                    <td>{{ $report["unresolved_calls_count"] }}</td>
                </tr>
            </tbody>
        </table>

        <h2>Top Extensions</h2>
        <table>
            <thead>
                <tr>
                    <th>Extension</th>
                    <th>Count</th>
                </tr>
            </thead>
            <tbody>
                @forelse(($report['top_extensions'] ?? []) as $row)
                <tr>
                    <td>{{ $row["key"] ?? ($row["extension"] ?? "") }}</td>
                    <td>{{ $row["count"] ?? ($row["calls"] ?? "") }}</td>
                </tr>
                @empty
                <tr>
                    <td colspan="2" class="muted">No data</td>
                </tr>
                @endforelse
            </tbody>
        </table>

        <h2>Top Call Topics</h2>
        <table>
            <thead>
                <tr>
                    <th>Topic</th>
                    <th>Mentions</th>
                </tr>
            </thead>
            <tbody>
                @forelse(($report['top_call_topics'] ?? []) as $row)
                <tr>
                    <td>{{ $row["topic"] ?? "" }}</td>
                    <td>{{ $row["mentions"] ?? "" }}</td>
                </tr>
                @empty
                <tr>
                    <td colspan="2" class="muted">No data</td>
                </tr>
                @endforelse
            </tbody>
        </table>

        <h2>Metadata</h2>
        <pre>{{
            json_encode(
                $report["metadata"] ?? [],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
            )
        }}</pre>
    </body>
</html>
