<?php

namespace Tests\Unit\Services\Admin;

use App\Services\Admin\WeeklyCallReportListPresenter;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class WeeklyCallReportListPresenterTest extends TestCase
{
    public function test_it_preserves_reports_index_payload_contract(): void
    {
        $presenter = new WeeklyCallReportListPresenter();

        $row = [
            'id' => 101,
            'company_name' => 'BlueHub',
            'week_start_date' => Carbon::parse('2026-05-01'),
            'week_end_date' => Carbon::parse('2026-05-07'),
            'total_calls' => 50,
            'answered_calls' => 40,
            'missed_calls' => 10,
        ];

        $mapped = $presenter->toIndexRow($row);

        $expectedKeys = [
            'id',
            'company',
            'company_name',
            'week_start_date',
            'week_end_date',
            'weekStartDate',
            'weekEndDate',
            'total_calls',
            'answered_calls',
            'missed_calls',
            'totalCalls',
            'answeredCalls',
            'missedCalls',
            'answer_rate',
            'answerRate',
        ];

        $this->assertSame($expectedKeys, array_keys($mapped));
        $this->assertSame('BlueHub', $mapped['company']['name']);
        $this->assertSame('2026-05-01', $mapped['week_start_date']);
        $this->assertSame('2026-05-07', $mapped['week_end_date']);
        $this->assertSame(80.0, $mapped['answer_rate']);
        $this->assertSame(80, $mapped['answerRate']);
    }

    public function test_it_handles_zero_total_calls_without_division_error(): void
    {
        $presenter = new WeeklyCallReportListPresenter();

        $mapped = $presenter->toIndexRow([
            'id' => 1,
            'company' => ['name' => 'Demo'],
            'week_start_date' => '2026-05-01',
            'week_end_date' => '2026-05-07',
            'total_calls' => 0,
            'answered_calls' => 0,
            'missed_calls' => 0,
        ]);

        $this->assertSame(0, $mapped['answer_rate']);
        $this->assertSame(0, $mapped['answerRate']);
    }
}
