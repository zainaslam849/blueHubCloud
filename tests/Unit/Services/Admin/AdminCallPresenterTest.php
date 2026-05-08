<?php

namespace Tests\Unit\Services\Admin;

use App\Models\Call;
use App\Models\CallCategory;
use App\Models\Company;
use App\Models\CompanyPbxAccount;
use App\Models\PbxProvider;
use App\Models\SubCategory;
use App\Services\Admin\AdminCallPresenter;
use Carbon\Carbon;
use Tests\TestCase;

class AdminCallPresenterTest extends TestCase
{
    public function test_it_preserves_calls_index_payload_contract(): void
    {
        $presenter = new AdminCallPresenter();

        $company = new Company();
        $company->setRawAttributes(['id' => 10, 'name' => 'BlueHub'], true);

        $provider = new PbxProvider();
        $provider->setRawAttributes(['id' => 7, 'name' => 'PBXware'], true);

        $account = new CompanyPbxAccount();
        $account->setRawAttributes(['id' => 14], true);
        $account->setRelation('pbxProvider', $provider);

        $category = new CallCategory();
        $category->setRawAttributes(['id' => 21, 'name' => 'Support'], true);

        $subCategory = new SubCategory();
        $subCategory->setRawAttributes(['id' => 22, 'name' => 'Billing'], true);

        $call = new Call();
        $call->setRawAttributes([
            'id' => 55,
            'pbx_unique_id' => 'CALL-123',
            'from' => '1001',
            'to' => '2002',
            'duration_seconds' => 180,
            'status' => 'answered',
            'has_transcription' => true,
            'transcription_status' => 'saved',
            'transcript_text' => 'Customer called about a billing issue and requested plan clarification.',
            'category_id' => 21,
            'sub_category_label' => null,
            'category_source' => 'ai',
            'category_confidence' => 0.94,
            'ai_summary_status' => 'completed',
            'ai_category_status' => 'completed',
            'ai_summary' => 'Customer asked about billing and plan details.',
        ], true);
        $call->created_at = Carbon::parse('2026-05-08T10:00:00Z');
        $call->started_at = Carbon::parse('2026-05-08T09:59:00Z');

        $call->setRelation('company', $company);
        $call->setRelation('companyPbxAccount', $account);
        $call->setRelation('category', $category);
        $call->setRelation('subCategory', $subCategory);

        $row = $presenter->toIndexRow($call);

        $expectedKeys = [
            'id',
            'callId',
            'callTime',
            'fromNumber',
            'toNumber',
            'company',
            'provider',
            'durationSeconds',
            'status',
            'hasTranscription',
            'transcriptionStatus',
            'transcriptSnippet',
            'createdAt',
            'category',
            'categoryId',
            'subCategory',
            'categorySource',
            'categoryConfidence',
            'aiSummaryStatus',
            'aiCategoryStatus',
            'hasAiSummary',
            'aiRecovery',
        ];

        $this->assertSame($expectedKeys, array_keys($row));
        $this->assertSame('CALL-123', $row['callId']);
        $this->assertSame('BlueHub', $row['company']);
        $this->assertSame('PBXware', $row['provider']);
        $this->assertSame('completed', $row['status']);
        $this->assertTrue($row['hasTranscription']);
        $this->assertTrue($row['hasAiSummary']);
        $this->assertSame('complete', $row['aiRecovery']['action']);
    }

    public function test_it_marks_calls_without_transcript_as_not_regenerable(): void
    {
        $presenter = new AdminCallPresenter();
        $call = new Call();
        $call->setRawAttributes([
            'status' => 'missed',
            'has_transcription' => false,
            'transcript_text' => null,
            'ai_summary' => null,
            'ai_summary_status' => null,
            'ai_category_status' => null,
            'category_id' => null,
        ], true);

        $state = $presenter->buildAiRecoveryState($call);

        $this->assertFalse($state['hasTranscript']);
        $this->assertFalse($state['canRegenerate']);
        $this->assertSame('none', $state['action']);
        $this->assertSame('Transcript is not available for that call.', $state['statusText']);
        $this->assertSame('failed', $presenter->normalizeOperationalStatus('missed'));
    }
}
