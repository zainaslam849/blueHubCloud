<?php

namespace Tests\Unit\Services\Admin;

use App\Models\Company;
use App\Models\CompanyPbxAccount;
use App\Models\WeeklyCallReport;
use App\Services\Admin\WeeklyCallReportShowPresenter;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class WeeklyCallReportShowPresenterTest extends TestCase
{
    public function test_it_maps_weekly_report_show_payload_contract(): void
    {
        $report = new WeeklyCallReport([
            'id' => 99,
            'server_id' => 'srv-1',
            'week_start_date' => CarbonImmutable::parse('2026-05-01'),
            'week_end_date' => CarbonImmutable::parse('2026-05-07'),
            'generated_at' => CarbonImmutable::parse('2026-05-08 08:00:00'),
            'status' => 'completed',
            'executive_summary' => 'Summary text',
            'total_calls' => 10,
            'answered_calls' => 8,
            'missed_calls' => 2,
            'calls_with_transcription' => 5,
            'total_call_duration_seconds' => 1230,
            'avg_call_duration_seconds' => 75,
            'first_call_at' => CarbonImmutable::parse('2026-05-01 09:00:00'),
            'last_call_at' => CarbonImmutable::parse('2026-05-07 17:00:00'),
            'pdf_path' => 'reports/a.pdf',
            'csv_path' => null,
            'top_extensions' => [['extension' => '1001', 'calls' => 4]],
            'top_call_topics' => ['Billing'],
        ]);

        $report->id = 99;
        $report->setRelation('company', new Company(['id' => 7, 'name' => 'Acme']));
        $report->setRelation('companyPbxAccount', new CompanyPbxAccount([
            'id' => 44,
            'tenant_code' => 'TENANT-44',
            'server_id' => 'srv-2',
            'pbx_provider_id' => 5,
        ]));

        $metrics = [
            'insights' => ['ai_opportunities' => ['Use IVR'], 'recommendations' => ['Train team']],
            'ai_summary' => ['confidence' => 0.9],
        ];

        $categoryBreakdowns = [
            'counts' => ['Billing' => 3],
            'details' => [],
            'top_dids' => [],
            'hourly_distribution' => [],
            'totals' => ['categorized_calls' => 3, 'report_total_calls' => 10],
        ];

        $callEndpoints = [
            ['call_id' => 1, 'from' => '1001', 'to' => '2001', 'status' => 'ANSWERED', 'started_at' => null, 'duration_seconds' => 90, 'pbx_unique_id' => 'abc'],
        ];

        $advancedViews = ['company_dashboard' => ['summary' => ['total_calls' => 10]]];

        $presenter = new WeeklyCallReportShowPresenter();
        $data = $presenter->toDetailData($report, $metrics, $categoryBreakdowns, $callEndpoints, $advancedViews);

        $this->assertSame(99, $data['header']['id']);
        $this->assertSame('Acme', $data['header']['company']['name']);
        $this->assertSame('TENANT-44', $data['header']['pbx_account']['name']);
        $this->assertSame('May 1–7, 2026', $data['header']['week_range']['formatted']);
        $this->assertSame(80.0, $data['metrics']['answer_rate']);
        $this->assertSame(50.0, $data['metrics']['transcription_rate']);
        $this->assertSame('1 minute', $data['metrics']['avg_call_duration_formatted']);
        $this->assertSame($categoryBreakdowns, $data['category_breakdowns']);
        $this->assertSame($callEndpoints, $data['call_endpoints']);
        $this->assertSame(['confidence' => 0.9], $data['ai_summary']);
        $this->assertTrue($data['exports']['pdf_available']);
        $this->assertFalse($data['exports']['csv_available']);
    }

    public function test_it_applies_default_insights_and_duration_formatting(): void
    {
        $report = new WeeklyCallReport([
            'id' => 100,
            'week_start_date' => CarbonImmutable::parse('2026-05-01'),
            'week_end_date' => CarbonImmutable::parse('2026-05-07'),
            'total_calls' => 0,
            'answered_calls' => 0,
            'calls_with_transcription' => 0,
            'avg_call_duration_seconds' => 3660,
        ]);

        $report->id = 100;

        $presenter = new WeeklyCallReportShowPresenter();
        $data = $presenter->toDetailData($report, [], [], [], []);

        $this->assertSame(0, $data['metrics']['answer_rate']);
        $this->assertSame(0, $data['metrics']['transcription_rate']);
        $this->assertSame('1 hour 1 minute', $data['metrics']['avg_call_duration_formatted']);
        $this->assertSame(['ai_opportunities' => [], 'recommendations' => []], $data['insights']);
        $this->assertNull($data['ai_summary']);
    }
}
