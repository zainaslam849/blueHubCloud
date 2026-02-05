<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="UTF-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1.0" />
        <title>CDR Analysis Report</title>
        <style>
            body {
                font-family: "Arial", sans-serif;
                margin: 0;
                padding: 0;
                background-color: #f8fafc;
                color: #222;
            }
            .container {
                max-width: 960px;
                margin: 20px auto;
                padding: 20px;
                background-color: #fff;
                box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
            }
            .header {
                text-align: center;
                padding-bottom: 20px;
                border-bottom: 2px solid #3995c6;
            }
            .header img {
                max-width: 250px;
                margin-bottom: 10px;
            }
            h1,
            h2,
            h3,
            h4 {
                color: #22234a;
            }
            h1 {
                font-size: 2.5em;
            }
            h2 {
                font-size: 2em;
                border-bottom: 1px solid #eee;
                padding-bottom: 10px;
                margin-top: 40px;
            }
            h3 {
                font-size: 1.5em;
                color: #3995c6;
                margin-top: 30px;
            }
            h4 {
                font-size: 1.2em;
            }
            table {
                width: 100%;
                border-collapse: collapse;
                margin-top: 20px;
            }
            th,
            td {
                padding: 12px;
                border: 1px solid #ddd;
                text-align: left;
            }
            th {
                background-color: #22234a;
                color: #fff;
            }
            tr:nth-child(even) {
                background-color: #f2f2f2;
            }
            .toc ul {
                list-style-type: none;
                padding: 0;
            }
            .toc ul li a {
                text-decoration: none;
                color: #3995c6;
                font-weight: bold;
            }
            .footer {
                text-align: center;
                margin-top: 40px;
                padding-top: 20px;
                border-top: 2px solid #3995c6;
                font-size: 0.9em;
                color: #777;
            }
        </style>
    </head>
    <body>
        @php
            $counts = $report['category_breakdowns']['counts'] ?? [];
            $details = $report['category_breakdowns']['details'] ?? [];
            $topDids = $report['category_breakdowns']['top_dids'] ?? [];
            $hourly = $report['category_breakdowns']['hourly_distribution'] ?? [];
        @endphp
        <div class="container">
            <div class="header">
                <img src="https://bluehubcloud.com.au/wp-content/uploads/2024/05/bluehubcloud-logo-transparent.png" alt="BlueHub Cloud Logo" />
                <h1>
                    Call Detail Record (CDR) Analysis Report
                    @if(!empty($report['pbx_account_name'] ?? null))
                        — {{ $report['pbx_account_name'] }}
                    @endif
                </h1>
                <p>
                    Prepared by BlueHub Cloud |
                    {{ $report['generated_at'] ? \Carbon\Carbon::parse($report['generated_at'])->toDateString() : ($report['reporting_period_end'] ?? '') }}
                </p>
            </div>

            <div class="toc">
                <h2>Table of Contents</h2>
                <ul>
                    <li><a href="#summary">Executive Summary</a></li>
                    <li><a href="#quantitative">Quantitative Analysis</a></li>
                    <li><a href="#breakdown">Key Category Breakdowns</a></li>
                    <li><a href="#insights">Insights & Recommendations</a></li>
                </ul>
            </div>

            <div id="summary">
                <h2>Executive Summary</h2>
                <p>
                    {{ $report['executive_summary'] ?? 'This report provides a detailed analysis of call detail records from the provided dataset. The analysis categorizes calls to identify key trends, peak call times, and common topics of inquiry.' }}
                </p>
            </div>

            <div id="quantitative">
                <h2>Quantitative Analysis</h2>
                <h3>Call Category Counts</h3>
                <table border="1" class="dataframe table">
                    <thead>
                        <tr style="text-align: right;">
                            <th></th>
                            <th>count</th>
                        </tr>
                        <tr>
                            <th>category</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($counts as $categoryKey => $count)
                            @php
                                $categoryName = $categoryKey;
                                if (strpos($categoryKey, '|') !== false) {
                                    $parts = explode('|', $categoryKey, 2);
                                    $categoryName = $parts[1] ?? $categoryKey;
                                }
                            @endphp
                            <tr>
                                <th>{{ $categoryName }}</th>
                                <td>{{ $count }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                <h3>Top 10 Locations (by DID)</h3>
                @if(count($topDids) > 0)
                    <table border="1" class="dataframe table">
                        <thead>
                            <tr style="text-align: right;">
                                <th></th>
                                <th>count</th>
                            </tr>
                            <tr>
                                <th>did</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($topDids as $row)
                                <tr>
                                    <th>{{ $row['did'] ?? '' }}</th>
                                    <td>{{ $row['calls'] ?? '' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @else
                    <p style="font-style: italic; color: #666; margin-top: 1rem">
                        No DID data available for this reporting period.
                    </p>
                @endif

                <h3>Hourly Call Distribution</h3>
                <table border="1" class="dataframe table">
                    <thead>
                        <tr style="text-align: right;">
                            <th></th>
                            <th>count</th>
                        </tr>
                        <tr>
                            <th>date</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($hourly as $hour => $count)
                            @if($count > 0)
                                <tr>
                                    <th>{{ $hour }}</th>
                                    <td>{{ $count }}</td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div id="breakdown">
                <h2>Key Category Breakdowns</h2>
                @foreach($counts as $categoryKey => $count)
                    @php
                        $categoryName = $categoryKey;
                        if (strpos($categoryKey, '|') !== false) {
                            $parts = explode('|', $categoryKey, 2);
                            $categoryName = $parts[1] ?? $categoryKey;
                        }
                        $categoryDetail = $details[$categoryKey] ?? [];
                        $subCategories = $categoryDetail['sub_categories'] ?? [];
                        $sampleCalls = $categoryDetail['sample_calls'] ?? [];
                    @endphp
                    <h3>Category: {{ $categoryName }}</h3>

                    @if(count($subCategories) > 0)
                        <h4>Sub-Category Counts</h4>
                        <table border="1" class="dataframe table">
                            <thead>
                                <tr style="text-align: right;">
                                    <th></th>
                                    <th>count</th>
                                </tr>
                                <tr>
                                    <th>sub_category</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($subCategories as $subKey => $subCount)
                                    @php
                                        $subName = $subKey;
                                        if (strpos($subKey, '|') !== false) {
                                            $parts = explode('|', $subKey, 2);
                                            $subName = $parts[1] ?? $subKey;
                                        }
                                    @endphp
                                    <tr>
                                        <th>{{ $subName }}</th>
                                        <td>{{ $subCount }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif

                    @if(count($sampleCalls) > 0)
                        <h4>Sample Calls</h4>
                        <table border="1" class="dataframe table">
                            <thead>
                                <tr style="text-align: right;">
                                    <th>date</th>
                                    <th>did</th>
                                    <th>src</th>
                                    <th>text</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($sampleCalls as $sample)
                                    <tr>
                                        <td>{{ $sample['date'] ?? '' }}</td>
                                        <td>{{ $sample['did'] ?? 'None' }}</td>
                                        <td>{{ $sample['src'] ?? '—' }}</td>
                                        <td>{{ $sample['transcript'] ?? '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                @endforeach
            </div>

            <div id="insights">
                <h2>Insights & Recommendations</h2>
                @if(!empty($report['ai_summary']['ai_summary'] ?? null))
                    <h4>AI Business Analysis</h4>
                    <p>{{ $report['ai_summary']['ai_summary'] }}</p>

                    @if(!empty($report['ai_summary']['automation_opportunities'] ?? []))
                        <h4>Opportunities for AI Call Deflection</h4>
                        <p>
                            Based on the call volume and the nature of inquiries, several categories present strong
                            opportunities for deflection using a Conversational AI agent.
                        </p>
                        <ul>
                            @foreach($report['ai_summary']['automation_opportunities'] as $opp)
                                @php
                                    $category = preg_match('/^([^:]+)/', $opp, $match) ? $match[1] : $opp;
                                    $desc = preg_match('/:\s*(.+)$/', $opp, $match) ? $match[1] : $opp;
                                @endphp
                                <li><strong>{{ $category }}:</strong> {{ $desc }}</li>
                            @endforeach
                        </ul>
                    @endif

                    @if(!empty($report['ai_summary']['recommendations'] ?? []))
                        <h4>Implementation Steps</h4>
                        <ol>
                            @foreach($report['ai_summary']['recommendations'] as $rec)
                                <li>{{ $rec }}</li>
                            @endforeach
                        </ol>
                    @endif
                @elseif(!empty($report['insights']['recommendations'] ?? []))
                    <h4>Key Recommendations</h4>
                    <ul>
                        @foreach($report['insights']['recommendations'] as $rec)
                            <li><strong>{{ $rec['message'] ?? '' }}</strong></li>
                        @endforeach
                    </ul>
                @endif
            </div>

            <div class="footer">
                <p>&copy; 2025 BlueHub Cloud. All Rights Reserved.</p>
            </div>
        </div>
    </body>
</html>
